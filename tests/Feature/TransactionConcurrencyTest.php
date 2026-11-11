<?php

namespace Tests\Feature;

use App\Exceptions\WriteConflict;
use App\Models\Role;
use App\Models\User;
use App\Services\WriteTransactions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TransactionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_database_only_retries_roll_back_every_failed_attempt(): void
    {
        $attempts = 0;
        $result = app(WriteTransactions::class)->run(function () use (&$attempts): string {
            $attempts++;
            DB::table('departments')->insert(['code' => 'retry-test', 'name' => 'Synthetic retry']);
            if ($attempts < 3) {
                throw new QueryException('testing', 'synthetic statement', [], new PDOException('Deadlock found when trying to get lock', 40001));
            }

            return 'committed';
        });
        $this->assertSame('committed', $result);
        $this->assertSame(3, $attempts);
        $this->assertSame(1, DB::table('departments')->where('code', 'retry-test')->count());
    }

    public function test_nested_database_services_retry_the_complete_outer_command(): void
    {
        $attempts = 0;
        $transactions = app(WriteTransactions::class);
        $transactions->run(function () use (&$attempts, $transactions): void {
            DB::table('departments')->insert(['code' => 'nested-retry', 'name' => 'Synthetic retry']);
            $transactions->run(function () use (&$attempts): void {
                $attempts++;
                if ($attempts < 3) {
                    throw new QueryException('testing', 'synthetic statement', [], new PDOException('Deadlock found when trying to get lock', 40001));
                }
            });
        });
        $this->assertSame(3, $attempts);
        $this->assertDatabaseCount('departments', 1);
    }

    public function test_filesystem_operations_are_never_automatically_replayed(): void
    {
        $attempts = 0;
        try {
            app(WriteTransactions::class)->run(function () use (&$attempts): void {
                $attempts++;
                DB::table('departments')->insert(['code' => 'file-retry-test', 'name' => 'Synthetic retry']);
                throw new QueryException('testing', 'synthetic statement', [], new PDOException('Deadlock found when trying to get lock', 40001));
            }, databaseOnly: false);
            $this->fail('The write must not be confirmed.');
        } catch (WriteConflict $exception) {
            $this->assertSame('write_busy', $exception->errorCode);
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame(['Retry-After' => '1'], $exception->getHeaders());
        }
        $this->assertSame(1, $attempts);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_exhausted_database_retries_return_a_safe_retryable_failure(): void
    {
        $attempts = 0;
        try {
            app(WriteTransactions::class)->run(function () use (&$attempts): void {
                $attempts++;
                DB::table('departments')->insert(['code' => 'busy-test', 'name' => 'Synthetic retry']);
                throw new QueryException('testing', 'Private SQL and bindings', ['private'], new PDOException('Deadlock found when trying to get lock', 40001));
            });
            $this->fail('All retries must fail.');
        } catch (WriteConflict $exception) {
            $this->assertSame('write_busy', $exception->errorCode);
            $this->assertStringNotContainsString('Private SQL', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame(3, $attempts);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_unrelated_failures_are_not_retried(): void
    {
        $attempts = 0;
        try {
            app(WriteTransactions::class)->run(function () use (&$attempts): void {
                $attempts++;
                throw new RuntimeException('Synthetic permanent failure.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic permanent failure.', $exception->getMessage());
        }
        $this->assertSame(1, $attempts);
    }

    public function test_simultaneous_duplicate_creates_commit_one_resource(): void
    {
        $this->requireMysql();
        $actor = $this->actor();
        $data = $this->data();
        [$first, $second] = $this->concurrent($actor, $data, $data, 'concurrent-key-000001', 'concurrent-key-000001');
        $this->assertSame([201, 200], [$first['status'], $second['status']]);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'staff.created')->count());
    }

    public function test_simultaneous_different_payloads_cannot_share_a_key(): void
    {
        $this->requireMysql();
        $actor = $this->actor();
        [$first, $second] = $this->concurrent($actor, $this->data(), array_replace($this->data(), ['first_name' => 'Different']),
            'concurrent-key-000001', 'concurrent-key-000001');
        $this->assertSame([201, 409], [$first['status'], $second['status']]);
        $this->assertSame('idempotency_conflict', $second['code']);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_simultaneous_edits_with_the_same_version_reject_the_second_editor(): void
    {
        $this->requireMysql();
        $actor = $this->actor();
        $target = User::factory()->create();
        $target->roles()->attach(Role::where('code', 'doctor')->sole());
        $data = collect($this->data())->except('username')->all() + ['version' => 1];
        [$first, $second] = $this->concurrent($actor, $data, array_replace($data, ['first_name' => 'Stale']),
            'concurrent-key-000001', 'concurrent-key-000002', $target->id);
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('Concurrent', $target->fresh()->first_name);
        $this->assertSame(2, $target->fresh()->version);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    private function requireMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real parallel transactions require the isolated MySQL database.');
        }
    }

    private function actor(): User
    {
        $this->seed(DatabaseSeeder::class);
        $actor = User::factory()->create(['password' => 'Correct-secret-123!']);
        $actor->roles()->attach(Role::where('code', 'admin')->sole());

        return $actor;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['username' => 'concurrent.staff', 'first_name' => 'Concurrent', 'last_name' => 'Staff',
            'middle_name' => null, 'email' => 'concurrent.staff@example.test', 'staff_code' => null, 'department_id' => null,
            'role_codes' => ['doctor'], 'is_active' => true, 'current_password' => 'Correct-secret-123!'];
    }

    /** @param array<string, mixed> $firstData @param array<string, mixed> $secondData @return array{array<string, mixed>, array<string, mixed>} */
    private function concurrent(User $actor, array $firstData, array $secondData, string $firstKey, string $secondKey, ?int $target = null): array
    {
        $first = $this->worker($actor, $firstData, $firstKey, $target, true);
        $second = $this->worker($actor, $secondData, $secondKey, $target, false);
        try {
            $first->start();
            $locked = $first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED'));
            $this->assertTrue($locked, $first->getOutput().$first->getErrorOutput());
            $second->start();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

            return [$this->workerResult($first), $this->workerResult($second)];
        } finally {
            $first->stop(0);
            $second->stop(0);
        }
    }

    /** @param array<string, mixed> $data */
    private function worker(User $actor, array $data, string $key, ?int $target, bool $hold): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test'
    || config('database.connections.mysql.username') !== 'easycare_test') {
    throw new RuntimeException('Parallel workers require the isolated test database.');
}
$data = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
$actor = App\Models\User::query()->findOrFail((int) $argv[3]);
$target = $argv[5] === '' ? null : App\Models\User::query()->findOrFail((int) $argv[5]);
$request = Illuminate\Http\Request::create('/api/v1/staff-management'.($target ? '/'.$target->id : ''), $target ? 'PATCH' : 'POST', $data);
$request->headers->set('Idempotency-Key', $argv[4]);
$request->setUserResolver(fn () => $actor);
$route = new Illuminate\Routing\Route($target ? 'PATCH' : 'POST', 'api/v1/staff-management'.($target ? '/{user}' : ''), fn () => null);
$route->name('api.v1.staff-management.'.($target ? 'update' : 'store'));
$route->bind($request);
if ($target) { $route->setParameter('user', $target); }
$request->setRouteResolver(fn () => $route);
$app->instance('request', $request);
$request->setUserResolver(fn () => $actor);
try {
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $actor, $target, $data, $argv) {
        $staff = $app->make(App\Services\StaffAdministration::class);
        $user = $target ? $staff->update($actor, $target, $data) : $staff->create($actor, $data);
        if ($argv[6] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
        return (new App\Http\Resources\StaffManagementResource($user->load(['department', 'roles'])))->response()->setStatusCode($target ? 200 : 201);
    });
    echo json_encode(['status' => $response->getStatusCode(), 'id' => $response->getData(true)['data']['id']])."\n";
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode(), 'code' => $exception instanceof App\Exceptions\WriteConflict ? $exception->errorCode : 'conflict'])."\n";
}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), json_encode($data, JSON_THROW_ON_ERROR),
            (string) $actor->id, $key, $target === null ? '' : (string) $target, $hold ? 'hold' : 'run'],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql'], timeout: 20);
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $process): array
    {
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
