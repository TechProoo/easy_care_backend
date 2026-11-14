<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\ClinicalRecord;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatientClinicalHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Patient $patient;
    private Encounter $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'encounters.view_any',
            'clinical.view', 'prescriptions.view', 'clinical.history.view']);
        $this->actor = $this->createStaffWithRole('doctor');
        $this->patient = Patient::factory()->create();
        $this->visit = Encounter::factory()->for($this->patient)->create(['opened_at' => now('UTC')->subHour()]);
        $this->actingAs($this->actor);
    }

    public function test_history_combines_finalized_saved_records_and_visits_without_copying_patients_or_exposing_drafts(): void
    {
        $note = $this->note($this->visit);
        $second = Encounter::factory()->for($this->patient)->create(['opened_at' => now('UTC')->subMinutes(30)]);
        $order = Prescription::factory()->withItems()->create(['clinical_record_id' => ClinicalRecord::factory()->forEncounter($second)->create(['kind' => 'prescription'])->id])->record;
        $this->sign($order);
        $this->note($this->visit, false);
        $other = $this->note(Encounter::factory()->create(['opened_at' => now('UTC')->subHour()]));
        $tables = ['patients', 'patient_identifiers', 'encounters', 'clinical_records', 'clinical_revisions', 'prescriptions', 'prescription_items'];
        $response = $this->assertTablesUnchanged($tables, fn () => $this->getJson($this->path()))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$note->id, $order->id], $ids);
        $this->assertNotContains($other->id, $ids);
        foreach ($response->json('data') as $row) {
            $this->assertSame($this->patient->id, $row['patient_id']);
            $this->assertSame($row['encounter_id'], $row['encounter']['id']);
            $this->assertArrayNotHasKey('patient', $row);
            $this->assertArrayNotHasKey('email', $row);
        }
        $this->assertDatabaseCount('clinical_revisions', 0);
        $this->assertAuditEventRecorded('patient.history_viewed', $this->actor->id, 'patient', $this->patient->id);
        $this->assertAuditExcludes(['Private history note sentinel', 'Synthetic test medication']);
        $this->getJson($this->path().'?kind=prescription')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active_order', true);
        $this->getJson($this->path().'?encounter_id='.$this->visit->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $note->id);
        $this->getJson($this->path().'?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_history_returns_current_heads_and_optionally_retained_predecessors_but_never_pending_amendments(): void
    {
        $original = $this->note($this->visit);
        $replacement = ClinicalRecord::factory()->amending($original)->create(['authored_by' => $this->actor->id]);
        ClinicalEntry::factory()->create(['clinical_record_id' => $replacement->id, 'fields' => new \stdClass, 'content' => 'Corrected history note']);
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $original->id);
        $this->sign($replacement);
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $replacement->id);
        $this->getJson($this->path().'?include_superseded=1')->assertOk()->assertJsonCount(2, 'data');
        $voided = ClinicalRecord::factory()->amending($replacement)->create(['authored_by' => $this->actor->id]);
        $voided->forceFill(['status' => 'voided', 'version' => 2, 'finalized_by' => $this->actor->id,
            'finalized_at' => now('UTC'), 'void_reason' => 'Retained history retraction'])->save();
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'voided')->assertJsonPath('data.0.payload', []);
        $this->getJson($this->path().'?status=signed')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path().'?include_superseded=1&status=signed')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_history_permission_does_not_expand_clinician_encounter_scope(): void
    {
        $own = $this->note($this->visit);
        $otherVisit = Encounter::factory()->for($this->patient)->create();
        $this->note($otherVisit);
        $this->revoke('encounters.view_any');
        DoctorAssignment::factory()->for($this->visit)->create(['doctor_id' => $this->actor->id, 'assigned_at' => now('UTC')]);
        DoctorAssignment::factory()->for($otherVisit)->create(['assigned_at' => now('UTC')]);
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson($this->path().'?encounter_id='.$otherVisit->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_note_and_prescription_visibility_are_independent_and_revocations_apply_on_the_next_request(): void
    {
        $this->note($this->visit);
        $order = Prescription::factory()->withItems()->create(['clinical_record_id' => ClinicalRecord::factory()->forEncounter($this->visit)->create(['kind' => 'prescription'])->id])->record;
        $this->sign($order);
        $this->revoke('prescriptions.view');
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'entry');
        $this->getJson($this->path().'?kind=prescription')->assertForbidden();
        $this->grantRolePermissions('doctor', ['prescriptions.view']);
        $this->revoke('clinical.view');
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'prescription');
        $this->getJson($this->path().'?kind=entry')->assertForbidden();
        $this->revoke('clinical.history.view');
        $this->getJson($this->path())->assertForbidden();
    }

    public function test_restricted_patient_missing_patient_and_other_patient_filters_never_disclose_records(): void
    {
        $this->note($this->visit);
        $other = $this->note(Encounter::factory()->create());
        $this->getJson($this->path().'?encounter_id='.$other->encounter_id)->assertOk()->assertJsonCount(0, 'data');
        $this->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $hidden = $this->getJson($this->path())->assertNotFound()->json();
        $missing = $this->getJson('/api/v1/patients/99999999/history')->assertNotFound()->json();
        $this->assertSame($missing, $hidden);
        $this->assertAuditExcludes(['Private history note sentinel']);
    }

    public function test_no_new_permissions_are_granted_by_seeding_and_history_cannot_write_patient_or_clinical_records(): void
    {
        $this->flushSession();
        $this->actingAs($this->createStaffWithRole('admin'));
        $this->getJson($this->path())->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->actor);
        $this->postJson($this->path())->assertMethodNotAllowed();
        $this->getJson($this->path().'?kind=invalid&status=draft&page=0&per_page=101&include_superseded=invalid')->assertUnprocessable();
        $this->assertDatabaseCount('permissions', 41);
        $admin = Role::where('code', 'admin')->sole();
        $newPermissions = Permission::where('code', 'like', 'prescriptions.%')->orWhere('code', 'clinical.history.view')->pluck('id');
        $this->assertFalse($admin->permissions()->whereIn('permissions.id', $newPermissions)->exists());
        $this->assertDatabaseCount('clinical_records', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
    }

    public function test_closed_encounter_history_remains_readable_without_enabling_mutations(): void
    {
        $note = $this->note($this->visit);
        $this->visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $this->visit->forceFill(['status' => 'closed', 'version' => 3, 'closed_at' => now('UTC')])->save();
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $note->id)
            ->assertJsonPath('data.0.encounter.status', 'closed');
    }

    private function note(Encounter $visit, bool $signed = true): ClinicalRecord
    {
        $record = ClinicalEntry::factory()->create(['clinical_record_id' => ClinicalRecord::factory()->forEncounter($visit)
            ->create(['authored_by' => $this->actor->id])->id, 'fields' => new \stdClass, 'content' => 'Private history note sentinel'])->record;
        if ($signed) {
            $this->sign($record);
        }

        return $record;
    }

    private function sign(ClinicalRecord $record): void
    {
        $record->forceFill(['status' => 'signed', 'version' => 2, 'finalized_by' => $record->authored_by, 'finalized_at' => now('UTC')])->save();
    }

    private function revoke(string $permission): void
    {
        Role::where('code', 'doctor')->sole()->permissions()->detach(Permission::where('code', $permission)->sole()->id);
    }

    private function path(): string
    {
        return '/api/v1/patients/'.$this->patient->id.'/history';
    }
}
