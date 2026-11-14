<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\ClinicalRecord;
use App\Models\Encounter;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrescriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private User $other;
    private Encounter $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'encounters.view_any',
            'prescriptions.view', 'prescriptions.write', 'prescriptions.sign', 'prescriptions.amend', 'prescriptions.void', 'prescriptions.review']);
        $this->author = $this->createStaffWithRole('doctor');
        $this->other = $this->createStaffWithRole('doctor');
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHour()]);
        $this->actingAs($this->author);
    }

    public function test_create_edit_sign_refresh_and_review_preserve_ordered_medication_snapshots(): void
    {
        $id = $this->create()->assertCreated()->assertJsonPath('data.kind', 'prescription')->assertJsonPath('data.is_active_order', false)
            ->assertJsonPath('data.payload.items.0.quantity', '12.125')->json('data.id');
        $items = [$this->item('Replacement medication sentinel'), $this->item('Second medication sentinel')];
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 1, 'payload' => ['items' => $items]])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonCount(2, 'data.payload.items');
        $this->sign($id, 2)->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.is_active_order', true)
            ->assertJsonPath('data.authored_by', $this->author->id)->assertJsonPath('data.finalized_by', $this->author->id);
        $this->getJson($this->path($id))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.payload.items.0.medication_name', 'Replacement medication sentinel')->assertJsonMissingPath('data.author.email');
        $this->getJson($this->path($id).'/history')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.snapshot.payload.items.0.medication_name', 'Original medication sentinel')
            ->assertJsonPath('data.2.snapshot.payload.items.1.medication_name', 'Second medication sentinel');
        $this->assertDatabaseHas('prescription_items', ['prescription_id' => $id, 'line_number' => 2, 'medication_name' => 'Second medication sentinel']);
        $this->assertDatabaseMissing('prescription_items', ['prescription_id' => $id, 'medication_name' => 'Original medication sentinel']);
        $this->assertDatabaseCount('clinical_records', 1);
        $this->assertDatabaseCount('clinical_revisions', 3);
        $this->assertDatabaseHas('idempotency_requests', ['resource_type' => 'prescription', 'resource_id' => $id, 'resource_version' => 3]);
        $this->assertAuditExcludes(['Original medication sentinel', 'Replacement medication sentinel', 'Second medication sentinel', 'Private prescription instructions']);
        $this->assertStringNotContainsString('medication sentinel', DB::table('idempotency_requests')->get()->toJson());
    }

    #[DataProvider('terminalActions')]
    public function test_stopping_and_completing_are_signed_replacements_with_no_reactivation(string $action, string $status): void
    {
        $id = $this->create()->assertCreated()->json('data.id');
        $this->sign($id)->assertOk();
        $this->as($this->other);
        $data = $this->finalization(2) + ['reason' => 'Private status change sentinel'];
        $key = ['Idempotency-Key' => 'prescription-terminal-'.$action.'-001'];
        $new = $this->postCommand($this->path($id).'/'.$action, $data, $key)->assertOk()
            ->assertJsonPath('data.amends_id', $id)->assertJsonPath('data.status', 'signed')->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.payload.order_status', $status)->assertJsonPath('data.is_active_order', false)
            ->assertJsonPath('data.authored_by', $this->other->id)->json('data.id');
        $this->postCommand($this->path($id).'/'.$action, $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $new);
        $this->getJson($this->path($id))->assertOk()->assertJsonPath('data.is_active_order', false)->assertJsonPath('data.superseded', true)
            ->assertJsonPath('data.payload.order_status', 'active')->assertJsonPath('data.authored_by', $this->author->id);
        $this->getJson($this->path().'?order_status='.$status)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $new);
        $this->postCommand($this->path($new).'/stop', $data)->assertConflict()->assertJsonPath('code', 'prescription_status_finalized');
        $this->postCommand($this->path($new).'/amend', $data + ['payload' => ['order_status' => 'active']])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.order_status');
        $this->getJson($this->path($new).'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.operation', 'amended')
            ->assertJsonPath('data.1.snapshot.payload.order_status', $status);
        $this->assertDatabaseCount('clinical_records', 2);
        $this->assertDatabaseCount('prescription_items', 2);
        $this->assertAuditExcludes(['Private status change sentinel', 'Original medication sentinel']);
    }

    public static function terminalActions(): array
    {
        return [['stop', 'stopped'], ['complete', 'completed']];
    }

    public function test_signed_corrections_and_retractions_retain_prior_lines_and_cannot_branch(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');
        $this->sign($id)->assertOk();
        $this->as($this->other);
        $data = $this->finalization(2) + ['reason' => 'Private correction reason', 'payload' => ['items' => [$this->item('Corrected medication sentinel')]]];
        $new = $this->postCommand($this->path($id).'/amend', $data)->assertCreated()->assertJsonPath('data.is_active_order', true)->json('data.id');
        $this->assertDatabaseHas('prescription_items', ['prescription_id' => $id, 'medication_name' => 'Original medication sentinel']);
        $this->postCommand($this->path($id).'/complete', $this->finalization(2) + ['reason' => 'Late competing change'])
            ->assertConflict()->assertJsonPath('code', 'clinical_not_current');
        $voided = $this->postCommand($this->path($new).'/void', $this->finalization(2) + ['reason' => 'Private retraction reason'])
            ->assertOk()->assertJsonPath('data.status', 'voided')->assertJsonPath('data.payload', [])
            ->assertJsonPath('data.is_active_order', false)->json('data.id');
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $voided);
        $this->assertDatabaseCount('clinical_records', 3);
        $this->assertDatabaseCount('prescription_items', 2);
        $this->assertAuditExcludes(['Private correction reason', 'Private retraction reason', 'Corrected medication sentinel']);
    }

    public function test_empty_or_incomplete_drafts_cannot_be_signed_and_can_be_voided_by_their_author(): void
    {
        $id = $this->create(['items' => []])->assertCreated()->json('data.id');
        $this->sign($id)->assertUnprocessable()->assertJsonValidationErrors('payload.items');
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 1, 'payload' => ['items' => [['medication_name' => 'Incomplete medication']]]])->assertOk();
        $this->sign($id, 2)->assertUnprocessable()->assertJsonValidationErrors(['payload.items.0.dose', 'payload.items.0.route', 'payload.items.0.frequency']);
        $this->postCommand($this->path($id).'/void', $this->finalization(2) + ['reason' => 'Incomplete order discarded'])->assertOk()->assertJsonPath('data.version', 3);
        $this->sign($id, 3)->assertConflict();
    }

    public function test_other_authors_drafts_and_cross_encounter_targets_are_hidden_and_clinical_permissions_do_not_grant_prescribing(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');
        $this->as($this->other);
        $this->getJson($this->path())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($id))->assertNotFound();
        $this->getJson($this->path($id).'/history')->assertNotFound();
        $this->sign($id)->assertNotFound();
        $this->as($this->author);
        $this->sign($id)->assertOk();
        $otherVisit = Encounter::factory()->for($this->visit->patient)->create();
        $this->getJson('/api/v1/encounters/'.$otherVisit->id.'/prescriptions/'.$id)->assertNotFound();
        $entry = ClinicalEntry::factory()->create(['clinical_record_id' => ClinicalRecord::factory()->forEncounter($this->visit)->create()->id]);
        $this->getJson($this->path($entry->getKey()))->assertNotFound();
        $this->getJson('/api/v1/encounters/'.$this->visit->id.'/clinical/'.$id)->assertForbidden();
        $this->grantRolePermissions('doctor', ['clinical.view', 'clinical.write', 'clinical.sign']);
        $this->getJson('/api/v1/encounters/'.$this->visit->id.'/clinical/'.$id)->assertNotFound();
        Role::where('code', 'doctor')->sole()->permissions()->detach(Permission::where('code', 'prescriptions.view')->sole()->id);
        $this->getJson($this->path($id))->assertForbidden();
    }

    public function test_separate_permissions_reauthentication_versions_and_current_scope_apply_before_replays(): void
    {
        $key = ['Idempotency-Key' => 'prescription-create-replay-001'];
        $data = ['encounter_version' => 1, 'payload' => $this->payload()];
        $id = $this->postCommand($this->path(), $data, $key)->assertCreated()->json('data.id');
        $this->postCommand($this->path(), $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->sign($id, 99)->assertConflict()->assertJsonPath('code', 'version_conflict');
        $this->sign($id, 1, ['current_password' => 'incorrect'])->assertUnprocessable();
        $role = Role::where('code', 'doctor')->sole();
        $role->permissions()->detach(Permission::where('code', 'prescriptions.sign')->sole()->id);
        $this->sign($id)->assertForbidden();
        $this->grantRolePermissions('doctor', ['prescriptions.sign']);
        $signKey = ['Idempotency-Key' => 'prescription-sign-replay-001'];
        $this->postCommand($this->path($id).'/sign', $this->finalization(1), $signKey)->assertOk();
        $this->postCommand($this->path($id).'/sign', $this->finalization(1, ['current_password' => 'incorrect']), $signKey)->assertUnprocessable();
        $this->postCommand($this->path(), $data, $key)->assertConflict()->assertJsonPath('code', 'idempotency_outdated');
        $role->permissions()->detach(Permission::where('code', 'prescriptions.amend')->sole()->id);
        $this->postCommand($this->path($id).'/stop', $this->finalization(2) + ['reason' => 'Requires amendment authority'])->assertForbidden();
        $role->permissions()->detach(Permission::where('code', 'prescriptions.review')->sole()->id);
        $this->getJson($this->path($id).'/history')->assertForbidden();
        $this->visit->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->getJson($this->path($id))->assertNotFound();
        $this->postCommand($this->path($id).'/sign', $this->finalization(1), $signKey)->assertNotFound();
    }

    #[DataProvider('writeActions')]
    public function test_closed_visits_remain_read_only(string $action): void
    {
        $id = $this->create()->assertCreated()->json('data.id');
        if (in_array($action, ['amend', 'void', 'stop', 'complete'], true)) {
            $this->sign($id)->assertOk();
        }
        $this->visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $this->visit->forceFill(['status' => 'closed', 'version' => 3, 'closed_at' => now('UTC')])->save();
        $data = $this->finalization(in_array($action, ['amend', 'void', 'stop', 'complete'], true) ? 2 : 1, ['encounter_version' => 3]);
        if (in_array($action, ['store', 'update', 'amend'], true)) {
            $data['payload'] = $this->payload();
        }
        if (in_array($action, ['amend', 'void', 'stop', 'complete'], true)) {
            $data['reason'] = 'Closed visit must remain read-only';
        }
        if ($action === 'store') { unset($data['version'], $data['current_password']); }
        if ($action === 'update') { unset($data['current_password']); }
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $action === 'update'
            ? $this->patchCommand($this->path($id), $data) : $this->postCommand($this->path($action === 'store' ? null : $id).($action === 'store' ? '' : '/'.$action), $data));
        $response->assertConflict()->assertJsonPath('code', 'clinical_encounter_closed');
        $this->getJson($this->path($id))->assertOk();
    }

    public static function writeActions(): array
    {
        return array_map(fn (string $action): array => [$action], ['store', 'update', 'sign', 'amend', 'void', 'stop', 'complete']);
    }

    #[DataProvider('invalidLines')]
    public function test_invalid_or_injected_medication_details_cannot_leave_partial_orders(array $changes, string $field): void
    {
        $item = array_replace($this->item(), $changes);
        $this->assertTablesUnchanged($this->tables(), fn () => $this->create(['items' => [$item]]))
            ->assertUnprocessable()->assertJsonValidationErrors('payload.items.0.'.$field);
    }

    public static function invalidLines(): array
    {
        return [[['medication_name' => ''], 'medication_name'], [['medication_name' => str_repeat('x', 256)], 'medication_name'],
            [['prescription_id' => 1], 'prescription_id'], [['id' => 1], 'id'], [['line_number' => 5], 'line_number'],
            [['quantity' => '0'], 'quantity'], [['quantity' => '-1'], 'quantity'], [['quantity' => '1.0001'], 'quantity'],
            [['quantity' => '1e3'], 'quantity'], [['quantity' => '1000000000'], 'quantity'], [['quantity_unit' => null], 'quantity'],
            [['duration_unit' => null], 'duration_value'], [['duration_value' => '10000000'], 'duration_value'],
            [['duration_value' => '-1'], 'duration_value'], [['dose' => ['unexpected']], 'dose']];
    }

    #[DataProvider('failureBoundaries')]
    public function test_failed_creation_rolls_back_every_line_header_revision_audit_and_receipt(string $table, string $operation): void
    {
        Log::spy();
        $hit = $this->failNextWriteTo($table, $operation);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->create());
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertNoSuccessfulAudit('prescription.created');
        $this->assertAuditExcludes(['Original medication sentinel', 'Private prescription instructions']);
    }

    public static function failureBoundaries(): array
    {
        return [['clinical_records', 'insert'], ['prescriptions', 'insert'], ['prescription_items', 'insert'],
            ['clinical_revisions', 'insert'], ['audit_events', 'insert'], ['idempotency_requests', 'update']];
    }

    public function test_a_failed_draft_line_replacement_restores_the_original_lines_and_version(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');
        $hit = $this->failNextWriteTo('prescription_items', 'insert');
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->patchCommand($this->path($id),
            ['encounter_version' => 1, 'version' => 1, 'payload' => ['items' => [$this->item('Failed replacement sentinel')]]]));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->getJson($this->path($id))->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.payload.items.0.medication_name', 'Original medication sentinel');
    }

    public function test_permissions_have_no_default_grants_and_unknown_fields_and_limits_fail_closed(): void
    {
        $this->getJson('/api/v1/prescriptions/options')->assertOk()->assertJsonPath('data.medication_source', 'staff_entered_snapshot');
        $this->create(['order_status' => 'completed'])->assertUnprocessable()->assertJsonValidationErrors('payload.order_status');
        $this->create(['items' => array_fill(0, 101, $this->item())])->assertUnprocessable()->assertJsonValidationErrors('payload.items');
        $this->postCommand($this->path(), ['encounter_version' => 1, 'kind' => 'prescription', 'payload' => $this->payload()])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->getJson($this->path().'?status=invalid&order_status=invalid&page=0&per_page=101')->assertUnprocessable();
        $this->as($this->createStaffWithRole('admin'));
        $this->getJson('/api/v1/prescriptions/options')->assertForbidden();
        $this->create()->assertForbidden();
        $this->assertDatabaseCount('permissions', 41);
    }

    public function test_unicode_text_respects_both_character_and_storage_byte_limits(): void
    {
        $this->create(['instructions' => str_repeat("\u{1F48A}", 16000)])->assertCreated();
        $this->assertTablesUnchanged($this->tables(), fn () => $this->create(['instructions' => str_repeat("\u{1F48A}", 20000)]))
            ->assertUnprocessable()->assertJsonValidationErrors('payload.instructions');
    }

    private function path(?int $id = null): string
    {
        return '/api/v1/encounters/'.$this->visit->id.'/prescriptions'.($id === null ? '' : '/'.$id);
    }

    private function item(string $name = 'Original medication sentinel'): array
    {
        return ['medication_name' => $name, 'form' => 'Synthetic form', 'strength' => 'Staff-entered strength',
            'dose' => 'Staff-entered dose', 'route' => 'Staff-entered route', 'frequency' => 'Staff-entered frequency',
            'duration_value' => '3.125', 'duration_unit' => 'days', 'quantity' => '12.125', 'quantity_unit' => 'units', 'instructions' => 'Private item instructions'];
    }

    private function payload(): array
    {
        return ['instructions' => 'Private prescription instructions', 'items' => [$this->item()]];
    }

    private function create(array $payload = []): TestResponse
    {
        return $this->postCommand($this->path(), ['encounter_version' => 1, 'payload' => array_replace($this->payload(), $payload)]);
    }

    private function finalization(int $version, array $overrides = []): array
    {
        return array_replace(['encounter_version' => 1, 'version' => $version, 'current_password' => self::STAFF_TEST_PASSWORD], $overrides);
    }

    private function sign(int $id, int $version = 1, array $overrides = []): TestResponse
    {
        return $this->postCommand($this->path($id).'/sign', $this->finalization($version, $overrides));
    }

    private function as(User $actor): void
    {
        $this->flushSession();
        $this->actingAs($actor);
    }

    private function tables(): array
    {
        return ['clinical_records', 'prescriptions', 'prescription_items', 'clinical_revisions', 'idempotency_requests'];
    }
}
