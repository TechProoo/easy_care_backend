<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PatientConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_registration_confirmation_commits_one_patient_contact_and_number(): void
    {
        $actor = $this->actor();
        $data = $this->data();
        [$first, $second] = $this->concurrent($actor, $data, $data, 'patient-key-000001', 'patient-key-000001');
        $this->assertSame([201, 200], [$first['status'], $second['status']]);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('patient_identifiers', 1);
        $this->assertDatabaseCount('patient_contacts', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertAuditEventRecorded('patient.created', $actor->id, 'patient', $first['id']);
    }

    public function test_parallel_distinct_numbers_still_require_review_of_the_first_committed_duplicate(): void
    {
        $actor = $this->actor();
        [$first, $second] = $this->concurrent($actor, $this->data(), array_replace($this->data(), ['mrn' => 'PARALLEL-002']),
            'patient-key-000001', 'patient-key-000002');
        $this->assertSame([201, 409], [$first['status'], $second['status']]);
        $this->assertSame('duplicate_review_required', $second['code']);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('patient_contacts', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_parallel_edits_reject_the_stale_version_without_losing_the_first_save(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create(['created_by' => $actor->id]);
        [$first, $second] = $this->concurrent($actor, ['version' => 1, 'address' => 'First save'],
            ['version' => 1, 'address' => 'Second save'], 'patient-key-000001', 'patient-key-000002', $patient->id);
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertSame('First save', $patient->fresh()->address);
        $this->assertSame(2, $patient->fresh()->version);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertAuditEventRecorded('patient.updated', $actor->id, 'patient', $patient->id);
    }

    private function actor(): User
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real parallel patient transactions require the isolated MySQL database.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk', ['patients.view', 'patients.create', 'patients.update']);

        return $this->createStaffWithRole('frontdesk');
    }

    private function data(): array
    {
        return ['mrn' => 'PARALLEL-001', 'first_name' => 'Concurrent', 'last_name' => 'Patient',
            'date_of_birth' => '2000-01-01', 'category_id' => PatientCategory::where('code', 'private')->sole()->id,
            'contacts' => [['name' => 'Synthetic kin', 'relationship' => 'parent', 'phone' => '+2348000000000']]];
    }

    private function concurrent(User $actor, array $firstData, array $secondData, string $firstKey, string $secondKey, ?int $target = null): array
    {
        $first = $this->worker($actor, $firstData, $firstKey, $target, true);
        $second = $this->worker($actor, $secondData, $secondKey, $target, false);
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

    private function worker(User $actor, array $data, string $key, ?int $target, bool $hold): Process
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
$target = $argv[5] === '' ? null : (int) $argv[5];
$request = Illuminate\Http\Request::create('/api/v1/patients'.($target ? '/'.$target : ''), $target ? 'PATCH' : 'POST', $data);
$request->headers->set('Idempotency-Key', $argv[4]);
$request->setUserResolver(fn () => $actor);
$route = new Illuminate\Routing\Route($target ? 'PATCH' : 'POST', 'api/v1/patients'.($target ? '/{patient}' : ''), fn () => null);
$route->name('api.v1.patients.'.($target ? 'update' : 'store'));
$route->bind($request);
if ($target) { $route->setParameter('patient', (string) $target); }
$request->setRouteResolver(fn () => $route);
$app->instance('request', $request);
$request->setUserResolver(fn () => $actor);
try {
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $actor, $target, $data, $argv) {
        $registry = $app->make(App\Services\PatientRegistry::class);
        $patient = $target ? $registry->update($actor, $target, $data) : $registry->create($actor, $data);
        if ($argv[6] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
        return (new App\Http\Resources\PatientResource($patient->load(['category', 'contacts', 'identifiers'])))->response()->setStatusCode($target ? 200 : 201);
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

    private function workerResult(Process $process): array
    {
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
