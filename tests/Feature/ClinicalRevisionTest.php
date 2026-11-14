<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\ClinicalRevision;
use App\Models\User;
use App\Services\ClinicalRecords;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_revision_factory_relations_and_snapshots_preserve_clinical_identity(): void
    {
        $entry = ClinicalEntry::factory()->create(['content' => 'Private retained note']);
        $record = $entry->record;
        $revision = ClinicalRevision::factory()->create(['clinical_record_id' => $record->id]);
        $this->assertTrue($revision->record->is($record));
        $this->assertTrue($revision->actor->is($record->author));
        $this->assertTrue($record->revisions->sole()->is($revision));
        $this->assertSame('Private retained note', $revision->snapshot['payload']['content']);
        $this->assertSame($record->patient_id, $revision->snapshot['patient_id']);
        $this->assertSame($record->encounter_id, $revision->snapshot['encounter_id']);
        $this->assertNull($revision->reason);
        $entry->forceFill(['content' => 'New draft value'])->save();
        $record->forceFill(['version' => 2])->save();
        ClinicalRevision::factory()->create(['clinical_record_id' => $record->id, 'version' => 2, 'operation' => 'updated']);
        $this->assertSame('Private retained note', $revision->fresh()->snapshot['payload']['content']);
        $this->assertSame('New draft value', $record->revisions()->where('version', 2)->sole()->snapshot['payload']['content']);
    }

    #[DataProvider('invalidRevisions')]
    public function test_database_rejects_revisions_with_inconsistent_identity_actor_state_or_time(string $case): void
    {
        $record = ClinicalEntry::factory()->create()->record;
        $data = ['clinical_record_id' => $record->id, 'version' => 1, 'operation' => 'created',
            'actor_id' => $record->authored_by, 'snapshot' => ClinicalRecords::snapshot($record), 'recorded_at' => now('UTC')];
        if (str_starts_with($case, 'snapshot.')) {
            $field = substr($case, 9);
            $data['snapshot'][$field] = in_array($field, ['kind', 'status'], true) ? 'invalid' : $data['snapshot'][$field] + 1;
        } else {
            $data = array_replace($data, match ($case) {
                'version' => ['version' => 2], 'actor' => ['actor_id' => User::factory()->create()->id],
                'operation' => ['operation' => 'deleted'], 'state' => ['operation' => 'signed'],
                'created_reason' => ['reason' => 'A reason cannot accompany creation'],
                'time' => ['recorded_at' => $record->recorded_at->subSecond()],
                'missing_snapshot_key' => ['snapshot' => []],
            });
        }
        $this->expectException(QueryException::class);
        ClinicalRevision::query()->forceCreate($data);
    }

    public static function invalidRevisions(): array
    {
        return array_map(fn (string $case): array => [$case], ['snapshot.id', 'snapshot.patient_id', 'snapshot.encounter_id',
            'snapshot.version', 'snapshot.kind', 'snapshot.status', 'version', 'actor', 'operation', 'state',
            'created_reason', 'time', 'missing_snapshot_key']);
    }

    #[DataProvider('immutableOperations')]
    public function test_private_revisions_cannot_be_changed_deleted_or_duplicated(string $operation): void
    {
        $revision = ClinicalRevision::factory()->create();
        $this->expectException(QueryException::class);
        match ($operation) {
            'update' => DB::table('clinical_revisions')->where('id', $revision->id)->update(['reason' => 'Tampered reason']),
            'delete' => DB::table('clinical_revisions')->where('id', $revision->id)->delete(),
            'duplicate' => ClinicalRevision::factory()->create(['clinical_record_id' => $revision->clinical_record_id]),
        };
    }

    public static function immutableOperations(): array
    {
        return [['update'], ['delete'], ['duplicate']];
    }
}
