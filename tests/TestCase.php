<?php

namespace Tests;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    protected const STAFF_TEST_PASSWORD = 'Correct-secret-123!';

    /** @param array<string, mixed> $data @param array<string, string> $headers */
    protected function postCommand(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->postJson($uri, $data, $headers + ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $data @param array<string, string> $headers */
    protected function patchCommand(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->patchJson($uri, $data, $headers + ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $attributes */
    protected function createStaffWithRole(string $roleCode, array $attributes = []): User
    {
        $role = Role::query()->where('code', $roleCode)->sole();
        $staff = User::factory()->create($attributes + ['password' => self::STAFF_TEST_PASSWORD]);
        $staff->roles()->attach($role);

        return $staff;
    }

    /** @param list<string> $codes */
    protected function grantRolePermissions(string $roleCode, array $codes): void
    {
        $permissions = Permission::query()->whereIn('code', $codes)->pluck('id');
        if ($permissions->count() !== count(array_unique($codes))) {
            throw new InvalidArgumentException('A test fixture requested an unknown permission. Seed the reference catalog first.');
        }
        Role::query()->where('code', $roleCode)->sole()->permissions()->syncWithoutDetaching($permissions);
    }

    protected function fakeProtectedStorage(): FilesystemAdapter
    {
        $name = 'verification-'.DB::getDriverName().'-'.getmypid().'-'.substr(hash('sha256', static::class.'::'.$this->name()), 0, 12);
        $disk = Storage::fake($name, ['throw' => true, 'visibility' => 'private', 'serve' => false]);
        $root = realpath($disk->path(''));
        $parent = realpath(storage_path('framework/testing/disks'));
        if ($root === false || $parent === false
            || ! str_starts_with(str_replace('\\', '/', $root), str_replace('\\', '/', $parent).'/verification-')) {
            throw new RuntimeException('Private test files must stay inside the framework testing directory.');
        }
        Storage::set('protected', $disk);
        $this->beforeApplicationDestroyed(function () use ($root): void {
            (new Filesystem)->cleanDirectory($root);
        });

        return $disk;
    }

    /** @param list<string> $fields */
    protected function assertApiFailure(TestResponse $response, int $status, string $code, array $fields = []): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code)
            ->assertJsonStructure(['message', 'code'])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('application/json', strtolower($response->headers->get('Content-Type')));
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', trim($response->json('message')));
        if ($fields !== []) {
            $response->assertJsonValidationErrors($fields);
        }
    }

    /**
     * Compare contents, not just counts, without printing private rows on failure.
     * Rejected-attempt audit rows must be asserted separately from domain state.
     *
     * @template T
     * @param list<string> $tables
     * @param Closure(): T $operation
     * @return T
     */
    protected function assertTablesUnchanged(array $tables, Closure $operation): mixed
    {
        if ($tables === []) {
            throw new InvalidArgumentException('A rollback check must name the affected tables.');
        }
        $before = [];
        foreach (array_unique($tables) as $table) {
            $before[$table] = $this->tableDigest($table);
        }
        $failure = null;
        $result = null;
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        foreach ($before as $table => $digest) {
            $this->assertSame($digest, $this->tableDigest($table), "Table {$table} changed during an operation that must leave it unchanged.");
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    /** @param array<string, mixed> $summary */
    protected function assertAuditEventRecorded(
        string $action,
        ?int $actorId,
        string $subjectType,
        ?int $subjectId,
        string $outcome = 'succeeded',
        array $summary = [],
        int $count = 1,
    ): void {
        $events = DB::table('audit_events')->where([
            'action' => $action, 'actor_id' => $actorId, 'subject_type' => $subjectType,
            'subject_id' => $subjectId, 'outcome' => $outcome,
        ])->get();
        $this->assertCount($count, $events, 'Unexpected number of matching audit events.');
        foreach ($events as $event) {
            $actual = json_decode($event->safe_summary, true, flags: JSON_THROW_ON_ERROR);
            foreach ($summary as $field => $value) {
                $this->assertArrayHasKey($field, $actual);
                $this->assertSame($value, $actual[$field]);
            }
        }
    }

    protected function assertNoSuccessfulAudit(string $action): void
    {
        $this->assertSame(0, DB::table('audit_events')->where('action', $action)->where('outcome', 'succeeded')->count(),
            'A rejected or failed operation must not leave a successful audit event.');
    }

    /** @param list<string> $values */
    protected function assertAuditExcludes(array $values): void
    {
        $contents = [];
        foreach (DB::table('audit_events')->get() as $event) {
            $fields = (array) $event;
            $fields['safe_summary'] = json_decode($event->safe_summary, true, flags: JSON_THROW_ON_ERROR);
            array_walk_recursive($fields, function (mixed $value) use (&$contents): void {
                if (is_scalar($value)) {
                    $contents[] = (string) $value;
                }
            });
        }
        foreach ($values as $value) {
            if ($value === '') {
                throw new InvalidArgumentException('Audit privacy checks require a non-empty sentinel.');
            }
            $found = collect($contents)->contains(fn (string $content): bool => str_contains($content, $value));
            $this->assertFalse($found, 'An audit field contains a forbidden fixture value.');
        }
    }

    /** @return Closure(): bool Reports whether the intended database write was reached. */
    protected function failNextWriteTo(string $table, string $operation): Closure
    {
        $this->validateTestTable($table);
        $verb = match ($operation) {
            'insert' => 'insert\s+into',
            'update' => 'update',
            'delete' => 'delete\s+from',
            default => throw new InvalidArgumentException('Select insert, update or delete as the failure boundary.'),
        };
        $triggered = false;
        $pattern = '/^'.$verb.'\s+[`"]?'.preg_quote($table, '/').'[`"]?(?:\s|\()/i';
        DB::listen(function (QueryExecuted $event) use (&$triggered, $pattern): void {
            if (! $triggered && preg_match($pattern, $event->sql) === 1) {
                $triggered = true;
                throw new RuntimeException('Synthetic failure after a database write.');
            }
        });

        return static function () use (&$triggered): bool {
            return $triggered;
        };
    }

    private function tableDigest(string $table): string
    {
        $this->validateTestTable($table);
        $rows = DB::table($table)->get()->map(function (object $row): string {
            $values = (array) $row;
            ksort($values, SORT_STRING);

            return json_encode($values, JSON_THROW_ON_ERROR);
        })->all();
        sort($rows, SORT_STRING);

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function validateTestTable(string $table): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new InvalidArgumentException('Verification expects an unqualified application table name.');
        }
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $this->ensureDatabaseIsIsolated($app);

        return $app;
    }

    protected function ensureDatabaseIsIsolated(Application $app): void
    {
        $connection = $app['config']->get('database.default');
        $settings = $app['config']->get("database.connections.{$connection}");

        $safeSqlite = $connection === 'sqlite' && $settings['database'] === ':memory:' && empty($settings['url']);
        $safeMysql = $connection === 'mysql'
            && $settings['database'] === 'easycare_test'
            && $settings['username'] === 'easycare_test'
            && in_array($settings['host'], ['127.0.0.1', 'localhost', '::1'], true)
            && empty($settings['url']);

        if (! $app->environment('testing') || $app->configurationIsCached() || (! $safeSqlite && ! $safeMysql)) {
            throw new RuntimeException('Tests require uncached testing configuration and an isolated test database.');
        }
    }
}
