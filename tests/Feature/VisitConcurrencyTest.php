<?php

namespace Tests\Feature;

use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VisitConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_assignments_cannot_create_two_current_clinicians(): void
    {
        [$visit, $doctor, $sender] = $this->fixtures();
        $this->assertSame(['saved', 'rejected'], $this->concurrent($visit, $doctor, $sender, 'assign', 'assign'));
        $this->assertDatabaseCount('doctor_assignments', 1);
        $this->assertSame(1, DoctorAssignment::current()->count());
    }

    public function test_assignment_committing_before_cancellation_prevents_visit_from_ending(): void
    {
        [$visit, $doctor, $sender] = $this->fixtures();
        $this->assertSame(['saved', 'rejected'], $this->concurrent($visit, $doctor, $sender, 'assign', 'close'));
        $this->assertSame('open', $visit->fresh()->status);
        $this->assertSame(1, DoctorAssignment::current()->count());
    }

    public function test_visit_cancellation_committing_first_prevents_a_late_assignment(): void
    {
        [$visit, $doctor, $sender] = $this->fixtures();
        $this->assertSame(['saved', 'rejected'], $this->concurrent($visit, $doctor, $sender, 'close', 'assign'));
        $this->assertSame('cancelled', $visit->fresh()->status);
        $this->assertDatabaseCount('doctor_assignments', 0);
    }

    public function test_parallel_reassignments_preserve_one_history_and_one_current_clinician(): void
    {
        [$visit, $doctor, $sender] = $this->fixtures();
        $original = DoctorAssignment::factory()->for($visit)->create(['doctor_id' => $doctor->id,
            'assigned_by' => $sender->id, 'assigned_at' => '2026-11-11 10:01:00']);
        $this->assertSame(['saved', 'rejected'], $this->concurrent($visit, $doctor, $sender, 'reassign', 'reassign', $original->id));
        $this->assertDatabaseCount('doctor_assignments', 2);
        $this->assertSame('reassigned', $original->fresh()->status);
        $this->assertSame(1, DoctorAssignment::current()->count());
        $this->assertNotSame($original->id, $visit->fresh()->currentAssignment->id);
        $currentId = $visit->fresh()->currentAssignment->id;
        $this->assertSame(['saved', 'rejected'], $this->concurrent($visit, $doctor, $sender, 'end', 'assign', $currentId));
        $this->assertDatabaseCount('doctor_assignments', 2);
        $this->assertSame(0, DoctorAssignment::current()->count());
    }

    /** @return array{Encounter, User, User} */
    private function fixtures(): array
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real parallel visit transactions require the isolated MySQL database.');
        }

        return [Encounter::factory()->create(['opened_at' => '2026-11-11 10:00:00']),
            User::factory()->create(), User::factory()->create()];
    }

    /** @return list<string> */
    private function concurrent(Encounter $visit, User $doctor, User $sender, string $firstAction, string $secondAction, int $original = 0): array
    {
        $first = $this->worker($visit, $doctor, $sender, $firstAction, true, $original);
        $second = $this->worker($visit, $doctor, $sender, $secondAction, false, $original);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'LOCKED')), $first->getErrorOutput());
            $second->start();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

            return [$this->outcome($first), $this->outcome($second)];
        } finally {
            $first->stop(0);
            $second->stop(0);
        }
    }

    private function worker(Encounter $visit, User $doctor, User $sender, string $action, bool $hold, int $original): Process
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
try {
    Illuminate\Support\Facades\DB::transaction(function () use ($argv) {
        $db = Illuminate\Support\Facades\DB::class;
        if ($argv[6] !== 'hold') {
            $db::table('encounters')->where('id', (int) $argv[2])->first();
        }
        if ($argv[5] === 'close') {
            $db::table('encounters')->where('id', (int) $argv[2])->update([
                'status' => 'cancelled', 'closed_at' => '2026-11-11 10:03:00', 'version' => 2]);
        } elseif ($argv[5] === 'end') {
            $db::table('doctor_assignments')->where('id', (int) $argv[7])->update([
                'status' => 'cancelled', 'ended_at' => '2026-11-11 10:03:00',
                'ended_by' => (int) $argv[4], 'end_reason' => 'Synthetic cancellation', 'version' => 2]);
        } else {
            if ($argv[5] === 'reassign') {
                $db::table('doctor_assignments')->where('id', (int) $argv[7])->update([
                    'status' => 'reassigned', 'ended_at' => '2026-11-11 10:02:00',
                    'ended_by' => (int) $argv[4], 'end_reason' => 'Synthetic handover', 'version' => 2]);
            }
            $db::table('doctor_assignments')->insert([
                'encounter_id' => (int) $argv[2], 'doctor_id' => (int) $argv[3], 'assigned_by' => (int) $argv[4],
                'assigned_at' => '2026-11-11 10:02:00', 'status' => 'assigned', 'version' => 1,
                'created_at' => '2026-11-11 10:02:00', 'updated_at' => '2026-11-11 10:02:00']);
        }
        if ($argv[6] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
    });
    echo json_encode(['outcome' => 'saved'])."\n";
} catch (Illuminate\Database\QueryException $exception) {
    echo json_encode(['outcome' => 'rejected', 'sqlstate' => $exception->getCode()])."\n";
}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), (string) $visit->id, (string) $doctor->id,
            (string) $sender->id, $action, $hold ? 'hold' : 'run', (string) $original], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql'], timeout: 20);
    }

    private function outcome(Process $process): string
    {
        $lines = explode("\n", trim($process->getOutput()));
        $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        if ($result['outcome'] === 'rejected') {
            $this->assertContains((string) $result['sqlstate'], ['23000', '45000'],
                'The competing write must fail an integrity guard, rather than a lock timeout or deadlock.');
        }

        return $result['outcome'];
    }
}
