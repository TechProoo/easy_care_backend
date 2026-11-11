<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\PatientContact;
use App\Models\PatientIdentifier;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditTrail;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PatientApiTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->registrar = $this->createStaffWithRole('frontdesk');
        $this->grantRolePermissions('frontdesk', ['patients.view', 'patients.create', 'patients.update']);
        $this->category = PatientCategory::where('code', 'private')->sole()->id;
    }

    public function test_registration_persists_demographics_number_and_ordered_contacts_with_private_audit(): void
    {
        $this->actingAs($this->registrar);
        $data = $this->data();
        $data['mrn'] = ' card/001 ';
        $data['phone'] = '234 801-234-5678';
        $data['contacts'][] = ['name' => 'Second Contact', 'relationship' => 'sister', 'phone' => '+2348011111111'];
        $response = $this->postCommand('/api/v1/patients', $data)->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $id = $response->json('data.id');
        $this->getJson('/api/v1/patients/'.$id)->assertOk()->assertJsonPath('data.mrn', 'CARD/001')
            ->assertJsonPath('data.first_name', 'Private-Sentinel')->assertJsonPath('data.phone', '+2348012345678')
            ->assertJsonPath('data.category.id', $this->category)->assertJsonPath('data.created_by', $this->registrar->id)
            ->assertJsonPath('data.version', 1)->assertJsonCount(2, 'data.contacts')->assertJsonPath('data.contacts.1.priority', 2)
            ->assertJsonPath('data.date_of_birth', '2000-03-14')->assertJsonMissingPath('data.photo_file_id')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('patient_identifiers', ['patient_id' => $id, 'identifier' => 'CARD/001', 'retired_at' => null]);
        $this->assertDatabaseHas('patient_contacts', ['patient_id' => $id, 'priority' => 1, 'name' => 'Contact-Sentinel']);
        $this->assertAuditEventRecorded('patient.created', $this->registrar->id, 'patient', $id,
            summary: ['version' => 1, 'category_id' => $this->category, 'contact_count' => 2]);
        $this->assertAuditExcludes(['Private-Sentinel', 'Contact-Sentinel', 'CARD/001', '+2348012345678', 'private@example.test', '2000-03-14']);
    }

    public function test_partial_edit_preserves_number_category_and_omitted_values_and_replaces_contacts_atomically(): void
    {
        $this->actingAs($this->registrar);
        $id = $this->postCommand('/api/v1/patients', $this->data())->assertCreated()->json('data.id');
        $this->patchCommand('/api/v1/patients/'.$id, ['version' => 1, 'address' => 'New private address',
            'contacts' => [['name' => 'New contact', 'relationship' => 'parent', 'phone' => '+2348099999999']]])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.mrn', 'CARD-001')
            ->assertJsonPath('data.contacts.0.name', 'New contact');
        $this->getJson('/api/v1/patients/'.$id)->assertOk()->assertJsonPath('data.first_name', 'Private-Sentinel')
            ->assertJsonPath('data.address', 'New private address')->assertJsonPath('data.category.id', $this->category);
        $this->assertDatabaseCount('patient_contacts', 1);
        $this->assertDatabaseCount('patient_identifiers', 1);
        $this->assertAuditEventRecorded('patient.updated', $this->registrar->id, 'patient', $id,
            summary: ['version' => 2, 'changed_fields' => ['address', 'contacts'], 'contacts_changed' => true]);
        $this->assertAuditExcludes(['New private address', 'New contact']);
    }

    public function test_empty_contact_replacement_clears_contacts_while_omission_keeps_them(): void
    {
        $this->actingAs($this->registrar);
        $id = $this->postCommand('/api/v1/patients', $this->data())->assertCreated()->json('data.id');
        $this->patchCommand('/api/v1/patients/'.$id, ['version' => 1, 'occupation' => 'Teacher'])->assertOk()->assertJsonCount(1, 'data.contacts');
        $this->patchCommand('/api/v1/patients/'.$id, ['version' => 2, 'contacts' => []])->assertOk()->assertJsonCount(0, 'data.contacts');
        $this->assertDatabaseCount('patient_contacts', 0);
    }

    #[DataProvider('invalidCreates')]
    public function test_invalid_registration_has_field_errors_without_partial_rows(array $changes, string $field): void
    {
        $this->actingAs($this->registrar);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/patients', array_replace($this->data(), $changes)));
        $this->assertApiFailure($response, 422, 'validation_failed', [$field]);
        $this->assertNoSuccessfulAudit('patient.created');
        $this->assertAuditEventRecorded('patient.created', $this->registrar->id, 'patient', null, 'denied');
    }

    public static function invalidCreates(): array
    {
        return [
            'missing name' => [['first_name' => ''], 'first_name'],
            'oversized name' => [['last_name' => str_repeat('A', 65)], 'last_name'],
            'invalid number' => [['mrn' => 'BAD NUMBER'], 'mrn'],
            'oversized number' => [['mrn' => str_repeat('A', 65)], 'mrn'],
            'newline inside number' => [['mrn' => "BAD\nNUMBER"], 'mrn'],
            'unknown category' => [['category_id' => 99999999], 'category_id'],
            'bad calendar date' => [['date_of_birth' => '2025-02-30'], 'date_of_birth'],
            'future date' => [['date_of_birth' => '2999-01-01'], 'date_of_birth'],
            'timestamp instead of date' => [['date_of_birth' => '2000-03-14T00:00:00Z'], 'date_of_birth'],
            'estimate without date' => [['date_of_birth' => null, 'dob_estimated' => true], 'date_of_birth'],
            'bad email' => [['email' => 'not-email'], 'email'],
            'bad phone' => [['phone' => 'not-phone'], 'phone'],
            'bad contact' => [['contacts' => [['name' => 'Name', 'relationship' => 'kin', 'phone' => 'bad']]], 'contacts.0.phone'],
            'contact spoofing' => [['contacts' => [['name' => 'Name', 'relationship' => 'kin', 'phone' => '+2348012345678', 'patient_id' => 123]]], 'contacts.0'],
            'too many contacts' => [['contacts' => array_fill(0, 11, ['name' => 'N', 'relationship' => 'kin', 'phone' => '+2348012345678'])], 'contacts'],
            'attribution spoofing' => [['created_by' => 123], 'created_by'],
            'status spoofing' => [['access_status' => 'active'], 'access_status'],
            'life status spoofing' => [['life_status' => 'deceased'], 'life_status'],
            'photo spoofing' => [['photo_file_id' => null], 'photo_file_id'],
            'version spoofing' => [['version' => 1], 'version'],
            'insurance pretending to save' => [['insurance_policy_number' => 'UNSUPPORTED'], 'insurance_policy_number'],
        ];
    }

    public function test_patient_number_and_category_cannot_change_through_demographic_edits(): void
    {
        $this->actingAs($this->registrar);
        $patient = $this->patient();
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->patchCommand('/api/v1/patients/'.$patient->id,
            ['version' => 1, 'mrn' => 'NEW-001', 'category_id' => $this->category]));
        $this->assertApiFailure($response, 422, 'validation_failed', ['mrn', 'category_id']);
    }

    public function test_estimated_birth_date_checks_use_the_existing_record_for_partial_edits(): void
    {
        $this->actingAs($this->registrar);
        $patient = $this->patient(['date_of_birth' => null]);
        $this->patchCommand('/api/v1/patients/'.$patient->id, ['version' => 1, 'dob_estimated' => true])->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
        $this->patchCommand('/api/v1/patients/'.$patient->id, ['version' => 1, 'date_of_birth' => '2000-01-01', 'dob_estimated' => true])->assertOk();
        $this->patchCommand('/api/v1/patients/'.$patient->id, ['version' => 2, 'date_of_birth' => null])->assertUnprocessable();
        $this->patchCommand('/api/v1/patients/'.$patient->id, ['version' => 2, 'date_of_birth' => null, 'dob_estimated' => false])->assertOk();
    }

    public function test_stale_edits_leave_demographics_contacts_number_and_commands_unchanged(): void
    {
        $this->actingAs($this->registrar);
        $patient = $this->patient();
        PatientContact::factory()->for($patient)->create();
        $this->patchCommand('/api/v1/patients/'.$patient->id, ['version' => 1, 'address' => 'Saved'])->assertOk();
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->patchCommand('/api/v1/patients/'.$patient->id,
            ['version' => 1, 'address' => 'Stale', 'contacts' => []]));
        $this->assertApiFailure($response, 409, 'version_conflict');
    }

    public function test_search_uses_scoped_pagination_filters_literal_tokens_and_historical_numbers(): void
    {
        $this->actingAs($this->registrar);
        $patient = $this->patient(['mrn' => 'SEARCH-001', 'first_name' => 'Literal%', 'last_name' => 'Needle', 'date_of_birth' => '2000-01-01']);
        $this->patient(['first_name' => 'LiteralX', 'last_name' => 'Needle']);
        $this->patient(['first_name' => 'Literal%', 'last_name' => 'Hidden', 'access_status' => 'restricted']);
        PatientIdentifier::factory()->for($patient)->create(['identifier' => 'OLD-SEARCH']);
        $this->getJson('/api/v1/patients?q=Literal%25%20Needle&per_page=1&sort=-mrn')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $patient->id)->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.contacts')->assertJsonMissingPath('data.0.created_by');
        $this->getJson('/api/v1/patients?mrn=old-search')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.mrn', 'SEARCH-001');
        $this->getJson('/api/v1/patients?date_of_birth=2000-01-01&category_id='.$this->category)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/patients?per_page=1&page=2&sort=mrn')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/patients?mrn=UNKNOWN')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_validation_options_and_unknown_record_behavior(): void
    {
        $this->actingAs($this->registrar);
        $this->getJson('/api/v1/patients?sort=address&per_page=101&page=0&date_of_birth=bad')->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'per_page', 'page', 'date_of_birth']);
        $this->getJson('/api/v1/patients/options')->assertOk()->assertJsonCount(7, 'data.categories');
        $this->assertApiFailure($this->getJson('/api/v1/patients/99999999'), 404, 'not_found');
    }

    #[DataProvider('hiddenStates')]
    public function test_hidden_records_do_not_leak_through_lookup_search_duplicates_or_edits(string $status): void
    {
        $this->actingAs($this->registrar);
        $patient = $this->patient(['mrn' => 'HIDDEN-001', 'first_name' => 'Private-Sentinel', 'last_name' => 'Person',
            'date_of_birth' => '2000-03-14', 'phone' => '+2348012345678', 'access_status' => $status]);
        $hidden = $this->getJson('/api/v1/patients/'.$patient->id);
        $this->assertSame($this->getJson('/api/v1/patients/99999999')->json(), $hidden->assertNotFound()->json());
        $this->getJson('/api/v1/patients?mrn=HIDDEN-001')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/patients?q=Private-Sentinel')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/patients/duplicate-check', $this->duplicateData())->assertOk()->assertJsonCount(0, 'data');
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->patchCommand('/api/v1/patients/'.$patient->id, ['version' => 1, 'address' => 'No']));
        $this->assertApiFailure($response, 404, 'not_found');
        $this->assertStringNotContainsString('Private-Sentinel', $hidden->getContent());
    }

    public static function hiddenStates(): array
    {
        return [['restricted'], ['merged']];
    }

    public function test_permissions_are_opt_in_and_admin_has_no_patient_bypass(): void
    {
        $admin = $this->createStaffWithRole('admin');
        $this->actingAs($admin);
        $this->getJson('/api/v1/patients?per_page=bad')->assertForbidden();
        $this->postCommand('/api/v1/patients', [])->assertForbidden();
        $this->seed(DatabaseSeeder::class);
        $this->getJson('/api/v1/patients')->assertForbidden();
        $this->assertFalse($admin->hasPermission('patients.view'));
    }

    public function test_read_only_staff_can_lookup_but_cannot_write_and_create_permission_alone_is_insufficient(): void
    {
        $viewer = $this->createStaffWithRole('doctor');
        $patient = $this->patient();
        $this->grantRolePermissions('doctor', ['patients.view']);
        $this->actingAs($viewer)->getJson('/api/v1/patients/'.$patient->id)->assertOk();
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/patients', $this->data()));
        $this->assertApiFailure($response, 403, 'forbidden');
        $this->patchCommand('/api/v1/patients/'.$patient->id, ['version' => 1, 'address' => 'No'])->assertForbidden();
        Role::where('code', 'doctor')->sole()->permissions()->detach(Permission::where('code', 'patients.view')->sole());
        $this->grantRolePermissions('doctor', ['patients.create']);
        $this->postCommand('/api/v1/patients', $this->data())->assertForbidden();
    }

    public function test_guest_and_inactive_staff_are_rejected_and_revoked_permissions_are_not_cached(): void
    {
        $this->getJson('/api/v1/patients')->assertUnauthorized();
        $this->postCommand('/api/v1/patients', $this->data())->assertUnauthorized();
        $this->actingAs($this->registrar)->getJson('/api/v1/patients')->assertOk();
        Role::where('code', 'frontdesk')->sole()->permissions()->detach(Permission::where('code', 'patients.view')->sole());
        $this->getJson('/api/v1/patients')->assertForbidden();
        $this->grantRolePermissions('frontdesk', ['patients.view']);
        $this->registrar->is_active = false;
        $this->registrar->save();
        $this->getJson('/api/v1/patients')->assertUnauthorized();
    }

    public function test_duplicate_candidates_require_exact_review_and_remain_separate_patients(): void
    {
        $this->actingAs($this->registrar);
        $existing = $this->patient(['first_name' => 'Private-Sentinel', 'last_name' => 'Person', 'date_of_birth' => '2000-03-14']);
        $this->postJson('/api/v1/patients/duplicate-check', $this->duplicateData())->assertOk()->assertJsonPath('data.0.id', $existing->id)
            ->assertJsonPath('meta.has_more', false)->assertJsonMissingPath('data.0.phone');
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/patients', $this->data()));
        $this->assertApiFailure($response, 409, 'duplicate_review_required');
        $this->postCommand('/api/v1/patients', $this->data() + ['acknowledged_duplicate_ids' => [$existing->id]])->assertCreated();
        $this->assertDatabaseCount('patients', 2);
    }

    public function test_unicode_and_whitespace_name_matching_and_shared_phone_candidates_are_checked(): void
    {
        $this->actingAs($this->registrar);
        $sameName = $this->patient(['first_name' => "\u{00c9}lodie  Anne", 'last_name' => 'Person', 'date_of_birth' => '2000-03-14']);
        $samePhone = $this->patient(['first_name' => 'Different', 'phone' => '+2348012345678']);
        $data = array_replace($this->duplicateData(), ['first_name' => "\u{00e9}lodie Anne"]);
        $result = $this->postJson('/api/v1/patients/duplicate-check', $data)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$sameName->id, $samePhone->id], array_column($result, 'id'));
    }

    public function test_duplicate_acknowledgements_cannot_be_forged_or_reused_after_candidates_change(): void
    {
        $this->actingAs($this->registrar);
        $existing = $this->patient(['first_name' => 'Private-Sentinel', 'last_name' => 'Person', 'date_of_birth' => '2000-03-14']);
        $this->postCommand('/api/v1/patients', $this->data() + ['acknowledged_duplicate_ids' => [99999999]])->assertConflict();
        $other = $this->patient(['phone' => '+2348012345678']);
        $this->postCommand('/api/v1/patients', $this->data() + ['acknowledged_duplicate_ids' => [$existing->id]])->assertConflict();
        $this->postCommand('/api/v1/patients', $this->data() + ['acknowledged_duplicate_ids' => [$existing->id, $other->id]])->assertCreated();
    }

    public function test_duplicate_checks_can_exclude_only_an_accessible_edit_target(): void
    {
        $this->actingAs($this->registrar);
        $patient = $this->patient(['phone' => '+2348012345678']);
        $this->postJson('/api/v1/patients/duplicate-check', $this->duplicateData() + ['exclude_id' => $patient->id])->assertOk()->assertJsonCount(0, 'data');
        $patient->access_status = 'restricted';
        $patient->save();
        $this->postJson('/api/v1/patients/duplicate-check', $this->duplicateData() + ['exclude_id' => $patient->id])->assertNotFound();
    }

    public function test_identity_edit_checks_duplicates_but_unrelated_edits_do_not_require_repeated_review(): void
    {
        $this->actingAs($this->registrar);
        $existing = $this->patient(['phone' => '+2348012345678']);
        $target = $this->patient();
        $this->patchCommand('/api/v1/patients/'.$target->id, ['version' => 1, 'phone' => '+2348012345678'])->assertConflict();
        $this->patchCommand('/api/v1/patients/'.$target->id, ['version' => 1, 'phone' => '+2348012345678',
            'acknowledged_duplicate_ids' => [$existing->id]])->assertOk();
        $this->patchCommand('/api/v1/patients/'.$target->id, ['version' => 2, 'address' => 'Safe edit'])->assertOk();
    }

    public function test_exact_retry_confirms_one_registration_before_number_uniqueness_validation(): void
    {
        $this->actingAs($this->registrar);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $created = $this->postCommand('/api/v1/patients', $this->data(), $headers)->assertCreated()->json('data.id');
        $this->postCommand('/api/v1/patients', $this->data(), $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $created);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('patient_contacts', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertAuditEventRecorded('patient.created', $this->registrar->id, 'patient', $created);
        $this->assertAuditEventRecorded('command.replayed', $this->registrar->id, 'patient', $created);
        $stored = json_encode(DB::table('idempotency_requests')->sole());
        $this->assertStringNotContainsString('Private-Sentinel', $stored);
        $this->assertStringNotContainsString('CARD-001', $stored);
    }

    public function test_edit_replay_confirmations_recheck_scope_permissions_and_later_versions(): void
    {
        $this->actingAs($this->registrar);
        $patient = $this->patient();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $data = ['version' => 1, 'address' => 'Saved'];
        $this->patchCommand('/api/v1/patients/'.$patient->id, $data, $headers)->assertOk();
        $this->patchCommand('/api/v1/patients/'.$patient->id, $data, $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->patchCommand('/api/v1/patients/'.$patient->id, ['version' => 2, 'address' => 'Later'])->assertOk();
        $this->patchCommand('/api/v1/patients/'.$patient->id, $data, $headers)->assertConflict()->assertJsonPath('code', 'idempotency_outdated');
        $patient->refresh()->forceFill(['access_status' => 'restricted'])->save();
        $this->patchCommand('/api/v1/patients/'.$patient->id, $data, $headers)->assertNotFound();
    }

    public function test_changed_payloads_and_expired_keys_cannot_create_another_record(): void
    {
        $this->actingAs($this->registrar);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postCommand('/api/v1/patients', $this->data(), $headers)->assertCreated();
        $this->postCommand('/api/v1/patients', array_replace($this->data(), ['first_name' => 'Changed']), $headers)->assertConflict()
            ->assertJsonPath('code', 'idempotency_conflict');
        DB::table('idempotency_requests')->update(['expires_at' => now()->subSecond()]);
        $this->postCommand('/api/v1/patients', $this->data(), $headers)->assertConflict()->assertJsonPath('code', 'idempotency_expired');
        $this->assertDatabaseCount('patients', 1);
    }

    #[DataProvider('writeFailureBoundaries')]
    public function test_failure_at_each_write_boundary_rolls_back_registration_and_its_success_audit(string $table, string $operation): void
    {
        $this->actingAs($this->registrar);
        $hit = $this->failNextWriteTo($table, $operation);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/patients', $this->data()));
        $this->assertTrue($hit(), 'The intended write boundary must be reached.');
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertNoSuccessfulAudit('patient.created');
        $this->assertAuditEventRecorded('patient.created', $this->registrar->id, 'patient', null, 'failed');
    }

    public static function writeFailureBoundaries(): array
    {
        return [['patients', 'insert'], ['patient_contacts', 'insert'], ['audit_events', 'insert'], ['idempotency_requests', 'update']];
    }

    public function test_failed_edit_restores_deleted_contacts_demographics_version_and_command_state(): void
    {
        $this->actingAs($this->registrar);
        $patient = $this->patient();
        PatientContact::factory()->for($patient)->create();
        $hit = $this->failNextWriteTo('patients', 'update');
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->patchCommand('/api/v1/patients/'.$patient->id,
            ['version' => 1, 'address' => 'Must roll back', 'contacts' => []]));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertNoSuccessfulAudit('patient.updated');
    }

    public function test_replay_after_revocation_and_patient_reads_with_failed_audit_storage_fail_closed(): void
    {
        $this->actingAs($this->registrar);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $id = $this->postCommand('/api/v1/patients', $this->data(), $headers)->assertCreated()->json('data.id');
        Role::where('code', 'frontdesk')->sole()->permissions()->detach(Permission::where('code', 'patients.create')->sole());
        $this->postCommand('/api/v1/patients', $this->data(), $headers)->assertForbidden();
        $this->mock(AuditTrail::class)->shouldReceive('record')->andThrow(new RuntimeException('Private audit failure'));
        $this->getJson('/api/v1/patients/'.$id)->assertStatus(500)->assertJsonMissingPath('data');
    }

    public function test_existing_current_or_retired_numbers_are_unavailable_without_returning_owner_details(): void
    {
        $this->actingAs($this->registrar);
        $hidden = $this->patient(['access_status' => 'restricted']);
        PatientIdentifier::factory()->for($hidden)->create(['identifier' => 'CARD-001']);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/patients', $this->data()));
        $this->assertApiFailure($response, 422, 'validation_failed', ['mrn']);
        $this->assertStringNotContainsString($hidden->first_name, $response->getContent());
    }

    public function test_registration_requires_a_request_key_and_known_versions_on_edit(): void
    {
        $this->actingAs($this->registrar);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postJson('/api/v1/patients', $this->data()));
        $this->assertApiFailure($response, 422, 'validation_failed', ['idempotency_key']);
        $patient = $this->patient();
        $this->patchCommand('/api/v1/patients/'.$patient->id, ['address' => 'No version'])->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_a_submission_key_cannot_be_reused_for_a_different_edit_target(): void
    {
        $this->actingAs($this->registrar);
        $first = $this->patient();
        $second = $this->patient();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $data = ['version' => 1, 'address' => 'First'];
        $this->patchCommand('/api/v1/patients/'.$first->id, $data, $headers)->assertOk();
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->patchCommand('/api/v1/patients/'.$second->id, $data, $headers));
        $this->assertApiFailure($response, 409, 'idempotency_conflict');
    }

    public function test_submission_keys_are_isolated_by_actor(): void
    {
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->actingAs($this->registrar)->postCommand('/api/v1/patients', $this->data(), $headers)->assertCreated()->json('data.id');
        $other = $this->createStaffWithRole('frontdesk');
        $this->flushSession();
        $second = $this->actingAs($other)->postCommand('/api/v1/patients', array_replace($this->data(),
            ['mrn' => 'OTHER-001', 'first_name' => 'Other', 'phone' => '+2348022222222']), $headers)->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('idempotency_requests', 2);
    }

    public function test_more_than_twenty_duplicate_candidates_cannot_be_silently_acknowledged(): void
    {
        $this->actingAs($this->registrar);
        $candidates = Patient::factory()->count(21)->create(['created_by' => $this->registrar->id, 'category_id' => $this->category,
            'first_name' => 'Private-Sentinel', 'last_name' => 'Person', 'date_of_birth' => '2000-03-14']);
        $this->postJson('/api/v1/patients/duplicate-check', $this->duplicateData())->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.has_more', true);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/patients', $this->data()
            + ['acknowledged_duplicate_ids' => $candidates->take(20)->modelKeys()]));
        $this->assertApiFailure($response, 409, 'duplicate_review_required');
    }

    #[DataProvider('editFailureBoundaries')]
    public function test_audit_and_command_completion_failures_restore_the_entire_edit(string $table, string $operation): void
    {
        $this->actingAs($this->registrar);
        $patient = $this->patient();
        PatientContact::factory()->for($patient)->create();
        $hit = $this->failNextWriteTo($table, $operation);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->patchCommand('/api/v1/patients/'.$patient->id,
            ['version' => 1, 'address' => 'Must roll back', 'contacts' => []]));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertNoSuccessfulAudit('patient.updated');
        $this->assertAuditEventRecorded('patient.updated', $this->registrar->id, 'patient', $patient->id, 'failed');
    }

    public static function editFailureBoundaries(): array
    {
        return [['audit_events', 'insert'], ['idempotency_requests', 'update']];
    }

    public function test_patient_database_failures_do_not_copy_private_sql_or_bindings_into_logs(): void
    {
        $this->actingAs($this->registrar);
        Log::spy();
        $hit = false;
        DB::listen(function (QueryExecuted $event) use (&$hit): void {
            if (! $hit && preg_match('/^insert into [`"]patients[`"]/i', $event->sql) === 1) {
                $hit = true;
                throw new QueryException('testing', 'private SQL', ['Private-Sentinel'], new PDOException('Private row failure'));
            }
        });
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/patients', $this->data()));
        $this->assertTrue($hit);
        $this->assertApiFailure($response, 500, 'server_error');
        Log::shouldHaveReceived('error')->once()->with('A patient database operation failed.');
    }

    private function patient(array $attributes = []): Patient
    {
        return Patient::factory()->create($attributes + ['created_by' => $this->registrar->id, 'category_id' => $this->category]);
    }

    private function tables(): array
    {
        return ['patients', 'patient_identifiers', 'patient_contacts', 'patient_relationships', 'idempotency_requests'];
    }

    private function duplicateData(): array
    {
        return ['first_name' => 'Private-Sentinel', 'last_name' => 'Person', 'date_of_birth' => '2000-03-14', 'phone' => '+2348012345678'];
    }

    private function data(): array
    {
        return $this->duplicateData() + ['mrn' => 'CARD-001', 'category_id' => $this->category, 'email' => 'private@example.test',
            'contacts' => [['name' => 'Contact-Sentinel', 'relationship' => 'brother', 'phone' => '+2348000000000']]];
    }
}
