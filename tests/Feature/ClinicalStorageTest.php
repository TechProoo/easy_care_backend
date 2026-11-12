<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplate;
use App\Models\ClinicalTemplateVersion;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalStorageTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['clinical_templates', 'clinical_template_versions', 'clinical_records', 'vital_signs',
        'clinical_entries', 'diagnoses', 'clinical_procedures', 'prescriptions', 'prescription_items'];

    #[DataProvider('payloadModels')]
    public function test_each_payload_belongs_to_exactly_one_patient_encounter_and_author(string $model, string $kind): void
    {
        $visit = Encounter::factory()->create();
        $record = ClinicalRecord::factory()->forEncounter($visit)->create(['kind' => $kind]);
        $payload = $model::factory()->create(['clinical_record_id' => $record->id]);
        $this->assertTrue($payload->record->is($record));
        $this->assertTrue($record->patient->is($visit->patient));
        $this->assertTrue($record->encounter->is($visit));
        $this->assertTrue($visit->clinicalRecords->sole()->is($record));
        $this->assertTrue($visit->patient->clinicalRecords->sole()->is($record));
        $this->assertTrue($record->author->authoredClinicalRecords->sole()->is($record));
        $relation = ['vitals' => 'vitals', 'entry' => 'entry', 'diagnosis' => 'diagnosis', 'procedure' => 'procedure', 'prescription' => 'prescription'][$kind];
        $this->assertTrue($record->{$relation}->is($payload));
        $this->assertSame('draft', $record->status);
        $this->assertSame(1, $record->version);
        $this->assertNull($record->finalizedBy);
    }

    public static function payloadModels(): array
    {
        return ['vitals' => [\App\Models\VitalSign::class, 'vitals'], 'entry' => [ClinicalEntry::class, 'entry'],
            'diagnosis' => [Diagnosis::class, 'diagnosis'], 'procedure' => [ClinicalProcedure::class, 'procedure'],
            'prescription' => [Prescription::class, 'prescription']];
    }

    public function test_templates_are_versioned_and_old_entries_keep_their_exact_definition(): void
    {
        $version = ClinicalTemplateVersion::factory()->create();
        $note = ClinicalEntry::factory()->create(['clinical_template_version_id' => $version->id]);
        $new = ClinicalTemplateVersion::factory()->create(['clinical_template_id' => $version->clinical_template_id,
            'version' => 2, 'fields' => ['different' => ['type' => 'text']]]);
        $version->template->forceFill(['name' => 'Renamed template', 'is_active' => false])->save();
        $this->assertTrue($note->fresh()->templateVersion->is($version));
        $this->assertSame(['summary' => ['type' => 'text', 'label' => 'Summary']], $note->templateVersion->fields);
        $this->assertSame([1, 2], $version->template->versions->pluck('version')->all());
        $this->assertTrue($version->entries->sole()->is($note));
        $this->assertNotNull($new->publishedBy);
        $this->assertNotNull($version->template->createdBy);
        $this->reject(fn () => $version->forceFill(['title' => 'Overwrite'])->save());
        $this->reject(fn () => $version->delete());
        $this->reject(fn () => ClinicalTemplateVersion::factory()->create(['clinical_template_id' => $version->clinical_template_id]));
        $this->reject(fn () => $version->template->forceFill(['code' => 'replacement'])->save());
        $this->reject(fn () => $version->template->delete());
    }

    public function test_amendments_keep_original_signed_values_and_authorship_without_branching(): void
    {
        $original = Diagnosis::factory()->create();
        $record = $original->record;
        $this->sign($record);
        $editor = User::factory()->create();
        $amendment = ClinicalRecord::factory()->amending($record)->create(['authored_by' => $editor->id]);
        Diagnosis::factory()->create(['clinical_record_id' => $amendment->id, 'description' => 'Corrected synthetic description']);
        $this->assertSame([$record->id], ClinicalRecord::query()->current()->pluck('id')->all());
        $this->sign($amendment);
        $this->assertSame([$amendment->id], ClinicalRecord::query()->current()->pluck('id')->all());
        $this->assertTrue($record->fresh()->amendment->is($amendment));
        $this->assertTrue($amendment->amendedRecord->is($record));
        $this->assertNotSame($record->authored_by, $amendment->authored_by);
        $this->assertTrue($amendment->finalizedBy->finalizedClinicalRecords->sole()->is($amendment));
        $this->assertSame('Synthetic test diagnosis', $original->fresh()->description);
        $this->reject(fn () => ClinicalRecord::factory()->amending($record)->create());
        $this->reject(fn () => $original->forceFill(['description' => 'Overwrite'])->save());
        $this->reject(fn () => $record->forceFill(['status' => 'draft', 'version' => 3, 'finalized_at' => null, 'finalized_by' => null])->save());
        $this->reject(fn () => $record->delete());
    }

    public function test_voiding_keeps_a_reason_and_can_be_corrected_through_another_retained_record(): void
    {
        $note = ClinicalEntry::factory()->create();
        $record = $note->record;
        $this->sign($record);
        $void = ClinicalRecord::factory()->amending($record)->create();
        $void->forceFill(['status' => 'voided', 'version' => 2, 'finalized_at' => now('UTC'),
            'finalized_by' => $void->authored_by, 'void_reason' => 'Synthetic record entered in error'])->save();
        $this->assertSame([$void->id], ClinicalRecord::query()->current()->pluck('id')->all());
        $this->assertSame('signed', $record->fresh()->status);
        $correction = ClinicalRecord::factory()->amending($void)->create();
        ClinicalEntry::factory()->create(['clinical_record_id' => $correction->id]);
        $this->sign($correction);
        $this->assertSame([$correction->id], ClinicalRecord::query()->current()->pluck('id')->all());
        $this->reject(fn () => $void->forceFill(['version' => 3, 'void_reason' => 'Rewritten history'])->save());
    }

    #[DataProvider('payloadModels')]
    public function test_signing_freezes_every_typed_payload_and_blocks_later_inserts(string $model, string $kind): void
    {
        $payload = $kind === 'prescription' ? $model::factory()->withItems()->create() : $model::factory()->create();
        $record = $payload->record;
        $this->sign($record);
        $this->reject(fn () => DB::table($payload->getTable())->where('clinical_record_id', $record->id)->update(['clinical_record_id' => $record->id]));
        $this->reject(fn () => $payload->delete());
        $empty = ClinicalRecord::factory()->create(['kind' => $kind]);
        $empty->forceFill(['status' => 'voided', 'version' => 2, 'finalized_at' => now('UTC'),
            'finalized_by' => $empty->authored_by, 'void_reason' => 'Synthetic unused draft'])->save();
        $this->reject(fn () => $model::factory()->create(['clinical_record_id' => $empty->id]));
    }

    #[DataProvider('payloadModels')]
    public function test_payload_type_and_identity_cannot_be_reassigned(string $model, string $kind): void
    {
        $wrong = ClinicalRecord::factory()->create(['kind' => $kind === 'entry' ? 'diagnosis' : 'entry']);
        $this->reject(fn () => $model::factory()->create(['clinical_record_id' => $wrong->id]));
        $payload = $model::factory()->create();
        $other = ClinicalRecord::factory()->create(['kind' => $kind]);
        $this->reject(fn () => DB::table($payload->getTable())->where('clinical_record_id', $payload->getKey())
            ->update(['clinical_record_id' => $other->id]));
    }

    #[DataProvider('invalidHeaders')]
    public function test_raw_database_writes_reject_invalid_new_clinical_records(array $changes): void
    {
        $row = ClinicalRecord::factory()->make()->getAttributes();
        $this->reject(fn () => DB::table('clinical_records')->insert(array_replace($row, $changes)));
    }

    public static function invalidHeaders(): array
    {
        return ['kind' => [['kind' => 'unknown']], 'kind case' => [['kind' => 'ENTRY']], 'status' => [['status' => 'SIGNED']],
            'starts signed' => [['status' => 'signed']], 'starts voided' => [['status' => 'voided']],
            'version' => [['version' => 2]], 'zero version' => [['version' => 0]],
            'missing encounter' => [['encounter_id' => 99999999]], 'missing patient' => [['patient_id' => 99999999]],
            'missing author' => [['authored_by' => 99999999]], 'bad time' => [['recorded_at' => 'not-a-date']],
            'before visit' => [['recorded_at' => '1900-01-01 00:00:00']], 'reason without parent' => [['amendment_reason' => 'Unexpected reason']],
            'parent without reason' => [['amends_id' => 99999999]]];
    }

    #[DataProvider('protectedHeaderFields')]
    public function test_clinical_identity_and_authorship_never_change(string $field): void
    {
        $this->freezeTime();
        $openedAt = now('UTC')->subDay();
        $visit = Encounter::factory()->create(['opened_at' => $openedAt]);
        $record = ClinicalRecord::factory()->forEncounter($visit)->create(['recorded_at' => now('UTC')]);
        $otherVisit = Encounter::factory()->for($visit->patient)->create(['opened_at' => $openedAt]);
        $changes = ['version' => 2, $field => match ($field) {
            'id', 'amends_id' => 99999999,
            'patient_id' => $otherVisit->patient_id,
            'encounter_id' => $otherVisit->id,
            'authored_by' => User::factory()->create()->id,
            'kind' => 'diagnosis', 'amendment_reason' => 'Unexpected amendment',
            'recorded_at', 'created_at' => now('UTC')->addDay()->format('Y-m-d H:i:s.u'),
        }];
        if ($field === 'patient_id') {
            $newIdentity = Encounter::factory()->create(['opened_at' => $openedAt]);
            $changes += ['encounter_id' => $newIdentity->id];
            $changes['patient_id'] = $newIdentity->patient_id;
        }
        if ($field === 'amends_id') {
            $predecessor = ClinicalRecord::factory()->forEncounter($visit)->create();
            ClinicalEntry::factory()->create(['clinical_record_id' => $predecessor->id]);
            $this->sign($predecessor);
            $changes['amends_id'] = $predecessor->id;
            $changes['amendment_reason'] = 'Synthetic correction';
        }
        $this->reject(fn () => DB::table('clinical_records')->where('id', $record->id)->update($changes));
    }

    public static function protectedHeaderFields(): array
    {
        return array_combine($fields = ['id', 'patient_id', 'encounter_id', 'authored_by', 'kind', 'amends_id',
            'amendment_reason', 'recorded_at', 'created_at'], array_map(fn (string $field): array => [$field], $fields));
    }

    public function test_draft_versions_signing_content_and_finalization_times_are_checked(): void
    {
        $record = ClinicalRecord::factory()->create();
        $this->reject(fn () => DB::table('clinical_records')->where('id', $record->id)->update(['version' => 1]));
        $this->reject(fn () => $record->forceFill(['version' => 3])->save());
        $record->refresh();
        $this->reject(fn () => $this->sign($record));
        ClinicalEntry::factory()->create(['clinical_record_id' => $record->id]);
        $record->refresh();
        $this->reject(fn () => $record->forceFill(['version' => 2, 'status' => 'signed', 'finalized_by' => $record->authored_by,
            'finalized_at' => $record->recorded_at->subSecond()])->save());
        $record->refresh();
        $this->reject(fn () => $record->forceFill(['version' => 2, 'status' => 'voided', 'finalized_by' => $record->authored_by,
            'finalized_at' => now('UTC'), 'void_reason' => ' '])->save());
        $record->refresh();
        $this->sign($record);
        $this->assertSame('signed', $record->fresh()->status);
        $this->assertSame(2, $record->version);
    }

    public function test_cross_patient_cross_visit_and_cross_kind_amendments_are_rejected(): void
    {
        $original = Diagnosis::factory()->create()->record;
        $this->sign($original);
        $other = Encounter::factory()->for($original->patient)->create();
        $this->reject(fn () => ClinicalRecord::factory()->amending($original)->create(['encounter_id' => $other->id]));
        $this->reject(fn () => ClinicalRecord::factory()->amending($original)->create(['patient_id' => Patient::factory()->create()->id]));
        $this->reject(fn () => ClinicalRecord::factory()->amending($original)->create(['kind' => 'entry']));
        $this->reject(fn () => ClinicalRecord::factory()->amending($original)->create(['amendment_reason' => ' ']));
        $draft = ClinicalRecord::factory()->create();
        $this->reject(fn () => ClinicalRecord::factory()->amending($draft)->create(['recorded_at' => now('UTC')]));
        $this->reject(fn () => ClinicalRecord::factory()->create(['encounter_id' => $other->id, 'patient_id' => Patient::factory()->create()->id]));
    }

    public function test_closed_visits_only_accept_linked_corrections_and_restriction_does_not_move_old_records(): void
    {
        $payload = Diagnosis::factory()->create();
        $record = $payload->record;
        $this->sign($record);
        $visit = $record->encounter;
        $visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $visit->forceFill(['status' => 'closed', 'closed_at' => now('UTC'), 'version' => 3])->save();
        $this->reject(fn () => ClinicalRecord::factory()->forEncounter($visit)->create());
        $amend = ClinicalRecord::factory()->amending($record)->create();
        Diagnosis::factory()->create(['clinical_record_id' => $amend->id]);
        $this->sign($amend);
        $record->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->reject(fn () => ClinicalRecord::factory()->amending($amend)->create());
        $this->assertSame($visit->patient_id, $payload->fresh()->record->patient_id);
        $this->assertSame(2, $record->patient->clinicalRecords()->count());
        $cancelled = Encounter::factory()->create();
        $cancelled->forceFill(['status' => 'cancelled', 'closed_at' => now('UTC'), 'version' => 2])->save();
        $this->reject(fn () => ClinicalRecord::factory()->forEncounter($cancelled)->create());
    }

    public function test_related_notes_are_scoped_to_the_same_patient_and_encounter(): void
    {
        $procedure = ClinicalProcedure::factory()->create();
        $record = ClinicalRecord::factory()->forEncounter($procedure->record->encounter)->create();
        $entry = ClinicalEntry::factory()->create(['clinical_record_id' => $record->id, 'related_record_id' => $procedure->getKey()]);
        $this->assertTrue($entry->relatedRecord->is($procedure->record));
        $otherVisit = Encounter::factory()->for($record->patient)->create();
        $other = ClinicalRecord::factory()->forEncounter($otherVisit)->create();
        $this->reject(fn () => $entry->forceFill(['related_record_id' => $other->id])->save());
        $this->reject(fn () => $entry->forceFill(['related_record_id' => $record->id])->save());
    }

    public function test_an_independently_approved_merge_retains_clinical_ownership_on_the_original_identity(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk', ['patients.view', 'patients.merges.request', 'patients.merges.approve']);
        $requester = $this->createStaffWithRole('frontdesk');
        $reviewer = $this->createStaffWithRole('frontdesk');
        $source = Patient::factory()->create();
        $target = Patient::factory()->create();
        $visit = Encounter::factory()->for($source)->create();
        $record = ClinicalRecord::factory()->forEncounter($visit)->create();
        ClinicalEntry::factory()->create(['clinical_record_id' => $record->id]);
        $this->sign($record);
        $visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $visit->forceFill(['status' => 'closed', 'closed_at' => now('UTC'), 'version' => 3])->save();
        $this->actingAs($requester);
        $data = ['version' => 1, 'reason' => 'Synthetic duplicate review', 'current_password' => self::STAFF_TEST_PASSWORD];
        $proposal = $this->postCommand('/api/v1/patients/'.$source->id.'/merge-requests', $data + [
            'target_patient_id' => $target->id, 'target_version' => 1])->assertCreated()->json('data.record_id');
        $this->flushSession();
        $this->actingAs($reviewer);
        $this->assertTablesUnchanged(self::TABLES, fn () => $this->postCommand('/api/v1/patients/'.$source->id.'/merge-requests/'.$proposal.'/approve',
            array_replace($data, ['version' => 2, 'request_version' => 1]))->assertOk());
        $this->assertSame('merged', $source->fresh()->access_status);
        $this->assertSame($source->id, $record->fresh()->patient_id);
        $this->assertSame($visit->id, $record->encounter_id);
        $this->assertSame(0, $target->clinicalRecords()->count());
        $this->reject(fn () => ClinicalRecord::factory()->amending($record)->create());
    }

    public function test_prescriptions_keep_ordered_medication_snapshots_and_freeze_all_lines_when_signed(): void
    {
        $prescription = Prescription::factory()->withItems(2)->create();
        $this->assertSame([1, 2], $prescription->items->pluck('line_number')->all());
        $line = $prescription->items->first();
        $this->assertTrue($line->prescription->is($prescription));
        $line->forceFill(['dose' => 'Updated synthetic instruction', 'quantity' => '1.125', 'quantity_unit' => 'tablets'])->save();
        $this->assertSame('1.125', $line->fresh()->quantity);
        $this->reject(fn () => PrescriptionItem::factory()->for($prescription)->create());
        $other = Prescription::factory()->create();
        $this->reject(fn () => $line->forceFill(['prescription_id' => $other->getKey()])->save());
        $line->refresh();
        $prescription->items()->where('line_number', 2)->firstOrFail()->delete();
        $this->sign($prescription->record);
        $this->reject(fn () => $line->forceFill(['dose' => 'Overwrite signed instructions'])->save());
        $this->reject(fn () => $line->delete());
        $this->reject(fn () => PrescriptionItem::factory()->for($prescription)->create(['line_number' => 3]));
        $this->reject(fn () => $this->sign($other->record));
        $this->assertSame('Updated synthetic instruction', $line->fresh()->dose);
    }

    public function test_vitals_keep_explicit_units_precise_values_and_derive_bmi(): void
    {
        $vitals = \App\Models\VitalSign::factory()->create(['observed_at' => '2026-11-12 10:00:00.123456',
            'blood_glucose' => '5.125', 'blood_glucose_unit' => 'mmol/L', 'spo2_percent' => '98.50']);
        $vitals->refresh();
        $this->assertSame('123456', $vitals->observed_at->format('u'));
        $this->assertSame('5.125', $vitals->blood_glucose);
        $this->assertSame('mmol/L', $vitals->blood_glucose_unit);
        $this->assertSame('98.50', $vitals->spo2_percent);
        $this->assertSame(24.22, $vitals->bodyMassIndex());
        $vitals->forceFill(['weight_kg' => null])->save();
        $this->assertNull($vitals->bodyMassIndex());
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_structural_values_are_rejected_by_the_database(string $model, array $changes): void
    {
        $this->reject(function () use ($model, $changes): void {
            $row = $model::factory()->make()->getAttributes();
            DB::table((new $model)->getTable())->insert(array_replace($row, $changes));
        });
    }

    public static function invalidPayloads(): array
    {
        $v = \App\Models\VitalSign::class;
        return ['half blood pressure' => [$v, ['diastolic_mmhg' => null]], 'zero height' => [$v, ['height_m' => 0]],
            'negative weight' => [$v, ['weight_kg' => -1]], 'glucose missing units' => [$v, ['blood_glucose' => 5]],
            'units without glucose' => [$v, ['blood_glucose_unit' => 'mg/dL']], 'unknown glucose unit' => [$v, ['blood_glucose' => 5, 'blood_glucose_unit' => 'unknown']],
            'invalid oxygen' => [$v, ['spo2_percent' => 101]], 'invalid observed timestamp' => [$v, ['observed_at' => 'not-a-date']],
            'blank diagnosis' => [Diagnosis::class, ['description' => ' ']], 'unversioned code' => [Diagnosis::class, ['code' => 'TEST']],
            'blank procedure' => [ClinicalProcedure::class, ['name' => ' ']], 'negative price' => [ClinicalProcedure::class, ['quoted_amount' => -1, 'currency' => 'NGN']],
            'price missing currency' => [ClinicalProcedure::class, ['quoted_amount' => 1]], 'performer missing time' => [ClinicalProcedure::class, ['performed_by' => 99999999]],
            'blank currency' => [ClinicalProcedure::class, ['quoted_amount' => 1, 'currency' => '   ']],
            'currency case' => [ClinicalProcedure::class, ['quoted_amount' => 1, 'currency' => 'ngn']],
            'entry array' => [ClinicalEntry::class, ['fields' => ['not', 'object']]], 'blank section' => [ClinicalEntry::class, ['section' => ' ']],
            'unknown medication state' => [Prescription::class, ['order_status' => 'unknown']],
            'case medication state' => [Prescription::class, ['order_status' => 'ACTIVE']],
            'blank medication' => [PrescriptionItem::class, ['medication_name' => ' ']], 'zero line' => [PrescriptionItem::class, ['line_number' => 0]],
            'duration without unit' => [PrescriptionItem::class, ['duration_unit' => null]], 'negative quantity' => [PrescriptionItem::class, ['quantity' => -1, 'quantity_unit' => 'tablets']],
            'quantity without unit' => [PrescriptionItem::class, ['quantity' => 1]], 'zero template version' => [ClinicalTemplateVersion::class, ['version' => 0]],
            'array template schema' => [ClinicalTemplateVersion::class, ['fields' => []]], 'blank template code' => [ClinicalTemplate::class, ['code' => ' ']]];
    }

    private function sign(ClinicalRecord $record): void
    {
        $record->forceFill(['status' => 'signed', 'version' => $record->version + 1,
            'finalized_by' => $record->authored_by, 'finalized_at' => now('UTC')])->save();
    }

    private function reject(callable $operation): void
    {
        try {
            $this->assertTablesUnchanged(self::TABLES, fn () => DB::transaction($operation));
            $this->fail('The database must reject the invalid clinical write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
