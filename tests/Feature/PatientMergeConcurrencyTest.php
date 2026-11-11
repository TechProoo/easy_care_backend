<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientMergeRequest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PatientMergeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_merge_approvals_with_one_key_commit_once_and_confirm_the_receipt(): void
    {
        [$actor, $source, $target, $proposal] = $this->fixtures();
        $data = $this->data(['version' => 2, 'request_version' => 1]);
        [$first, $second] = $this->concurrent($actor, $data, $data, 'merge-key-000001', 'merge-key-000001', $source->id, 'approve', $proposal->id);
        $this->assertSame([200, 200], [$first['status'], $second['status']]);
        $this->assertDatabaseCount('patient_merges', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertSame(3, $source->fresh()->version);
        $this->assertSame(2, $target->fresh()->version);
        $this->assertAuditEventRecorded('patient.merge_approved', $actor->id, 'patient', $source->id);
    }

    public function test_parallel_distinct_approval_commands_reject_the_late_stale_decision(): void
    {
        [$actor, $source, $target, $proposal] = $this->fixtures();
        $data = $this->data(['version' => 2, 'request_version' => 1]);
        [$first, $second] = $this->concurrent($actor, $data, $data, 'merge-key-000001', 'merge-key-000002', $source->id, 'approve', $proposal->id);
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertDatabaseCount('patient_merges', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_parallel_merge_requests_create_one_pending_proposal(): void
    {
        [$actor, $source, $target] = $this->fixtures(false);
        $data = $this->data(['target_patient_id' => $target->id, 'target_version' => 1]);
        [$first, $second] = $this->concurrent($actor, $data, $data, 'merge-key-000001', 'merge-key-000002', $source->id, 'request');
        $this->assertSame([201, 409], [$first['status'], $second['status']]);
        $this->assertDatabaseCount('patient_merge_requests', 1);
        $this->assertDatabaseCount('patient_merges', 0);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    private function fixtures(bool $pending = true): array
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real parallel merge transactions require the isolated MySQL database.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk', ['patients.view', 'patients.merges.request', 'patients.merges.approve']);
        $actor = $this->createStaffWithRole('frontdesk', ['password' => 'Secret-password-123']);
        $requester = $this->createStaffWithRole('frontdesk');
        $source = Patient::factory()->create(['version' => $pending ? 2 : 1]);
        $target = Patient::factory()->create();
        $proposal = $pending ? PatientMergeRequest::factory()->create(['source_patient_id' => $source->id,
            'target_patient_id' => $target->id, 'source_version' => 2, 'target_version' => 1, 'requested_by' => $requester->id]) : null;

        return [$actor, $source, $target, $proposal];
    }

    private function data(array $extra): array
    {
        return array_replace(['version' => 1, 'reason' => 'Synthetic independent identity review', 'current_password' => 'Secret-password-123'], $extra);
    }

    private function concurrent(User $actor, array $firstData, array $secondData, string $firstKey, string $secondKey, int $target, string $action, ?int $proposal = null): array
    {
        $first = $this->worker($actor, $firstData, $firstKey, $target, $action, $proposal, true);
        $second = $this->worker($actor, $secondData, $secondKey, $target, $action, $proposal, false);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED')),
                $first->getErrorOutput());
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

    private function worker(User $actor, array $data, string $key, int $target, string $action, ?int $proposal, bool $hold): Process
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || $app->configurationIsCached() || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'easycare_test'
    || config('database.connections.mysql.username') !== 'easycare_test'
    || !in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true)
    || config('database.connections.mysql.url')) {
    throw new RuntimeException('Parallel workers require the isolated test database.');
}
$data = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
$actor = App\Models\User::query()->findOrFail((int) $argv[3]);
$target = (int) $argv[5];
$action = $argv[7];
$proposal = $argv[8] === '' ? null : (int) $argv[8];
$path = '/api/v1/patients/'.$target.'/merge-requests'.($proposal ? '/'.$proposal.'/approve' : '');
$request = Illuminate\Http\Request::create($path, 'POST', $data);
$request->headers->set('Idempotency-Key', $argv[4]);
$request->setUserResolver(fn () => $actor);
$route = new Illuminate\Routing\Route('POST', 'api/v1/patients/{patient}/merge-requests'.($proposal ? '/{mergeRequest}/approve' : ''), fn () => null);
$route->name('api.v1.patients.records.merges.'.$action);
$route->bind($request);
$route->setParameter('patient', (string) $target);
if ($proposal) { $route->setParameter('mergeRequest', (string) $proposal); }
$request->setRouteResolver(fn () => $route);
$app->instance('request', $request);
$request->setUserResolver(fn () => $actor);
try {
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $request, $data, $argv) {
        $receipt = $app->make(App\Services\PatientRecordManagement::class)->execute($request, $data);
        if ($argv[6] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
        return response()->json(['data' => $receipt], $argv[7] === 'request' ? 201 : 200);
    });
    echo json_encode(['status' => $response->getStatusCode(), 'id' => $response->getData(true)['data']['id']])."\n";
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode(), 'code' => $exception instanceof App\Exceptions\WriteConflict ? $exception->errorCode : 'conflict'])."\n";
}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), json_encode($data, JSON_THROW_ON_ERROR),
            (string) $actor->id, $key, (string) $target, $hold ? 'hold' : 'run', $action, $proposal === null ? '' : (string) $proposal],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql'], timeout: 20);
    }

    private function workerResult(Process $process): array
    {
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
