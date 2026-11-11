<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\PatientContact;
use App\Models\PatientFlag;
use App\Models\PatientMergeRequest;
use App\Models\PatientRelationship;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientRecordManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $permissions = array_filter(array_keys(config('authorization.permissions')), fn (string $code): bool => str_starts_with($code, 'patients.'));
        $this->grantRolePermissions('frontdesk', $permissions);
        $this->requester = $this->createStaffWithRole('frontdesk', ['password' => 'Secret-password-123']);
        $this->reviewer = $this->createStaffWithRole('frontdesk', ['password' => 'Secret-password-123']);
        $this->withSession(['password_hash_web' => $this->requester->password])->actingAs($this->requester);
    }

    public function test_flags_keep_original_reason_and_record_one_resolution_without_sensitive_audit(): void
    {
        $patient = $this->patient();
        $flag = $this->command($patient, 'flags', ['type' => 'identity-review'])->assertCreated()->assertJsonMissingPath('data.reason')->json('data.record_id');
        $this->getJson($this->url($patient, 'flags'))->assertOk()->assertJsonPath('data.0.reason', 'Private reason sentinel')->assertHeader('Cache-Control', 'no-store, private');
        $this->command($patient, 'flags/'.$flag.'/resolve', ['version' => 2, 'reason' => 'Resolved private sentinel'])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertDatabaseHas('patient_flags', ['id' => $flag, 'reason' => 'Private reason sentinel', 'resolution_reason' => 'Resolved private sentinel']);
        $this->assertApiFailure($this->command($patient, 'flags/'.$flag.'/resolve', ['version' => 3]), 409, 'flag_already_resolved');
        $this->assertAuditEventRecorded('patient.flagged', $this->requester->id, 'patient', $patient->id, summary: ['record_id' => $flag, 'version' => 2]);
        $this->assertAuditExcludes(['Private reason sentinel', 'Resolved private sentinel', 'identity-review']);
    }

    public function test_flags_are_scoped_and_histories_validate_pagination(): void
    {
        $patient = $this->patient();
        $other = $this->patient();
        $flag = PatientFlag::factory()->for($other)->create(['created_by' => $this->requester->id]);
        $this->assertApiFailure($this->command($patient, 'flags/'.$flag->id.'/resolve'), 404, 'not_found');
        foreach (['flags', 'card-history', 'merge-requests'] as $path) {
            $this->getJson($this->url($patient, $path).'?per_page=101&page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page']);
        }
    }

    public function test_restriction_and_restore_only_expose_metadata_and_preserve_history(): void
    {
        $patient = $this->patient(['first_name' => 'Hidden-sentinel']);
        $data = $this->data();
        $this->postCommand($this->url($patient, 'access/restrict'), $data, ['Idempotency-Key' => 'access-key-000001'])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/patients/'.$patient->id)->assertNotFound();
        $this->getJson('/api/v1/patients?mrn='.$patient->mrn)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url($patient, 'access-state'))->assertOk()->assertExactJson(['data' => ['id' => $patient->id, 'version' => 2, 'access_status' => 'restricted']]);
        $replay = $this->postCommand($this->url($patient, 'access/restrict'), $data, ['Idempotency-Key' => 'access-key-000001'])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertStringNotContainsString('Hidden-sentinel', $replay->getContent());
        foreach (['flags', 'card-history', 'merge-requests'] as $path) {
            $this->getJson($this->url($patient, $path))->assertNotFound();
        }
        $this->command($patient, 'access/restore', ['version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $this->getJson('/api/v1/patients/'.$patient->id)->assertOk();
        $this->assertDatabaseCount('patient_access_events', 2);
        $this->assertApiFailure($this->command($patient, 'access/restore', ['version' => 3]), 409, 'invalid_patient_transition');
        $this->assertAuditExcludes(['Hidden-sentinel', 'Private reason sentinel']);
    }

    public function test_card_changes_reserve_all_numbers_and_keep_category_history(): void
    {
        $patient = $this->patient(['mrn' => 'ORIGINAL-001']);
        $category = PatientCategory::where('code', 'hmo')->sole()->id;
        $this->command($patient, 'card', ['mrn' => ' new/001 ', 'category_id' => $category])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'mrn' => 'NEW/001', 'category_id' => $category]);
        $this->assertDatabaseMissing('patient_identifiers', ['identifier' => 'ORIGINAL-001', 'retired_at' => null]);
        $this->assertDatabaseHas('patient_identifiers', ['patient_id' => $patient->id, 'identifier' => 'NEW/001', 'retired_at' => null]);
        $this->getJson('/api/v1/patients?mrn=ORIGINAL-001')->assertOk()->assertJsonPath('data.0.id', $patient->id);
        $this->getJson($this->url($patient, 'card-history'))->assertOk()->assertJsonPath('data.0.previous_mrn', 'ORIGINAL-001')->assertJsonPath('data.0.new_mrn', 'NEW/001');
        $this->command($patient, 'card', ['version' => 2, 'mrn' => 'ORIGINAL-001', 'category_id' => $category])->assertUnprocessable()->assertJsonValidationErrors('mrn');
        $this->command($patient, 'card', ['version' => 2, 'mrn' => 'NEW/001', 'category_id' => $patient->category_id])->assertOk();
        $this->assertDatabaseCount('patient_identifiers', 2);
        $this->assertDatabaseCount('patient_category_history', 2);
        $this->assertAuditExcludes(['ORIGINAL-001', 'NEW/001', 'Private reason sentinel']);
    }

    public function test_card_noop_and_number_owned_by_another_patient_have_no_effect(): void
    {
        $patient = $this->patient();
        $other = $this->patient();
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->command($patient, 'card', ['category_id' => $patient->category_id]));
        $this->assertApiFailure($response, 409, 'no_patient_change');
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->command($patient, 'card', ['category_id' => $patient->category_id, 'mrn' => $other->mrn]));
        $response->assertUnprocessable()->assertJsonValidationErrors('mrn');
    }

    public function test_merge_requires_an_independent_reviewer_and_preserves_original_records(): void
    {
        $source = $this->patient(['first_name' => 'Original-private-sentinel']);
        $target = $this->patient(['first_name' => 'Surviving-patient']);
        PatientContact::factory()->for($source)->create();
        $proposal = $this->propose($source, $target);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->review($source, $proposal, 'approve'));
        $response->assertForbidden();
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $data = $this->data(['version' => 2, 'request_version' => 1]);
        $this->postCommand($this->url($source, 'merge-requests/'.$proposal.'/approve'), $data, ['Idempotency-Key' => 'merge-key-000001'])->assertOk()->assertJsonPath('data.version', 3);
        $this->postCommand($this->url($source, 'merge-requests/'.$proposal.'/approve'), $data, ['Idempotency-Key' => 'merge-key-000001'])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseHas('patients', ['id' => $source->id, 'access_status' => 'merged', 'mrn' => $source->mrn, 'first_name' => 'Original-private-sentinel']);
        $this->assertDatabaseHas('patients', ['id' => $target->id, 'access_status' => 'active', 'version' => 2, 'first_name' => 'Surviving-patient']);
        $this->assertDatabaseHas('patient_contacts', ['patient_id' => $source->id]);
        $this->assertDatabaseCount('patient_identifiers', 2);
        $this->assertDatabaseCount('patient_merges', 1);
        $lookup = $this->getJson('/api/v1/patients/'.$source->id)->assertOk()->assertJsonPath('data.id', $target->id)->assertJsonPath('meta.canonical_id', $target->id);
        $this->assertStringNotContainsString('Original-private-sentinel', $lookup->getContent());
        $this->getJson('/api/v1/patients?mrn='.$source->mrn)->assertOk()->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/v1/patients?q=Original-private-sentinel')->assertOk()->assertJsonCount(0, 'data');
        $this->patchCommand('/api/v1/patients/'.$source->id, ['version' => 3, 'address' => 'wrong target'])->assertNotFound();
        $this->command($source, 'access/restore', ['version' => 3])->assertConflict();
        $this->assertAuditExcludes(['Original-private-sentinel', 'Private reason sentinel', $source->mrn]);
    }

    #[DataProvider('staleMergeSides')]
    public function test_merge_rechecks_both_versions_before_approval(string $side): void
    {
        $source = $this->patient();
        $target = $this->patient();
        $proposal = $this->propose($source, $target);
        $changed = $side === 'source' ? $source : $target;
        $this->patchCommand('/api/v1/patients/'.$changed->id, ['version' => $side === 'source' ? 2 : 1, 'address' => 'Updated'])->assertOk();
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->review($source, $proposal, 'approve', ['version' => $source->fresh()->version]));
        $this->assertApiFailure($response, 409, 'version_conflict');
    }

    public static function staleMergeSides(): array
    {
        return [['source'], ['target']];
    }

    public function test_merge_reject_and_cancel_have_single_review_and_allow_a_new_proposal(): void
    {
        $source = $this->patient();
        $target = $this->patient();
        $proposal = $this->propose($source, $target);
        $this->command($source, 'merge-requests', ['version' => 2, 'target_patient_id' => $target->id, 'target_version' => 1])->assertConflict();
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $this->review($source, $proposal, 'cancel')->assertForbidden();
        $this->review($source, $proposal, 'reject')->assertOk();
        $this->review($source, $proposal, 'approve', ['version' => 3, 'request_version' => 2])->assertConflict();
        $this->withSession(['password_hash_web' => $this->requester->password])->actingAs($this->requester);
        $next = $this->propose($source->fresh(), $target);
        $this->review($source, $next, 'cancel', ['version' => 4])->assertOk();
        $this->assertDatabaseCount('patient_merges', 0);
        $this->assertDatabaseHas('patient_merge_requests', ['id' => $next, 'status' => 'cancelled', 'reviewed_by' => $this->requester->id]);
    }

    #[DataProvider('mergeBlockers')]
    public function test_merges_block_unresolved_source_flags_and_family_links(string $blocker): void
    {
        $source = $this->patient();
        $target = $this->patient();
        if ($blocker === 'flag') {
            PatientFlag::factory()->for($source)->create(['created_by' => $this->requester->id]);
        } else {
            PatientRelationship::factory()->create(['patient_id' => $source->id, 'related_patient_id' => $target->id]);
        }
        $proposal = $this->propose($source, $target);
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->review($source, $proposal, 'approve'));
        $this->assertApiFailure($response, 409, 'merge_review_required');
    }

    public static function mergeBlockers(): array
    {
        return [['flag'], ['family']];
    }

    public function test_alias_chains_resolve_and_fail_closed_when_the_final_identity_is_restricted(): void
    {
        $first = $this->patient();
        $middle = $this->patient();
        $last = $this->patient();
        $proposal = $this->propose($first, $middle);
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $this->review($first, $proposal, 'approve')->assertOk();
        $this->withSession(['password_hash_web' => $this->requester->password])->actingAs($this->requester);
        $proposal = $this->propose($middle->fresh(), $last);
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $this->review($middle, $proposal, 'approve', ['version' => 3])->assertOk();
        $this->getJson('/api/v1/patients/'.$first->id)->assertOk()->assertJsonPath('data.id', $last->id);
        $this->command($last, 'access/restrict', ['version' => 2])->assertOk();
        $this->getJson('/api/v1/patients/'.$first->id)->assertNotFound();
        $this->getJson('/api/v1/patients?mrn='.$first->mrn)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_merged_hidden_or_self_targets_and_cross_patient_proposal_ids_are_rejected(): void
    {
        $source = $this->patient();
        $hidden = $this->patient(['access_status' => 'restricted']);
        $this->command($source, 'merge-requests', ['target_patient_id' => $source->id, 'target_version' => 1])->assertConflict();
        $this->command($source, 'merge-requests', ['target_patient_id' => $hidden->id, 'target_version' => 1])->assertNotFound();
        $target = $this->patient();
        $proposal = $this->propose($source, $target);
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $this->review($target, $proposal, 'approve', ['version' => 1])->assertNotFound();
        $this->command($target, 'access/restrict')->assertOk();
        $this->getJson($this->url($source, 'merge-requests'))->assertOk()->assertJsonMissingPath('data.0.reason');
        $this->review($source, $proposal, 'approve')->assertNotFound();
        $this->review($source, $proposal, 'reject')->assertOk();
    }

    #[DataProvider('writeCases')]
    public function test_commands_require_explicit_permissions_reauthentication_reason_and_current_version(string $path, array $extra, string $permission): void
    {
        $patient = $this->patient();
        $data = $this->data($extra);
        if ($path === 'flags') {
            unset($data['current_password']);
        }
        if ($path === 'card') {
            $data['category_id'] = PatientCategory::where('code', 'hmo')->sole()->id;
        }
        if ($path === 'merge-requests') {
            $data['target_patient_id'] = $this->patient()->id;
        }
        if ($path !== 'flags') {
            $this->postCommand($this->url($patient, $path), array_replace($data, ['current_password' => 'incorrect']))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }
        $this->postCommand($this->url($patient, $path), array_replace($data, ['reason' => '']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postCommand($this->url($patient, $path), array_replace($data, ['version' => 999]))->assertConflict();
        $this->postCommand($this->url($patient, $path), array_replace($data, ['created_by' => 999]))->assertUnprocessable()->assertJsonValidationErrors('created_by');
        $role = Role::where('code', 'frontdesk')->sole();
        $role->permissions()->detach(Permission::where('code', $permission)->sole());
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand($this->url($patient, $path), $data));
        $response->assertForbidden();
    }

    public static function writeCases(): array
    {
        return [['flags', ['type' => 'identity-review'], 'patients.flags.manage'],
            ['access/restrict', [], 'patients.access.manage'], ['card', [], 'patients.cards.manage'],
            ['merge-requests', ['target_version' => 1], 'patients.merges.request']];
    }

    public function test_all_new_permissions_are_opt_in_and_flags_manage_requires_flags_view(): void
    {
        $admin = $this->createStaffWithRole('admin');
        $patient = $this->patient();
        $this->withSession(['password_hash_web' => $admin->password])->actingAs($admin);
        $this->getJson($this->url($patient, 'access-state'))->assertForbidden();
        $this->command($patient, 'flags', ['type' => 'identity-review'])->assertForbidden();
        $this->withSession(['password_hash_web' => $this->requester->password])->actingAs($this->requester);
        Role::where('code', 'frontdesk')->sole()->permissions()->detach(Permission::where('code', 'patients.flags.view')->sole());
        $this->command($patient, 'flags', ['type' => 'identity-review'])->assertForbidden();
        $this->getJson($this->url($patient, 'flags'))->assertForbidden();
    }

    public function test_idempotency_checks_nested_targets_changed_body_expiry_and_permission_revocation(): void
    {
        $patient = $this->patient();
        $one = PatientFlag::factory()->for($patient)->create(['created_by' => $this->requester->id]);
        $two = PatientFlag::factory()->for($patient)->create(['created_by' => $this->requester->id]);
        $data = $this->data();
        unset($data['current_password']);
        $path = $this->url($patient, 'flags/'.$one->id.'/resolve');
        $this->postCommand($path, $data, ['Idempotency-Key' => 'nested-key-000001'])->assertOk();
        $this->postCommand($path, $data, ['Idempotency-Key' => 'nested-key-000001'])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertApiFailure($this->postCommand($this->url($patient, 'flags/'.$two->id.'/resolve'), $data, ['Idempotency-Key' => 'nested-key-000001']), 409, 'idempotency_conflict');
        $this->assertApiFailure($this->postCommand($path, array_replace($data, ['reason' => 'Different reason']), ['Idempotency-Key' => 'nested-key-000001']), 409, 'idempotency_conflict');
        DB::table('idempotency_requests')->update(['expires_at' => now('UTC')->subSecond()]);
        $this->assertApiFailure($this->postCommand($path, $data, ['Idempotency-Key' => 'nested-key-000001']), 409, 'idempotency_expired');
        Role::where('code', 'frontdesk')->sole()->permissions()->detach(Permission::where('code', 'patients.flags.manage')->sole());
        $this->postCommand($path, $data, ['Idempotency-Key' => 'nested-key-000001'])->assertForbidden();
        $row = (array) DB::table('idempotency_requests')->sole();
        $this->assertStringNotContainsString('Private reason sentinel', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function test_approval_confirmation_expires_when_the_survivor_changes(): void
    {
        $source = $this->patient();
        $target = $this->patient();
        $proposal = $this->propose($source, $target);
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $path = $this->url($source, 'merge-requests/'.$proposal.'/approve');
        $data = $this->data(['version' => 2, 'request_version' => 1]);
        $this->postCommand($path, $data, ['Idempotency-Key' => 'approve-key-000001'])->assertOk();
        $this->patchCommand('/api/v1/patients/'.$target->id, ['version' => 2, 'address' => 'Changed'])->assertOk();
        $this->assertApiFailure($this->postCommand($path, $data, ['Idempotency-Key' => 'approve-key-000001']), 409, 'idempotency_outdated');
    }

    #[DataProvider('rollbackCases')]
    public function test_failures_roll_back_domain_history_audit_and_command_completion(string $operation, string $table, string $event, string $write): void
    {
        $patient = $this->patient();
        $data = [];
        $path = $operation;
        if ($operation === 'flags') {
            $data['type'] = 'identity-review';
        } elseif ($operation === 'card') {
            $data = ['mrn' => 'ROLLBACK-NEW', 'category_id' => PatientCategory::where('code', 'hmo')->sole()->id];
        } elseif ($operation === 'merge-requests') {
            $data = ['target_patient_id' => $this->patient()->id, 'target_version' => 1];
        } elseif ($operation === 'approve') {
            $proposal = $this->propose($patient, $this->patient());
            $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
            $path = 'merge-requests/'.$proposal.'/approve';
            $data = ['version' => 2, 'request_version' => 1];
        }
        $wasHit = $this->failNextWriteTo($table, $write);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->command($patient, $path, $data));
        $this->assertTrue($wasHit());
        $response->assertServerError();
        $this->assertNoSuccessfulAudit($event);
    }

    public static function rollbackCases(): array
    {
        return [['flags', 'patient_flags', 'patient.flagged', 'insert'], ['card', 'patient_category_history', 'patient.card_changed', 'insert'],
            ['card', 'patients', 'patient.card_changed', 'update'], ['access/restrict', 'patient_access_events', 'patient.access_restricted', 'insert'],
            ['merge-requests', 'patient_merge_requests', 'patient.merge_requested', 'insert'], ['approve', 'patient_merges', 'patient.merge_approved', 'insert'],
            ['approve', 'audit_events', 'patient.merge_approved', 'insert'], ['approve', 'idempotency_requests', 'patient.merge_approved', 'update']];
    }

    #[DataProvider('immutableTables')]
    public function test_database_rejects_history_rewrites_and_deletes(string $table, string $operation): void
    {
        $source = $this->patient();
        $target = $this->patient();
        $flag = $this->command($source, 'flags', ['type' => 'identity-review'])->assertCreated()->json('data.record_id');
        $this->command($source, 'flags/'.$flag.'/resolve', ['version' => 2])->assertOk();
        $this->command($source, 'card', ['version' => 3, 'category_id' => PatientCategory::where('code', 'hmo')->sole()->id])->assertOk();
        $this->command($source, 'access/restrict', ['version' => 4])->assertOk();
        $this->command($source, 'access/restore', ['version' => 5])->assertOk();
        $proposal = $this->propose($source->fresh(), $target);
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $this->review($source, $proposal, 'approve', ['version' => 7])->assertOk();
        $this->expectException(QueryException::class);
        $query = DB::table($table);
        if ($operation === 'delete') {
            $query->delete();
        } else {
            $query->update(['reason' => 'private reason sentinel']);
        }
    }

    public static function immutableTables(): array
    {
        $cases = [];
        foreach (['patient_flags', 'patient_category_history', 'patient_access_events', 'patient_merge_requests', 'patient_merges'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $cases[] = [$table, $operation];
            }
        }

        return $cases;
    }

    public function test_database_requires_independent_review_even_when_the_api_is_bypassed(): void
    {
        $source = $this->patient();
        $target = $this->patient();
        $proposal = $this->propose($source, $target);
        $this->expectException(QueryException::class);
        DB::table('patient_merge_requests')->where('id', $proposal)->update(['status' => 'approved', 'version' => 2,
            'reviewed_by' => $this->requester->id, 'review_reason' => 'Attempted self approval', 'reviewed_at' => now('UTC')]);
    }

    public function test_database_prevents_multiple_pending_proposals_for_the_same_source(): void
    {
        $source = $this->patient();
        $target = $this->patient();
        $this->propose($source, $target);
        $this->expectException(QueryException::class);
        PatientMergeRequest::factory()->create(['source_patient_id' => $source->id, 'target_patient_id' => $target->id,
            'requested_by' => $this->requester->id]);
    }

    public function test_view_only_access_does_not_include_private_histories_or_access_metadata(): void
    {
        $patient = $this->patient();
        $this->grantRolePermissions('doctor', ['patients.view']);
        $viewer = $this->createStaffWithRole('doctor');
        $this->withSession(['password_hash_web' => $viewer->password])->actingAs($viewer);
        $this->getJson('/api/v1/patients/'.$patient->id)->assertOk()->assertJsonMissingPath('data.flags')->assertJsonMissingPath('data.access_events');
        foreach (['flags', 'card-history', 'access-state', 'merge-requests'] as $path) {
            $this->getJson($this->url($patient, $path))->assertForbidden();
        }
    }

    public function test_replays_require_the_current_password_and_current_record_version(): void
    {
        $patient = $this->patient();
        $path = $this->url($patient, 'access/restrict');
        $data = $this->data();
        $headers = ['Idempotency-Key' => 'current-key-000001'];
        $this->postCommand($path, $data, $headers)->assertOk();
        $this->postCommand($path, array_replace($data, ['current_password' => 'wrong']), $headers)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->command($patient, 'access/restore', ['version' => 2])->assertOk();
        $this->assertApiFailure($this->postCommand($path, $data, $headers), 409, 'idempotency_outdated');
    }

    public function test_reverse_merging_an_alias_cannot_create_a_cycle(): void
    {
        $source = $this->patient();
        $target = $this->patient();
        $proposal = $this->propose($source, $target);
        $this->withSession(['password_hash_web' => $this->reviewer->password])->actingAs($this->reviewer);
        $this->review($source, $proposal, 'approve')->assertOk();
        $this->command($target, 'merge-requests', ['version' => 2, 'target_patient_id' => $source->id, 'target_version' => 3])->assertNotFound();
        $this->assertDatabaseCount('patient_merges', 1);
        $this->getJson('/api/v1/patients/'.$source->id)->assertOk()->assertJsonPath('data.id', $target->id);
    }

    public function test_inconsistent_merge_state_fails_closed_in_canonical_lookup(): void
    {
        $merge = \App\Models\PatientMerge::factory()->create();
        $this->getJson('/api/v1/patients/'.$merge->source_patient_id)->assertOk()->assertJsonPath('data.id', $merge->target_patient_id);
        DB::table('patients')->where('id', $merge->source_patient_id)->update(['access_status' => 'active']);
        $this->getJson('/api/v1/patients/'.$merge->source_patient_id)->assertNotFound();
    }

    public function test_requester_can_cancel_a_known_pending_proposal_after_restriction_without_reading_details(): void
    {
        $source = $this->patient();
        $proposal = $this->propose($source, $this->patient());
        $this->command($source, 'access/restrict', ['version' => 2])->assertOk();
        $this->getJson($this->url($source, 'merge-requests'))->assertNotFound();
        $this->review($source, $proposal, 'cancel', ['version' => 3])->assertOk()->assertJsonMissingPath('data.reason')->assertJsonMissingPath('data.first_name');
        $this->assertDatabaseHas('patients', ['id' => $source->id, 'access_status' => 'restricted', 'version' => 4]);
    }

    private function patient(array $changes = []): Patient
    {
        return Patient::factory()->create(array_replace(['created_by' => $this->requester->id, 'category_id' => PatientCategory::where('code', 'private')->sole()->id], $changes));
    }

    private function url(Patient $patient, string $path): string
    {
        return '/api/v1/patients/'.$patient->id.'/'.$path;
    }

    private function data(array $changes = []): array
    {
        return array_replace(['version' => 1, 'reason' => 'Private reason sentinel', 'current_password' => 'Secret-password-123'], $changes);
    }

    private function command(Patient $patient, string $path, array $changes = []): TestResponse
    {
        $data = $this->data($changes);
        if (str_starts_with($path, 'flags')) {
            unset($data['current_password']);
        }

        return $this->postCommand($this->url($patient, $path), $data);
    }

    private function propose(Patient $source, Patient $target): int
    {
        return $this->command($source, 'merge-requests', ['version' => $source->version, 'target_patient_id' => $target->id, 'target_version' => $target->version])->assertCreated()->json('data.record_id');
    }

    private function review(Patient $source, int $proposal, string $decision, array $changes = []): TestResponse
    {
        return $this->command($source, 'merge-requests/'.$proposal.'/'.$decision, array_replace(['version' => 2, 'request_version' => 1], $changes));
    }

    private function tables(): array
    {
        return ['patients', 'patient_contacts', 'patient_identifiers', 'patient_relationships', 'patient_flags',
            'patient_category_history', 'patient_access_events', 'patient_merge_requests', 'patient_merges', 'idempotency_requests'];
    }
}
