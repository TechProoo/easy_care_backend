<?php

namespace Tests\Feature;

use App\Models\ClinicalRecord;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PrescriptionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $author;
    private Encounter $visit;

    public function test_same_key_parallel_orders_save_one_prescription_and_one_set_of_lines(): void
    {
        $this->fixtures();
        $job = $this->job('store', ['encounter_version' => 1, 'payload' => ['items' => [$this->item()]]]);
        [$first, $second] = $this->concurrent($job, $job);
        $this->assertSame([201, 200], [$first['status'], $second['status']]);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('clinical_records', 1);
        $this->assertDatabaseCount('prescriptions', 1);
        $this->assertDatabaseCount('prescription_items', 1);
        $this->assertDatabaseCount('clinical_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_signing_blocks_a_competing_line_replacement(): void
    {
        $record = $this->draft();
        [$first, $second] = $this->concurrent($this->job('sign', $this->credentials(), $record),
            $this->job('update', ['encounter_version' => 1, 'version' => 1, 'payload' => ['items' => [$this->item('Rejected concurrent medication')]]], $record));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertSame('signed', $record->fresh()->status);
        $this->assertSame('Synthetic test medication', $record->fresh()->prescription->items->sole()->medication_name);
    }

    public function test_line_replacement_requires_a_competing_signer_to_review_the_new_version(): void
    {
        $record = $this->draft();
        [$first, $second] = $this->concurrent($this->job('update', ['encounter_version' => 1, 'version' => 1, 'payload' => ['items' => [$this->item('Saved concurrent medication')]]], $record),
            $this->job('sign', $this->credentials(), $record));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertSame('draft', $record->fresh()->status);
        $this->assertSame('Saved concurrent medication', $record->fresh()->prescription->items->sole()->medication_name);
    }

    public function test_competing_stop_and_correction_cannot_branch_or_reactivate_the_original_order(): void
    {
        $record = $this->draft();
        $record->forceFill(['status' => 'signed', 'version' => 2, 'finalized_by' => $this->author->id, 'finalized_at' => now('UTC')])->save();
        $other = $this->createStaffWithRole('doctor');
        [$first, $second] = $this->concurrent($this->job('stop', $this->credentials(2) + ['reason' => 'Concurrent signed stop'], $record),
            $this->job('amend', $this->credentials(2) + ['reason' => 'Competing correction', 'payload' => ['instructions' => 'Rejected replacement']], $record, $other));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('clinical_not_current', $second['code']);
        $this->assertDatabaseCount('clinical_records', 2);
        $this->assertDatabaseCount('clinical_revisions', 2);
        $this->assertSame('stopped', $record->fresh()->amendment->prescription->order_status);
        $this->assertSame('active', $record->fresh()->prescription->order_status);
    }

    private function fixtures(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real parallel prescribing transactions require the isolated MySQL database.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'encounters.view_any',
            'prescriptions.view', 'prescriptions.write', 'prescriptions.sign', 'prescriptions.amend', 'prescriptions.void']);
        $this->author = $this->createStaffWithRole('doctor');
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHour()]);
    }

    private function draft(): ClinicalRecord
    {
        $this->fixtures();

        return Prescription::factory()->withItems()->create(['clinical_record_id' => ClinicalRecord::factory()->forEncounter($this->visit)
            ->create(['kind' => 'prescription', 'authored_by' => $this->author->id])->id])->record;
    }

    private function credentials(int $version = 1): array
    {
        return ['encounter_version' => 1, 'version' => $version, 'current_password' => self::STAFF_TEST_PASSWORD];
    }

    private function item(string $name = 'Concurrent synthetic medication'): array
    {
        return ['medication_name' => $name, 'dose' => 'Staff-entered dose', 'route' => 'Staff-entered route', 'frequency' => 'Staff-entered frequency'];
    }

    private function job(string $action, array $data, ?ClinicalRecord $record = null, ?User $actor = null): array
    {
        return ['actor' => ($actor ?? $this->author)->id, 'data' => $data, 'key' => fake()->uuid(),
            'encounter' => $this->visit->id, 'record' => $record?->id, 'action' => $action];
    }

    private function concurrent(array $firstJob, array $secondJob): array
    {
        $first = $this->worker($firstJob, true);
        $second = $this->worker($secondJob, false);
        try {
            $first->start();
            $ready = $first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED'));
            $this->assertTrue($ready, $first->getErrorOutput().$first->getOutput());
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

    private function worker(array $job, bool $hold): Process
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
$job = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
$actor = App\Models\User::query()->findOrFail($job['actor']);
$action = $job['action'];
$path = '/api/v1/encounters/'.$job['encounter'].'/prescriptions'.($job['record'] ? '/'.$job['record'] : '')
    .(in_array($action, ['sign', 'amend', 'void', 'stop', 'complete'], true) ? '/'.$action : '');
$method = $action === 'update' ? 'PATCH' : 'POST';
$request = Illuminate\Http\Request::create($path, $method, $job['data']);
$request->headers->set('Idempotency-Key', $job['key']);
$request->setUserResolver(fn () => $actor);
$route = new Illuminate\Routing\Route($method, ltrim($path, '/'), fn () => null);
$route->name('api.v1.prescriptions.'.$action);
$route->bind($request);
$route->setParameter('encounter', (string) $job['encounter']);
if ($job['record']) { $route->setParameter('clinicalRecord', (string) $job['record']); }
$request->setRouteResolver(fn () => $route);
$app->instance('request', $request);
$request->setUserResolver(fn () => $actor);
try {
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $actor, $request, $action, $job, $argv) {
        $record = $app->make(App\Services\ClinicalRecords::class)->execute($request, $actor, $job['data']);
        if ($argv[3] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
        return (new App\Http\Resources\ClinicalResource($record))->response()->setStatusCode(in_array($action, ['store', 'amend']) ? 201 : 200);
    });
    echo json_encode(['status' => $response->getStatusCode(), 'id' => $response->getData(true)['data']['id']])."\n";
} catch (Illuminate\Validation\ValidationException $exception) {
    echo json_encode(['status' => 422, 'code' => 'validation_failed', 'fields' => array_keys($exception->errors())])."\n";
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode(), 'code' => $exception instanceof App\Exceptions\WriteConflict ? $exception->errorCode : 'conflict'])."\n";
}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), json_encode($job, JSON_THROW_ON_ERROR), $hold ? 'hold' : 'run'],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql'], timeout: 20);
    }

    private function workerResult(Process $process): array
    {
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
