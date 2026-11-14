<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplateVersion;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ClinicalApiTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $other;

    private Encounter $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'encounters.view_any',
            'clinical.view', 'clinical.write', 'clinical.sign', 'clinical.amend', 'clinical.void', 'clinical.review']);
        $this->author = $this->createStaffWithRole('doctor', ['first_name' => 'Clinical Author Sentinel', 'email' => 'private.clinical@example.test']);
        $this->other = $this->createStaffWithRole('doctor');
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHour()]);
        $this->actingAs($this->author);
    }

    #[DataProvider('kinds')]
    public function test_create_edit_sign_refresh_and_private_review_keep_a_single_identity(string $kind): void
    {
        $response = $this->create($kind)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.patient_id', $this->visit->patient_id)->assertJsonPath('data.authored_by', $this->author->id);
        $id = $response->json('data.id');
        $change = match ($kind) { 'vitals' => ['comment' => 'Private clinical update sentinel'], 'entry' => ['content' => 'Private clinical update sentinel'],
            'diagnosis' => ['description' => 'Private clinical update sentinel'], 'procedure' => ['remarks' => 'Private clinical update sentinel'] };
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 1, 'payload' => $change])->assertOk()->assertJsonPath('data.version', 2);
        $this->sign($id, 2)->assertOk()->assertJsonPath('data.status', 'signed')->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.finalized_by', $this->author->id)->assertJsonPath('data.author_name', $this->author->name);
        $this->getJson($this->path($id))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.version', 3)
            ->assertJsonMissingPath('data.author.email')->assertJsonMissingPath('data.password');
        $history = $this->getJson($this->path($id).'/history')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.operation', 'created')->assertJsonPath('data.1.operation', 'updated')->assertJsonPath('data.2.operation', 'signed')
            ->assertJsonPath('data.0.snapshot.patient_id', $this->visit->patient_id)->assertJsonMissingPath('data.0.snapshot.author_name');
        if ($kind === 'entry') {
            $this->assertInstanceOf(\stdClass::class, $history->baseResponse->getData()->data[0]->snapshot->payload->fields);
        }
        $this->assertDatabaseCount('clinical_records', 1);
        $this->assertDatabaseCount('clinical_revisions', 3);
        $this->assertDatabaseHas('idempotency_requests', ['resource_type' => 'clinical', 'resource_id' => $id, 'resource_version' => 3]);
        $this->assertAuditExcludes(['Private clinical update sentinel', 'Clinical Author Sentinel', 'private.clinical@example.test', 'Synthetic diagnosis sentinel']);
    }

    public static function kinds(): array
    {
        return [['vitals'], ['entry'], ['diagnosis'], ['procedure']];
    }

    public function test_amendments_are_signed_atomically_under_the_new_author_and_preserve_the_old_record(): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        $this->sign($id)->assertOk();
        $original = ClinicalRecord::findOrFail($id);
        $this->as($this->other);
        $data = $this->finalization(2) + ['reason' => 'Private amendment reason sentinel', 'payload' => ['content' => 'Corrected clinical content sentinel']];
        $key = ['Idempotency-Key' => 'clinical-amendment-confirm-001'];
        $response = $this->postCommand($this->path($id).'/amend', $data, $key)->assertCreated()->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.amends_id', $id)->assertJsonPath('data.authored_by', $this->other->id);
        $new = $response->json('data.id');
        $this->assertNotSame($id, $new);
        $this->assertSame($this->author->id, $original->fresh()->authored_by);
        $this->assertSame('Private clinical content sentinel', $original->entry->content);
        $this->postCommand($this->path($id).'/amend', $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $new);
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $new);
        $this->getJson($this->path($id))->assertOk()->assertJsonPath('data.superseded', true);
        $this->getJson($this->path($new).'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.operation', 'amended');
        $this->postCommand($this->path($id).'/amend', $data)->assertConflict()->assertJsonPath('code', 'clinical_not_current');
        $this->assertDatabaseCount('clinical_records', 2);
        $this->assertAuditExcludes(['Private amendment reason sentinel', 'Corrected clinical content sentinel']);
    }

    public function test_voiding_a_signed_record_retains_it_and_retires_its_current_head(): void
    {
        $id = $this->create('diagnosis')->assertCreated()->json('data.id');
        $this->sign($id)->assertOk();
        $this->as($this->other);
        $data = $this->finalization(2) + ['reason' => 'Private void reason sentinel'];
        $key = ['Idempotency-Key' => 'clinical-void-confirmation-001'];
        $response = $this->postCommand($this->path($id).'/void', $data, $key)->assertOk()->assertJsonPath('data.status', 'voided')->assertJsonPath('data.amends_id', $id);
        $new = $response->json('data.id');
        $this->assertNotSame($id, $new);
        $this->assertSame('signed', ClinicalRecord::findOrFail($id)->status);
        $this->assertSame('Synthetic diagnosis sentinel', ClinicalRecord::findOrFail($id)->diagnosis->description);
        $this->postCommand($this->path($id).'/void', $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $new);
        $this->getJson($this->path())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'voided');
        $this->postCommand($this->path($new).'/amend', $this->finalization(2) + ['reason' => 'Review voided record', 'payload' => ['description' => 'Another value']])
            ->assertConflict()->assertJsonPath('code', 'clinical_not_current');
        $this->assertAuditExcludes(['Private void reason sentinel', 'Synthetic diagnosis sentinel']);
    }

    public function test_drafts_are_private_and_another_staff_member_cannot_sign_or_edit_them(): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        $this->as($this->other);
        $this->getJson($this->path())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($id))->assertNotFound();
        $this->getJson($this->path($id).'/history')->assertNotFound();
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 1, 'payload' => ['content' => 'Not my draft']])->assertNotFound();
        $this->sign($id)->assertNotFound();
        $this->postCommand($this->path($id).'/void', $this->finalization(1) + ['reason' => 'Not my draft'])->assertNotFound();
        $this->as($this->author);
        $this->sign($id)->assertOk();
        $this->as($this->other);
        $this->getJson($this->path($id))->assertOk();
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 2, 'payload' => ['content' => 'Overwrite other author']])->assertForbidden();
        $this->sign($id, 2)->assertForbidden();
    }

    public function test_cross_patient_cross_visit_and_restricted_records_stay_hidden(): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        $this->sign($id)->assertOk();
        $otherVisit = Encounter::factory()->create(['opened_at' => now('UTC')->subHour()]);
        $wrong = '/api/v1/encounters/'.$otherVisit->id.'/clinical/'.$id;
        $this->getJson($wrong)->assertNotFound();
        $this->getJson($wrong.'/history')->assertNotFound();
        $this->postCommand($wrong.'/amend', $this->finalization(2) + ['reason' => 'Wrong patient correction', 'payload' => ['content' => 'No cross-patient changes']])->assertNotFound();
        $this->visit->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->getJson($this->path())->assertNotFound();
        $this->getJson($this->path($id))->assertNotFound();
        $this->create('entry')->assertNotFound();
        $this->assertSame(1, ClinicalRecord::where('patient_id', $this->visit->patient_id)->count());
    }

    public function test_clinician_assignment_scope_is_rechecked_on_reads_writes_and_retries(): void
    {
        Role::where('code', 'doctor')->sole()->permissions()->detach(Permission::where('code', 'encounters.view_any')->sole()->id);
        $assignment = DoctorAssignment::factory()->for($this->visit)->create(['doctor_id' => $this->author->id, 'assigned_at' => now('UTC')->subMinutes(30)]);
        $data = ['encounter_version' => 1, 'kind' => 'entry', 'payload' => $this->payload('entry')];
        $key = ['Idempotency-Key' => 'clinical-current-clinician-001'];
        $id = $this->postCommand($this->path(), $data, $key)->assertCreated()->json('data.id');
        $this->sign($id)->assertOk();
        $this->as($this->other);
        $this->getJson($this->path())->assertNotFound();
        $assignment->forceFill(['status' => 'reassigned', 'version' => 2, 'ended_at' => now('UTC'),
            'ended_by' => $this->other->id, 'end_reason' => 'Synthetic handover'])->save();
        DoctorAssignment::factory()->for($this->visit)->create(['doctor_id' => $this->other->id, 'assigned_at' => now('UTC')]);
        $this->getJson($this->path($id))->assertOk();
        $this->as($this->author);
        $this->getJson($this->path($id))->assertNotFound();
        $this->postCommand($this->path(), $data, $key)->assertNotFound();
    }

    #[DataProvider('closedActions')]
    public function test_closed_visits_are_read_only_for_every_clinical_action(string $action): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        if (in_array($action, ['amend', 'void'], true)) {
            $this->sign($id)->assertOk();
        }
        $this->visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $this->visit->forceFill(['status' => 'closed', 'version' => 3, 'closed_at' => now('UTC')])->save();
        $data = ['encounter_version' => 3, 'version' => in_array($action, ['amend', 'void'], true) ? 2 : 1,
            'current_password' => self::STAFF_TEST_PASSWORD, 'reason' => 'Closed visit review', 'payload' => ['content' => 'Closed visit change']];
        $response = $this->assertTablesUnchanged($this->tables(), function () use ($id, $action, $data): TestResponse {
            return match ($action) {
                'store' => $this->create('entry', ['encounter_version' => 3]),
                'update' => $this->patchCommand($this->path($id), array_intersect_key($data, array_flip(['encounter_version', 'version', 'payload']))),
                'sign' => $this->postCommand($this->path($id).'/sign', array_intersect_key($data, array_flip(['encounter_version', 'version', 'current_password']))),
                default => $this->postCommand($this->path($id).'/'.$action, array_intersect_key($data, array_flip($action === 'amend'
                    ? ['encounter_version', 'version', 'current_password', 'reason', 'payload'] : ['encounter_version', 'version', 'current_password', 'reason']))),
            };
        });
        $this->assertApiFailure($response, 409, 'clinical_encounter_closed');
        $this->getJson($this->path($id))->assertOk();
    }

    public static function closedActions(): array
    {
        return [['store'], ['update'], ['sign'], ['amend'], ['void']];
    }

    public function test_vital_signatures_require_a_measurement_and_retain_explicit_units_and_precision(): void
    {
        $id = $this->create('vitals', ['payload' => ['observed_at' => now('UTC')->subMinute()->toISOString(), 'comment' => 'Draft without measurements']])->assertCreated()->json('data.id');
        $this->sign($id)->assertUnprocessable()->assertJsonValidationErrors('payload.observed_at');
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 1, 'payload' => ['blood_glucose' => '5.125', 'blood_glucose_unit' => 'mmol/L']])
            ->assertOk()->assertJsonPath('data.payload.blood_glucose', '5.125');
        $this->sign($id, 2)->assertOk()->assertJsonPath('data.payload.blood_glucose_unit', 'mmol/L');
        $full = $this->create('vitals')->assertCreated();
        $this->assertSame(24.22, $full->json('data.body_mass_index'));
    }

    public function test_published_template_versions_enforce_their_own_fields_without_rewriting_old_definitions(): void
    {
        $template = ClinicalTemplateVersion::factory()->create(['fields' => ['summary' => ['type' => 'text', 'required' => true, 'max_length' => 20],
            'reviewed' => ['type' => 'boolean'], 'finding' => ['type' => 'select', 'options' => ['Recorded', 'Pending']]]]);
        $id = $this->create('entry', ['payload' => ['section' => 'note', 'clinical_template_version_id' => $template->id]])->assertCreated()->json('data.id');
        $this->sign($id)->assertUnprocessable()->assertJsonValidationErrors('payload.fields.summary');
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 1, 'payload' => ['fields' => ['not_defined' => 'No arbitrary fields']]])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.fields.not_defined');
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 1, 'payload' => ['fields' => ['summary' => 'Reviewed', 'reviewed' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.fields.reviewed');
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 1, 'payload' => ['fields' => ['summary' => 'Reviewed', 'reviewed' => true, 'finding' => 'Recorded']]])->assertOk();
        ClinicalTemplateVersion::factory()->create(['clinical_template_id' => $template->clinical_template_id, 'version' => 2, 'fields' => ['different' => ['type' => 'text']]]);
        $this->sign($id, 2)->assertOk()->assertJsonPath('data.template_version.version', 1)->assertJsonPath('data.payload.fields.summary', 'Reviewed');
        $this->getJson('/api/v1/clinical/templates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 2);
        $template->template->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/clinical/templates')->assertOk()->assertJsonCount(0, 'data');
        $this->create('entry', ['payload' => ['section' => 'note', 'clinical_template_version_id' => $template->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.clinical_template_version_id');
        $this->getJson($this->path($id))->assertOk()->assertJsonPath('data.template_version.version', 1);
    }

    public function test_untemplated_physical_examinations_and_procedure_findings_use_scoped_fields_and_authors(): void
    {
        $id = $this->create('entry', ['payload' => ['section' => 'physical_examination', 'fields' => ['general' => 'Recorded exam', 'cns' => 'Recorded finding']]])->assertCreated()->json('data.id');
        $this->sign($id)->assertOk();
        $procedure = $this->create('procedure')->assertCreated()->json('data.id');
        $this->sign($procedure)->assertOk();
        $this->as($this->other);
        $finding = $this->create('entry', ['payload' => ['section' => 'procedure_findings', 'related_record_id' => $procedure, 'content' => 'Independent finding sentinel']])
            ->assertCreated()->assertJsonPath('data.authored_by', $this->other->id)->json('data.id');
        $this->sign($finding)->assertOk();
        $this->assertSame($this->author->id, ClinicalRecord::findOrFail($procedure)->authored_by);
        $otherVisit = Encounter::factory()->create(['opened_at' => now('UTC')->subHour()]);
        $foreign = ClinicalRecord::factory()->forEncounter($otherVisit)->create(['kind' => 'procedure']);
        $this->create('entry', ['payload' => ['section' => 'procedure_findings', 'related_record_id' => $foreign->id, 'content' => 'No foreign link']])
            ->assertUnprocessable()->assertJsonValidationErrors('payload.related_record_id');
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_and_injected_clinical_fields_do_not_change_any_records(string $kind, array $changes, string $field): void
    {
        $payload = array_replace($this->payload($kind), $changes);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->create($kind, ['payload' => $payload]));
        $response->assertUnprocessable()->assertJsonValidationErrors('payload.'.$field);
    }

    public static function invalidPayloads(): array
    {
        return [
            ['vitals', ['systolic_mmhg' => null], 'systolic_mmhg'], ['vitals', ['blood_glucose_unit' => null], 'blood_glucose'],
            ['vitals', ['blood_glucose_unit' => 'unknown'], 'blood_glucose_unit'], ['vitals', ['spo2_percent' => 101], 'spo2_percent'],
            ['vitals', ['height_m' => 0], 'height_m'], ['vitals', ['weight_kg' => -1], 'weight_kg'],
            ['vitals', ['temperature_c' => '36.123'], 'temperature_c'], ['vitals', ['observed_at' => '2026-11-31T10:00:00Z'], 'observed_at'],
            ['vitals', ['observed_at' => '2999-01-01T00:00:00Z'], 'observed_at'], ['vitals', ['observed_at' => '2026-11-11 10:00:00'], 'observed_at'],
            ['vitals', ['body_mass_index' => 10], 'body_mass_index'], ['vitals', ['clinical_record_id' => 1], 'clinical_record_id'],
            ['diagnosis', ['description' => ' '], 'description'], ['diagnosis', ['code' => 'TEST'], 'code'],
            ['diagnosis', ['authored_by' => 1], 'authored_by'], ['procedure', ['performed_by' => 1], 'performed_by'],
            ['procedure', ['quoted_amount' => '-1'], 'quoted_amount'], ['procedure', ['currency' => null], 'quoted_amount'],
            ['procedure', ['currency' => 'ngn'], 'currency'], ['procedure', ['name' => ' '], 'name'],
            ['entry', ['fields' => ['not_defined' => 'arbitrary']], 'fields.not_defined'], ['entry', ['section' => 'unknown'], 'section'],
        ];
    }

    public function test_versions_permissions_reauthentication_and_command_targets_are_rechecked(): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        $this->patchCommand($this->path($id), ['encounter_version' => 1, 'version' => 99, 'payload' => ['content' => 'Stale values']])->assertConflict();
        $this->patchCommand($this->path($id), ['encounter_version' => 99, 'version' => 1, 'payload' => ['content' => 'Stale encounter']])->assertConflict();
        $this->sign($id, 1, ['current_password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $key = ['Idempotency-Key' => 'clinical-sign-confirmation-001'];
        $this->postCommand($this->path($id).'/sign', $this->finalization(1), $key)->assertOk();
        $this->postCommand($this->path($id).'/sign', $this->finalization(1), $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->postCommand($this->path($id).'/sign', $this->finalization(1, ['current_password' => 'wrong']), $key)->assertUnprocessable();
        $other = $this->create('entry')->assertCreated()->json('data.id');
        $this->postCommand($this->path($other).'/sign', $this->finalization(1), $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        Role::where('code', 'doctor')->sole()->permissions()->detach(Permission::where('code', 'clinical.sign')->sole()->id);
        $this->postCommand($this->path($id).'/sign', $this->finalization(1), $key)->assertForbidden();
        $this->getJson($this->path($id))->assertOk();
    }

    public function test_receipts_do_not_store_clinical_values_and_expired_or_outdated_retries_cannot_overwrite(): void
    {
        $data = ['encounter_version' => 1, 'kind' => 'entry', 'payload' => $this->payload('entry')];
        $key = ['Idempotency-Key' => 'clinical-create-confirmation-001'];
        $id = $this->postCommand($this->path(), $data, $key)->assertCreated()->json('data.id');
        $this->postCommand($this->path(), $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->sign($id)->assertOk();
        $this->postCommand($this->path(), $data, $key)->assertConflict()->assertJsonPath('code', 'idempotency_outdated');
        DB::table('idempotency_requests')->update(['expires_at' => now('UTC')->subSecond()]);
        $this->postCommand($this->path(), $data, $key)->assertConflict()->assertJsonPath('code', 'idempotency_expired');
        $this->assertStringNotContainsString('Private clinical content sentinel', json_encode(DB::table('idempotency_requests')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_permissions_are_opt_in_readers_cannot_write_and_private_history_needs_review_permission(): void
    {
        $admin = $this->createStaffWithRole('admin');
        $this->as($admin);
        $this->getJson('/api/v1/clinical/options')->assertForbidden();
        $this->getJson($this->path())->assertForbidden();
        $this->create('entry')->assertForbidden();
        $this->as($this->author);
        $id = $this->create('entry')->assertCreated()->json('data.id');
        $this->sign($id)->assertOk();
        $role = Role::where('code', 'doctor')->sole();
        $role->permissions()->detach(Permission::whereIn('code', ['clinical.write', 'clinical.review'])->pluck('id'));
        $this->getJson($this->path($id))->assertOk();
        $this->getJson($this->path($id).'/history')->assertForbidden();
        $this->create('entry')->assertForbidden();
        $this->assertDatabaseMissing('role_permissions', ['role_id' => Role::where('code', 'admin')->sole()->id,
            'permission_id' => Permission::where('code', 'clinical.sign')->sole()->id]);
    }

    public function test_authentication_inactive_staff_and_prescription_isolation_apply_to_all_new_endpoints(): void
    {
        $prescription = Prescription::factory()->create(['clinical_record_id' => ClinicalRecord::factory()->forEncounter($this->visit)->create(['kind' => 'prescription'])->id]);
        $this->getJson($this->path($prescription->getKey()))->assertNotFound();
        $this->create('prescription')->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->author->forceFill(['is_active' => false, 'version' => 2])->save();
        $this->getJson($this->path())->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson($this->path())->assertUnauthorized();
    }

    public function test_own_draft_can_be_voided_with_a_reason_but_cannot_be_silently_reopened(): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        $this->postCommand($this->path($id).'/void', $this->finalization(1) + ['reason' => 'Entered against wrong visit'])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'voided')->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.payload.content', 'Private clinical content sentinel');
        $this->assertDatabaseCount('clinical_records', 1);
        $this->getJson($this->path($id).'/history')->assertOk()->assertJsonPath('data.1.operation', 'voided')
            ->assertJsonPath('data.1.reason', 'Entered against wrong visit');
        $this->sign($id, 2)->assertConflict()->assertJsonPath('code', 'clinical_record_finalized');
        $this->patchCommand($this->path($id), ['version' => 2, 'encounter_version' => 1, 'payload' => ['content' => 'Cannot overwrite']])->assertConflict();
    }

    public function test_list_filters_pagination_and_unknown_command_fields_follow_the_api_contract(): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        $this->create('diagnosis')->assertCreated();
        $this->getJson($this->path().'?kind=entry&status=draft&per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1);
        $this->getJson($this->path().'?kind=prescription&status=invalid&page=0&per_page=101')->assertUnprocessable()
            ->assertJsonValidationErrors(['kind', 'status', 'page', 'per_page']);
        $this->patchCommand($this->path($id), ['version' => 1, 'encounter_version' => 1, 'payload' => ['content' => 'Private clinical content sentinel']])
            ->assertConflict()->assertJsonPath('code', 'clinical_unchanged');
        $this->create('entry', ['authored_by' => $this->other->id])->assertUnprocessable()->assertJsonValidationErrors('authored_by');
        $this->postJson($this->path(), ['encounter_version' => 1, 'kind' => 'entry', 'payload' => $this->payload('entry')])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_sign_amend_and_void_permissions_are_independent_and_rechecked(): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        $role = Role::where('code', 'doctor')->sole();
        $role->permissions()->detach(Permission::where('code', 'clinical.sign')->sole()->id);
        $this->sign($id)->assertForbidden();
        $this->grantRolePermissions('doctor', ['clinical.sign']);
        $this->sign($id)->assertOk();
        $role->permissions()->detach(Permission::where('code', 'clinical.amend')->sole()->id);
        $this->postCommand($this->path($id).'/amend', $this->finalization(2) + ['reason' => 'Unauthorized correction', 'payload' => ['content' => 'Replacement']])->assertForbidden();
        $this->postCommand($this->path($id).'/void', $this->finalization(2) + ['reason' => 'Unauthorized retraction'])->assertForbidden();
        $this->grantRolePermissions('doctor', ['clinical.amend']);
        $role->permissions()->detach(Permission::where('code', 'clinical.void')->sole()->id);
        $this->postCommand($this->path($id).'/void', $this->finalization(2) + ['reason' => 'Unauthorized retraction'])->assertForbidden();
        $this->assertDatabaseCount('clinical_records', 1);
        $this->assertDatabaseCount('clinical_revisions', 2);
    }

    #[DataProvider('finalizationFailures')]
    public function test_failed_edits_and_finalizations_roll_back_existing_content_and_state(string $action, bool $signed): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        if ($signed) {
            $this->sign($id)->assertOk();
        }
        $hit = $this->failNextWriteTo('clinical_revisions', 'insert');
        $data = $this->finalization($signed ? 2 : 1) + ['reason' => 'Synthetic rollback reason'];
        if ($action === 'update') {
            unset($data['current_password'], $data['reason']);
            $data['payload'] = ['content' => 'Rolled back draft edit'];
        } elseif ($action === 'sign') {
            unset($data['reason']);
        }
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $action === 'update'
            ? $this->patchCommand($this->path($id), $data) : $this->postCommand($this->path($id).'/'.$action, $data));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertSame($signed ? 'signed' : 'draft', ClinicalRecord::findOrFail($id)->status);
        $this->assertSame('Private clinical content sentinel', ClinicalRecord::findOrFail($id)->entry->content);
    }

    public static function finalizationFailures(): array
    {
        return [['update', false], ['sign', false], ['void', false], ['void', true]];
    }

    #[DataProvider('writeFailures')]
    public function test_failed_creations_roll_back_content_revisions_receipts_and_success_audits(string $table, string $operation): void
    {
        Log::spy();
        $hit = $this->failNextWriteTo($table, $operation);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->create('vitals'));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertNoSuccessfulAudit('clinical.created');
        $this->assertAuditExcludes(['Private clinical observation sentinel']);
    }

    public static function writeFailures(): array
    {
        return [['clinical_records', 'insert'], ['vital_signs', 'insert'], ['clinical_revisions', 'insert'],
            ['audit_events', 'insert'], ['idempotency_requests', 'update']];
    }

    public function test_a_failure_after_the_final_amendment_revision_rolls_back_the_entire_correction(): void
    {
        $id = $this->create('entry')->assertCreated()->json('data.id');
        $this->sign($id)->assertOk();
        $count = 0;
        DB::listen(function (QueryExecuted $event) use (&$count): void {
            if (preg_match('/^insert\s+into\s+[`"]?clinical_revisions/i', $event->sql) === 1 && ++$count === 2) {
                throw new RuntimeException('Synthetic failure after final correction.');
            }
        });
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand($this->path($id).'/amend',
            $this->finalization(2) + ['reason' => 'Private failed correction sentinel', 'payload' => ['content' => 'Rolled back replacement sentinel']]));
        $this->assertSame(2, $count);
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertDatabaseCount('clinical_records', 1);
        $this->assertNoSuccessfulAudit('clinical.amended');
    }

    private function path(?int $id = null): string
    {
        return '/api/v1/encounters/'.$this->visit->id.'/clinical'.($id === null ? '' : '/'.$id);
    }

    private function create(string $kind, array $overrides = []): TestResponse
    {
        return $this->postCommand($this->path(), array_replace(['encounter_version' => 1, 'kind' => $kind,
            'payload' => $kind === 'prescription' ? [] : $this->payload($kind)], $overrides));
    }

    private function payload(string $kind): array
    {
        return match ($kind) {
            'vitals' => ['observed_at' => now('UTC')->subMinute()->toISOString(), 'temperature_c' => '36.50', 'systolic_mmhg' => 120,
                'diastolic_mmhg' => 80, 'blood_glucose' => '5.125', 'blood_glucose_unit' => 'mmol/L', 'weight_kg' => '70.000',
                'height_m' => '1.700', 'comment' => 'Private clinical observation sentinel'],
            'entry' => ['section' => 'note', 'content' => 'Private clinical content sentinel'],
            'diagnosis' => ['description' => 'Synthetic diagnosis sentinel'],
            'procedure' => ['name' => 'Synthetic procedure sentinel', 'quoted_amount' => '3000.00', 'currency' => 'NGN', 'performed_at' => now('UTC')->subMinute()->toISOString()],
        };
    }

    private function sign(int $id, int $version = 1, array $overrides = []): TestResponse
    {
        return $this->postCommand($this->path($id).'/sign', $this->finalization($version, $overrides));
    }

    private function finalization(int $version, array $overrides = []): array
    {
        return array_replace(['encounter_version' => 1, 'version' => $version, 'current_password' => self::STAFF_TEST_PASSWORD], $overrides);
    }

    private function as(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
    }

    private function tables(): array
    {
        return ['clinical_records', 'clinical_revisions', 'vital_signs', 'clinical_entries', 'diagnoses', 'clinical_procedures', 'prescriptions', 'prescription_items', 'idempotency_requests'];
    }
}
