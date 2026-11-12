<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\ClinicalRecord;
use App\Models\Prescription;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ClinicalConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_signing_blocks_a_competing_note_edit_despite_an_earlier_read_snapshot(): void
    {
        $this->requireMysql();
        $record = ClinicalEntry::factory()->create()->record;
        $this->assertSame(['saved', 'rejected'], $this->race($record, 'sign', 'edit'));
        $this->assertSame('signed', $record->fresh()->status);
        $this->assertNull($record->entry->content);
    }

    public function test_a_signature_waits_for_an_existing_content_edit_and_preserves_the_saved_values(): void
    {
        $this->requireMysql();
        $record = ClinicalEntry::factory()->create()->record;
        $this->assertSame(['saved', 'saved'], $this->race($record, 'edit', 'sign'));
        $this->assertSame('signed', $record->fresh()->status);
        $this->assertSame('Concurrent synthetic note', $record->entry->content);
    }

    public function test_signing_blocks_competing_prescription_lines(): void
    {
        $this->requireMysql();
        $record = Prescription::factory()->withItems()->create()->record;
        $this->assertSame(['saved', 'rejected'], $this->race($record, 'sign', 'insert_item'));
        $this->assertDatabaseCount('prescription_items', 1);
        $this->assertSame('signed', $record->fresh()->status);
    }

    public function test_a_signed_medication_line_cannot_be_deleted_by_a_competing_draft_editor(): void
    {
        $this->requireMysql();
        $record = Prescription::factory()->withItems()->create()->record;
        $this->assertSame(['saved', 'rejected'], $this->race($record, 'sign', 'delete_item'));
        $this->assertDatabaseCount('prescription_items', 1);
    }

    public function test_competing_corrections_cannot_branch_the_same_retained_record(): void
    {
        $this->requireMysql();
        $record = ClinicalEntry::factory()->create()->record;
        $record->forceFill(['status' => 'signed', 'version' => 2, 'finalized_by' => $record->authored_by,
            'finalized_at' => now('UTC')])->save();
        $this->assertSame(['saved', 'rejected'], $this->race($record, 'amend', 'amend'));
        $this->assertDatabaseCount('clinical_records', 2);
        $this->assertSame(1, ClinicalRecord::query()->where('amends_id', $record->id)->count());
    }

    private function requireMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real competing clinical transactions require the isolated MySQL database.');
        }
    }

    /** @return array{string, string} */
    private function race(ClinicalRecord $record, string $firstAction, string $secondAction): array
    {
        $first = $this->worker($record->id, $firstAction, true);
        $second = $this->worker($record->id, $secondAction, false);
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

    private function worker(int $id, string $action, bool $hold): Process
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
    Illuminate\Support\Facades\DB::transaction(function () use ($argv): void {
        $record = App\Models\ClinicalRecord::query()->findOrFail((int) $argv[2]);
        match ($argv[3]) {
            'sign' => $record->forceFill(['status' => 'signed', 'version' => $record->version + 1,
                'finalized_by' => $record->authored_by, 'finalized_at' => now('UTC')])->save(),
            'edit' => $record->entry->forceFill(['content' => 'Concurrent synthetic note'])->save(),
            'insert_item' => App\Models\PrescriptionItem::factory()->create(['prescription_id' => $record->id, 'line_number' => 2]),
            'delete_item' => $record->prescription->items()->firstOrFail()->delete(),
            'amend' => App\Models\ClinicalRecord::factory()->amending($record)->create(),
        };
        if ($argv[4] === 'hold') { echo "LOCKED\n"; flush(); usleep(700000); }
    });
    echo "saved\n";
} catch (Illuminate\Database\QueryException) {
    echo "rejected\n";
}
PHP;

        return new Process([PHP_BINARY, '-r', $code, base_path(), (string) $id, $action, $hold ? 'hold' : 'run'],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql'], timeout: 20);
    }

    private function workerResult(Process $process): string
    {
        $lines = explode("\n", trim($process->getOutput()));

        return end($lines);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
