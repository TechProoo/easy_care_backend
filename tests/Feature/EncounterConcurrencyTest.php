<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EncounterConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_arrival_with_same_key_opens_once_and_confirms_saved_encounter(): void
    {
        [$actor, $doctor, $patient] = $this->fixtures();
        $booking = Appointment::factory()->create();
        $job = $this->job($actor, ['version' => 1, 'patient_id' => $patient->id, 'patient_version' => 1,
            'identity_verified' => true, 'care_setting' => 'OPD', 'reason' => 'Synthetic identity check'], 'same-arrival-key-0001', $booking->id, 'arrive');
        [$first, $second] = $this->concurrent($job, $job);
        $this->assertSame([201, 200], [$first['status'], $second['status']]);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('encounters', 1);
        $this->assertDatabaseCount('appointment_revisions', 1);
        $this->assertDatabaseCount('encounter_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_parallel_distinct_arrivals_cannot_open_two_visits_from_one_booking(): void
    {
        [$actor, $doctor, $patient] = $this->fixtures();
        $other = $this->createStaffWithRole('frontdesk');
        $booking = Appointment::factory()->create();
        $data = ['version' => 1, 'patient_id' => $patient->id, 'patient_version' => 1, 'identity_verified' => true,
            'care_setting' => 'OPD', 'reason' => 'Synthetic identity check'];
        [$first, $second] = $this->concurrent($this->job($actor, $data, 'first-arrival-key-0001', $booking->id, 'arrive'),
            $this->job($other, $data, 'other-arrival-key-0001', $booking->id, 'arrive'));
        $this->assertSame([201, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertDatabaseCount('encounters', 1);
        $this->assertDatabaseCount('appointment_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_two_staff_cannot_assign_different_current_clinicians_from_one_version(): void
    {
        [$actor, $doctor, $patient] = $this->fixtures();
        $otherRegistrar = $this->createStaffWithRole('frontdesk');
        $visit = Encounter::factory()->for($patient)->create();
        $other = $this->createStaffWithRole('doctor');
        $data = ['version' => 1, 'reason' => 'Synthetic assignment', 'consultation_type' => 'General Consultation'];
        [$first, $second] = $this->concurrent($this->job($actor, $data + ['doctor_id' => $doctor->id], 'first-assign-key-0001', $visit->id, 'assign'),
            $this->job($otherRegistrar, $data + ['doctor_id' => $other->id], 'other-assign-key-0001', $visit->id, 'assign'));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertDatabaseCount('doctor_assignments', 1);
        $this->assertSame($doctor->id, $visit->fresh()->currentAssignment->doctor_id);
    }

    public function test_parallel_acceptance_and_reassignment_preserve_accepted_care_and_reject_stale_replacement(): void
    {
        [$actor, $doctor, $patient] = $this->fixtures();
        $visit = Encounter::factory()->for($patient)->create();
        $assignment = DoctorAssignment::factory()->for($visit)->create(['doctor_id' => $doctor->id, 'assigned_at' => now('UTC')]);
        $other = $this->createStaffWithRole('doctor');
        $data = ['version' => 1, 'assignment_id' => $assignment->id, 'assignment_version' => 1, 'reason' => 'Synthetic visit action'];
        [$first, $second] = $this->concurrent($this->job($doctor, $data, 'accept-visit-key-0001', $visit->id, 'accept'),
            $this->job($actor, $data + ['doctor_id' => $other->id, 'consultation_type' => 'General Consultation'], 'reassign-visit-key-0001', $visit->id, 'reassign'));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertSame('accepted', $assignment->fresh()->status);
        $this->assertSame('in_progress', $visit->fresh()->status);
        $this->assertDatabaseCount('doctor_assignments', 1);
    }

    public function test_parallel_completion_and_reassignment_cannot_reopen_closed_care(): void
    {
        [$actor, $doctor, $patient] = $this->fixtures();
        $visit = Encounter::factory()->for($patient)->create();
        $assignment = DoctorAssignment::factory()->for($visit)->create(['doctor_id' => $doctor->id, 'assigned_at' => now('UTC')]);
        $visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $assignment->forceFill(['status' => 'accepted', 'accepted_at' => now('UTC'), 'version' => 2])->save();
        $other = $this->createStaffWithRole('doctor');
        $data = ['version' => 2, 'assignment_id' => $assignment->id, 'assignment_version' => 2, 'reason' => 'Synthetic visit action'];
        [$first, $second] = $this->concurrent($this->job($doctor, $data, 'complete-visit-key-0001', $visit->id, 'complete'),
            $this->job($actor, $data + ['doctor_id' => $other->id, 'consultation_type' => 'General Consultation'], 'reassign-visit-key-0001', $visit->id, 'reassign'));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertSame('closed', $visit->fresh()->status);
        $this->assertNull($visit->fresh()->currentAssignment);
        $this->assertSame('completed', $assignment->fresh()->status);
    }

    private function fixtures(): array
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real parallel visit transactions require the isolated MySQL database.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk', ['patients.view', 'appointments.view', 'encounters.view', 'encounters.view_any', 'encounters.arrive', 'encounters.assign']);
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'encounters.progress']);

        return [$this->createStaffWithRole('frontdesk'), $this->createStaffWithRole('doctor'), Patient::factory()->create()];
    }

    private function job(User $actor, array $data, string $key, int $target, string $action): array
    {
        return ['actor' => $actor->id, 'data' => $data, 'key' => $key, 'target' => $target, 'action' => $action];
    }

    private function concurrent(array $firstJob, array $secondJob): array
    {
        $first = $this->worker($firstJob, true);
        $second = $this->worker($secondJob, false);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED')), $first->getErrorOutput());
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
$target = $job['target'];
$action = $job['action'];
$arrival = $action === 'arrive';
$path = $arrival ? '/api/v1/appointments/'.$target.'/arrive' : '/api/v1/encounters'.($target ? '/'.$target.'/'.$action : '');
$method = 'POST';
$request = Illuminate\Http\Request::create($path, $method, $job['data']);
$request->headers->set('Idempotency-Key', $job['key']);
$request->setUserResolver(fn () => $actor);
$route = new Illuminate\Routing\Route($method, ($arrival ? 'api/v1/appointments/{appointment}/arrive' : 'api/v1/encounters'.($target ? '/{encounter}/'.$action : '')), fn () => null);
$route->name($arrival ? 'api.v1.appointments.arrive' : 'api.v1.encounters.'.$action);
$route->bind($request);
if ($target) { $route->setParameter($arrival ? 'appointment' : 'encounter', (string) $target); }
$request->setRouteResolver(fn () => $route);
$app->instance('request', $request);
$request->setUserResolver(fn () => $actor);
try {
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $actor, $target, $action, $job, $argv) {
        $visit = $app->make(App\Services\EncounterFlow::class)->execute(request(), $actor, $job['data']);
        if ($argv[3] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
        return (new App\Http\Resources\EncounterResource($visit))->response()->setStatusCode(in_array($action, ['store', 'arrive']) ? 201 : 200);
    });
    echo json_encode(['status' => $response->getStatusCode(), 'id' => $response->getData(true)['data']['id']])."\n";
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
}
