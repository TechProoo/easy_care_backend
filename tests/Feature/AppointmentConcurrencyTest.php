<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentBookings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AppointmentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_same_key_confirms_one_booking_history_and_command(): void
    {
        [$actor, $doctor] = $this->fixtures();
        $job = $this->job($actor, $this->data($doctor), 'same-booking-key-0001');
        [$first, $second] = $this->concurrent($job, $job);
        $this->assertSame([201, 200], [$first['status'], $second['status']]);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertAuditEventRecorded('appointment.created', $actor->id, 'appointment', $first['id']);
    }

    public function test_two_staff_cannot_book_the_same_clinician_interval_with_distinct_keys(): void
    {
        [$actor, $doctor] = $this->fixtures();
        $other = $this->createStaffWithRole('frontdesk');
        [$first, $second] = $this->concurrent($this->job($actor, $this->data($doctor), 'first-booking-key-0001'),
            $this->job($other, $this->data($doctor), 'other-booking-key-0001'));
        $this->assertSame([201, 409], [$first['status'], $second['status']]);
        $this->assertSame('appointment_conflict', $second['code']);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_parallel_reschedules_to_one_free_interval_preserve_the_losing_original_slot(): void
    {
        [$actor, $doctor] = $this->fixtures();
        $bookings = app(AppointmentBookings::class);
        $one = $bookings->create($actor, $this->data($doctor));
        $two = $bookings->create($actor, $this->data($doctor, ['starts_at' => '2026-11-20T09:00:00Z', 'ends_at' => '2026-11-20T09:30:00Z']));
        $change = ['version' => 1, 'reason' => 'Synthetic reschedule', 'starts_at' => '2026-11-20T10:00:00Z', 'ends_at' => '2026-11-20T10:30:00Z'];
        [$first, $second] = $this->concurrent($this->job($actor, $change, 'first-reschedule-0001', $one->id, 'update'),
            $this->job($actor, $change, 'other-reschedule-0001', $two->id, 'update'));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('appointment_conflict', $second['code']);
        $this->assertSame('2026-11-20 09:00:00', $two->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $two->fresh()->version);
        $this->assertDatabaseCount('appointment_revisions', 3);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_parallel_cancellation_and_edit_reject_stale_state_without_reopening(): void
    {
        [$actor, $doctor] = $this->fixtures();
        $booking = app(AppointmentBookings::class)->create($actor, $this->data($doctor));
        [$first, $second] = $this->concurrent($this->job($actor, ['version' => 1, 'reason' => 'Synthetic cancellation'], 'cancel-booking-key-0001', $booking->id, 'cancel'),
            $this->job($actor, ['version' => 1, 'reason' => 'Synthetic stale edit', 'first_name' => 'Stale'], 'edit-booking-key-0001', $booking->id, 'update'));
        $this->assertSame([200, 409], [$first['status'], $second['status']]);
        $this->assertSame('version_conflict', $second['code']);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(2, $booking->fresh()->version);
        $this->assertDatabaseCount('appointment_revisions', 2);
    }

    /** @return array{User, User} */
    private function fixtures(): array
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real parallel booking transactions require the isolated MySQL database.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk', ['appointments.view', 'appointments.create', 'appointments.update', 'patients.view']);

        return [$this->createStaffWithRole('frontdesk'), $this->createStaffWithRole('doctor')];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function data(User $doctor, array $changes = []): array
    {
        return array_replace(['first_name' => 'Concurrent', 'last_name' => 'Caller', 'phone' => '+2348012345678',
            'starts_at' => '2026-11-20T08:00:00Z', 'ends_at' => '2026-11-20T08:30:00Z',
            'booking_channel' => 'Voice Call', 'visit_type' => 'General Consultation', 'doctor_id' => $doctor->id], $changes);
    }

    private function job(User $actor, array $data, string $key, int $target = 0, string $action = 'store'): array
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
$path = '/api/v1/appointments'.($target ? '/'.$target : '').(in_array($action, ['store', 'update']) ? '' : '/'.$action);
$method = $action === 'update' ? 'PATCH' : 'POST';
$request = Illuminate\Http\Request::create($path, $method, $job['data']);
$request->headers->set('Idempotency-Key', $job['key']);
$request->setUserResolver(fn () => $actor);
$route = new Illuminate\Routing\Route($method, 'api/v1/appointments'.($target ? '/{appointment}' : ''), fn () => null);
$route->name('api.v1.appointments.'.$action);
$route->bind($request);
if ($target) { $route->setParameter('appointment', (string) $target); }
$request->setRouteResolver(fn () => $route);
$app->instance('request', $request);
$request->setUserResolver(fn () => $actor);
try {
    $response = $app->make(App\Services\IdempotentCommands::class)->run($request, function () use ($app, $actor, $target, $action, $job, $argv) {
        $bookings = $app->make(App\Services\AppointmentBookings::class);
        $booking = $target ? $bookings->update($actor, $target, $job['data'], $action) : $bookings->create($actor, $job['data']);
        if ($argv[3] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
        return (new App\Http\Resources\AppointmentResource($booking))->response()->setStatusCode($target ? 200 : 201);
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
