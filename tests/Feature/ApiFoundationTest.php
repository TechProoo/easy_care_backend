<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_checks_readiness_without_exposing_configuration(): void
    {
        $this->get('/api/v1/health')->assertOk()->assertExactJson([
            'data' => ['application' => 'EasyCare API', 'api_version' => 'v1', 'status' => 'ready'],
        ]);
    }

    public function test_failed_database_readiness_is_a_generic_503(): void
    {
        DB::partialMock()->shouldReceive('select')->once()->andThrow(new RuntimeException('Private database details'));
        $this->get('/api/v1/health')->assertStatus(503)->assertExactJson([
            'message' => 'The service is temporarily unavailable.', 'code' => 'service_unavailable',
        ]);
    }

    public function test_reference_data_requires_login_without_a_json_accept_header(): void
    {
        $this->get('/api/v1/departments', ['Accept' => 'text/html'])
            ->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_reference_data_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/departments')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_inactive_staff_cannot_read_reference_data(): void
    {
        $user = $this->authorizedStaff();
        $user->is_active = false;
        $user->save();
        $this->actingAs($user)->getJson('/api/v1/departments')->assertUnauthorized();
    }

    public function test_departments_have_paginated_filtered_sorted_resources(): void
    {
        Department::factory()->create(['code' => 'NURSE', 'name' => 'Nursing']);
        Department::factory()->create(['code' => 'MED', 'name' => 'Medical']);
        Department::factory()->create(['code' => 'LAB', 'name' => 'Laboratory']);
        $response = $this->actingAs($this->authorizedStaff())
            ->getJson('/api/v1/departments?q=ing&sort=-name&per_page=1&page=1');
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'NURSE')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'created_at', 'updated_at']], 'meta', 'links']);
        $this->assertMatchesRegularExpression('/Z$/', $response->json('data.0.created_at'));
        $this->getJson('/api/v1/departments?sort=name&per_page=2&page=2')
            ->assertOk()->assertJsonPath('data.0.code', 'NURSE')->assertJsonPath('meta.total', 3);
    }

    public function test_search_uses_literal_wildcard_characters(): void
    {
        Department::factory()->create(['name' => '100% Care']);
        Department::factory()->create(['name' => 'Other Care']);
        $this->actingAs($this->authorizedStaff())->getJson('/api/v1/departments?q=%25')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '100% Care');
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_queries_have_field_validation_errors(string $query, string $field): void
    {
        $this->actingAs($this->authorizedStaff())->getJson('/api/v1/departments?'.$query)
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($field);
    }

    public static function invalidQueries(): array
    {
        return [
            ['per_page=101', 'per_page'], ['per_page=0', 'per_page'], ['per_page=all', 'per_page'],
            ['page=0', 'page'], ['sort=password', 'sort'], ['q[]=not-a-string', 'q'],
        ];
    }

    public function test_empty_collections_use_default_pagination(): void
    {
        $this->actingAs($this->authorizedStaff())->getJson('/api/v1/departments')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.per_page', 25);
    }

    public function test_unknown_api_routes_return_json_404(): void
    {
        $this->get('/api/v1/not-a-route', ['Accept' => 'text/html'])
            ->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_unsupported_methods_preserve_the_allow_header(): void
    {
        $this->postJson('/api/v1/health')->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed')->assertHeader('Allow', 'GET, HEAD');
    }

    public function test_errors_hide_exception_details_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/test-failure', function (): never {
            throw new RuntimeException('Private server details');
        });
        $this->getJson('/api/v1/test-failure')->assertStatus(500)->assertExactJson([
            'message' => 'An unexpected error occurred.', 'code' => 'server_error',
        ]);
    }

    public function test_rate_limits_preserve_retry_after(): void
    {
        RateLimiter::for('api', fn (): Limit => Limit::perMinute(1)->by('foundation-rate-limit-test'));
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/health')->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_allowed_frontend_origin_has_credentialed_cors(): void
    {
        $this->getJson('/api/v1/health', ['Origin' => 'http://localhost:5173'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_cors_preflight_does_not_require_login(): void
    {
        $this->options('/api/v1/departments', [], [
            'Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Content-Type',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_unlisted_origins_do_not_receive_cors_access(): void
    {
        $this->getJson('/api/v1/health', ['Origin' => 'https://unlisted.example'])
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    private function authorizedStaff(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create(['code' => 'reference-data.view']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        return $user;
    }
}
