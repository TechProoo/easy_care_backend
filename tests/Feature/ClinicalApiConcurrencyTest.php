<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\ClinicalRecord;
use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ClinicalApiConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $author;

    private Encounter $visit;

    public function test_parallel_identical_creations_save_once_and_confirm_the_same_record(): void
    {
        $this->fixtures();
        $job = $this->job('store', ['encounter_version' => 1, 'kind' => 'entry', 'payload' => ['section' => 'note', 'content' => 'Concurrent synthetic note']]);
        [$first, $second] = $this->concurrent($job, $job);
        $this->assertSame([201, 200], [$first['status'], $second['status']]);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('clinical_records', 1);
        $this->assertDatabaseCount('clinical_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_signature_prevents_a_competing_stale_edit_from_changing_signed_content(): void
    {
        $record = $this->draft();
        [$first, $second] = $this->concurrent($this->job('sign', $this->credentials(), $record),
            $this->job('update', ['encounter_version' => 1, 'version' => 1, 'payload' => ['content' => 'Rejected concurrent overwrite']], $record));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertSame('signed', $record->fresh()->status);
        $this->assertSame('Original concurrent note', $record->fresh()->entry->content);
        $this->assertDatabaseCount('clinical_revisions', 1);
    }

    public function test_an_edit_forces_a_competing_signature_to_review_the_new_draft_version(): void
    {
        $record = $this->draft();
        [$first, $second] = $this->concurrent($this->job('update', ['encounter_version' => 1, 'version' => 1, 'payload' => ['content' => 'Saved concurrent edit']], $record),
            $this->job('sign', $this->credentials(), $record));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertSame('draft', $record->fresh()->status);
        $this->assertSame('Saved concurrent edit', $record->fresh()->entry->content);
    }

    public function test_competing_amendment_and_retraction_cannot_branch_a_signed_record(): void
    {
        $record = $this->draft();
        $record->forceFill(['status' => 'signed', 'version' => 2, 'finalized_by' => $this->author->id, 'finalized_at' => now('UTC')])->save();
        $other = $this->createStaffWithRole('doctor');
        [$first, $second] = $this->concurrent($this->job('amend', $this->credentials(2) + ['reason' => 'Concurrent correction reason', 'payload' => ['content' => 'Saved signed correction']], $record),
            $this->job('void', $this->credentials(2) + ['reason' => 'Competing retraction reason'], $record, $other));
        $this->assertSame([201, 409], [$first['status'], $second['status']]);
        $this->assertSame('clinical_not_current', $second['code']);
        $this->assertDatabaseCount('clinical_records', 2);
        $this->assertDatabaseCount('clinical_revisions', 2);
        $this->assertSame('signed', $record->fresh()->amendment->status);
        $this->assertSame('Original concurrent note', $record->fresh()->entry->content);
    }

    public function test_visit_cancellation_is_visible_to_a_waiting_clinical_save(): void
    {
        $record = $this->draft();
        [$first, $second] = $this->concurrent($this->job('cancel', ['version' => 1, 'reason' => 'Concurrent cancellation']),
            $this->job('update', ['encounter_version' => 1, 'version' => 1, 'payload' => ['content' => 'Rejected after cancellation']], $record));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertSame('cancelled', $this->visit->fresh()->status);
        $this->assertSame('Original concurrent note', $record->fresh()->entry->content);
        $this->assertDatabaseCount('clinical_revisions', 0);
    }

    public function test_patient_restriction_blocks_a_waiting_signature_without_leaving_a_receipt(): void
    {
        $record = $this->draft();
        [$first, $second] = $this->concurrent($this->job('restrict', ['version' => 1, 'reason' => 'Concurrent access restriction', 'current_password' => self::STAFF_TEST_PASSWORD]),
            $this->job('sign', $this->credentials(), $record));
        $this->assertSame([200, 404], [$first['status'], $second['status']]);
        $this->assertSame('restricted', $this->visit->patient->fresh()->access_status);
        $this->assertSame('draft', $record->fresh()->status);
        $this->assertDatabaseCount('clinical_revisions', 0);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    private function fixtures(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real parallel clinical API transactions require the isolated MySQL database.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor', ['patients.view', 'patients.access.manage', 'encounters.view', 'encounters.view_any', 'encounters.cancel',
            'clinical.view', 'clinical.write', 'clinical.sign', 'clinical.amend', 'clinical.void']);
        $this->author = $this->createStaffWithRole('doctor');
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHour()]);
    }

    private function draft(): ClinicalRecord
    {
        $this->fixtures();

        return ClinicalEntry::factory()->create(['clinical_record_id' => ClinicalRecord::factory()->forEncounter($this->visit)
            ->create(['authored_by' => $this->author->id])->id, 'fields' => new \stdClass, 'content' => 'Original concurrent note'])->record;
    }

    private function credentials(int $version = 1): array
    {
        return ['encounter_version' => 1, 'version' => $version, 'current_password' => self::STAFF_TEST_PASSWORD];
    }

    private function job(string $action, array $data, ?ClinicalRecord $record = null, ?User $actor = null): array
    {
        return ['actor' => ($actor ?? $this->author)->id, 'data' => $data, 'key' => fake()->uuid(),
            'encounter' => $this->visit->id, 'patient' => $this->visit->patient_id, 'record' => $record?->id, 'action' => $action];
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
$clinical = !in_array($action, ['cancel', 'restrict'], true);
$path = $action === 'restrict' ? '/api/v1/patients/'.$job['patient'].'/access/restrict'
    : '/api/v1/encounters/'.$job['encounter'].($clinical ? '/clinical'.($job['record'] ? '/'.$job['record'] : '')
        .(in_array($action, ['sign', 'amend', 'void'], true) ? '/'.$action : '') : '/cancel');
$method = $action === 'update' ? 'PATCH' : 'POST';
$request = Illuminate\Http\Request::create($path, $method, $job['data']);
$request->headers->set('Idempotency-Key', $job['key']);
$request->setUserResolver(fn () => $actor);
$route = new Illuminate\Routing\Route($method, ltrim($path, '/'), fn () => null);
$route->name($clinical ? 'api.v1.clinical.'.$action : ($action === 'cancel' ? 'api.v1.encounters.cancel' : 'api.v1.patients.records.access.restrict'));
$route->bind($request);
$route->setParameter('encounter', (string) $job['encounter']);
$route->setParameter('patient', (string) $job['patient']);
if ($job['record']) { $route->setParameter('clinicalRecord', (string) $job['record']); }
$request->setRouteResolver(fn () => $route);
$app->instance('request', $request);
$request->setUserResolver(fn () => $actor);
try {
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $actor, $request, $clinical, $action, $job, $argv) {
        if ($clinical) {
            $record = $app->make(App\Services\ClinicalRecords::class)->execute($request, $actor, $job['data']);
            $response = (new App\Http\Resources\ClinicalResource($record))->response()->setStatusCode(in_array($action, ['store', 'amend']) ? 201 : 200);
        } elseif ($action === 'cancel') {
            $visit = $app->make(App\Services\EncounterFlow::class)->execute($request, $actor, $job['data']);
            $response = (new App\Http\Resources\EncounterResource($visit))->response();
        } else {
            $response = response()->json(['data' => $app->make(App\Services\PatientRecordManagement::class)->execute($request, $job['data'])]);
        }
        if ($argv[3] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
        return $response;
    });
    echo json_encode(['status' => $response->getStatusCode(), 'id' => $response->getData(true)['data']['id']])."\n";
} catch (Illuminate\Database\Eloquent\ModelNotFoundException) {
    echo json_encode(['status' => 404, 'code' => 'not_found'])."\n";
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
