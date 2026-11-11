<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\PatientContact;
use App\Models\PatientIdentifier;
use App\Models\PatientRelationship;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PatientFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private PatientCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrar = User::factory()->create();
        $this->category = PatientCategory::factory()->create();
    }

    public function test_demographics_and_relationships_survive_reloading_in_both_directions(): void
    {
        $parent = $this->patient(['date_of_birth' => '1990-03-14', 'dob_estimated' => true]);
        $child = $this->patient(['date_of_birth' => '2020-08-22']);
        $contact = PatientContact::factory()->for($parent)->create(['priority' => 2]);
        $first = PatientContact::factory()->for($parent)->create(['priority' => 1]);
        $link = PatientRelationship::factory()->for($parent)->for($child, 'relatedPatient')->create();
        $parent = $parent->fresh(['category', 'createdBy', 'identifiers', 'currentIdentifier', 'contacts', 'relationships.relatedPatient']);

        $this->assertTrue($parent->category->is($this->category));
        $this->assertTrue($parent->createdBy->is($this->registrar));
        $this->assertTrue($this->category->patients->contains($parent));
        $this->assertTrue($this->registrar->registeredPatients->contains($parent));
        $this->assertTrue($parent->identifiers->sole()->patient->is($parent));
        $this->assertSame($parent->mrn, $parent->currentIdentifier->identifier);
        $this->assertSame([$first->id, $contact->id], $parent->contacts->modelKeys());
        $this->assertTrue($contact->patient->is($parent));
        $this->assertTrue($parent->relationships->sole()->relatedPatient->is($child));
        $this->assertTrue($link->patient->is($parent));
        $this->assertTrue($child->incomingRelationships->sole()->is($link));
        $this->assertCount(0, $child->relationships);
        $this->assertSame('1990-03-14', $parent->date_of_birth->format('Y-m-d'));
        $this->assertSame('1990-03-14', $parent->toArray()['date_of_birth']);
        $this->assertTrue($parent->dob_estimated);
        $this->assertSame(1, $parent->version);
        $this->assertSame('unknown', $parent->life_status);
        $this->assertSame('active', $parent->access_status);
        $this->assertDatabaseCount('patients', 2);
    }

    public function test_direct_database_creation_reserves_the_current_number_without_model_events(): void
    {
        $id = DB::table('patients')->insertGetId($this->patientData(['mrn' => 'RAW/001']));
        $this->assertDatabaseHas('patient_identifiers', ['patient_id' => $id, 'identifier' => 'RAW/001', 'kind' => 'mrn', 'retired_at' => null]);
        $this->assertDatabaseCount('patient_identifiers', 1);
    }

    public function test_number_change_preserves_identity_and_all_old_numbers(): void
    {
        $patient = $this->patient(['mrn' => 'CARD-001']);
        $patient->mrn = ' new/002 ';
        $patient->save();
        $patient->refresh();
        $this->assertSame('NEW/002', $patient->mrn);
        $this->assertSame('NEW/002', $patient->currentIdentifier->identifier);
        $old = $patient->identifiers()->where('identifier', 'CARD-001')->sole();
        $this->assertNotNull($old->retired_at);
        $this->assertSame($patient->id, $old->patient_id);
        $this->assertSame($patient->id, Patient::whereHas('identifiers', fn (Builder $query): Builder => $query->where('identifier', 'CARD-001'))->sole()->id);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('patient_identifiers', 2);
        $patient->first_name = 'Updated';
        $patient->save();
        $this->assertDatabaseCount('patient_identifiers', 2);
    }

    public function test_a_current_number_cannot_be_reserved_again_as_an_old_number(): void
    {
        $owner = $this->patient();
        $other = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patients', 'patient_identifiers'], fn () => PatientIdentifier::factory()->for($other)->create(['identifier' => $owner->mrn]));
    }

    public function test_a_new_patient_cannot_reuse_a_historical_number(): void
    {
        $owner = $this->patient();
        $alias = PatientIdentifier::factory()->for($owner)->create(['identifier' => 'RETIRED-001']);
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patients', 'patient_identifiers'], fn () => DB::table('patients')->insert($this->patientData(['mrn' => $alias->identifier])));
    }

    public function test_two_current_patients_cannot_share_a_number(): void
    {
        $owner = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patients', 'patient_identifiers'], fn () => DB::table('patients')->insert($this->patientData(['mrn' => $owner->mrn])));
    }

    public function test_a_number_change_cannot_claim_another_patients_historical_number(): void
    {
        $owner = $this->patient();
        PatientIdentifier::factory()->for($owner)->create(['identifier' => 'RESERVED-001']);
        $target = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patients', 'patient_identifiers'], fn () => DB::table('patients')->where('id', $target->id)->update(['mrn' => 'RESERVED-001', 'first_name' => 'Must roll back']));
    }

    public function test_a_patient_cannot_reuse_its_own_retired_number(): void
    {
        $patient = $this->patient(['mrn' => 'BEFORE-001']);
        $patient->mrn = 'AFTER-001';
        $patient->save();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patients', 'patient_identifiers'], fn () => DB::table('patients')->where('id', $patient->id)->update(['mrn' => 'BEFORE-001']));
    }

    #[DataProvider('invalidPatients')]
    public function test_invalid_patient_state_is_rejected_by_the_database(array $attributes): void
    {
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patients', 'patient_identifiers'], fn () => DB::table('patients')->insert($this->patientData($attributes)));
    }

    public static function invalidPatients(): array
    {
        return [
            'empty number' => [['mrn' => '']],
            'lowercase raw number' => [['mrn' => 'card-001']],
            'padded raw number' => [['mrn' => ' CARD-001 ']],
            'noncanonical punctuation' => [['mrn' => 'CARD 001']],
            'control character' => [['mrn' => "CARD-001\n"]],
            'non-ascii number' => [['mrn' => "CARD-\u{00e9}"]],
            'too long number' => [['mrn' => str_repeat('A', 65)]],
            'blank first name' => [['first_name' => ' ']],
            'blank last name' => [['last_name' => '']],
            'zero version' => [['version' => 0]],
            'invalid access' => [['access_status' => 'disabled']],
            'incorrect access casing' => [['access_status' => 'ACTIVE']],
            'invalid life status' => [['life_status' => 'eligible']],
            'incorrect life casing' => [['life_status' => 'ALIVE']],
            'estimate without date' => [['dob_estimated' => true, 'date_of_birth' => null]],
            'invalid boolean' => [['dob_estimated' => 2, 'date_of_birth' => '2000-01-01']],
            'death timestamp while alive' => [['life_status' => 'alive', 'deceased_at' => '2026-01-01 00:00:00']],
            'missing category' => [['category_id' => 99999999]],
            'missing creator' => [['created_by' => 99999999]],
            'missing photo' => [['photo_file_id' => 99999999]],
        ];
    }

    #[DataProvider('immutableIdentifierChanges')]
    public function test_old_numbers_cannot_be_rewritten_reassigned_or_reactivated(string $change): void
    {
        $patient = $this->patient();
        $other = $this->patient();
        $identifier = PatientIdentifier::factory()->for($patient)->create();
        $attributes = match ($change) {
            'number' => ['identifier' => 'REPLACEMENT-001'],
            'owner' => ['patient_id' => $other->id],
            'kind' => ['kind' => 'mrn'],
            'reactivate' => ['retired_at' => null],
            'retirement' => ['retired_at' => '2000-01-01 00:00:00'],
        };
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_identifiers'], fn () => DB::table('patient_identifiers')->where('id', $identifier->id)->update($attributes));
    }

    public static function immutableIdentifierChanges(): array
    {
        return [['number'], ['owner'], ['kind'], ['reactivate'], ['retirement']];
    }

    public function test_number_reservations_cannot_be_deleted(): void
    {
        $patient = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patients', 'patient_identifiers'], fn () => $patient->identifiers()->delete());
    }

    public function test_the_current_number_cannot_be_retired_without_changing_the_patient_number(): void
    {
        $patient = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_identifiers'], fn () => $patient->identifiers()->update(['retired_at' => now()]));
    }

    public function test_an_unrelated_number_cannot_be_added_as_an_extra_current_number(): void
    {
        $patient = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_identifiers'], fn () => PatientIdentifier::factory()->for($patient)->create(['kind' => 'mrn', 'retired_at' => null]));
    }

    public function test_an_identifier_cannot_reference_a_missing_patient(): void
    {
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_identifiers'], fn () => PatientIdentifier::factory()->create(['patient_id' => 99999999]));
    }

    public function test_noncanonical_historical_numbers_are_rejected_without_model_normalization(): void
    {
        $patient = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_identifiers'], fn () => DB::table('patient_identifiers')->insert([
            'identifier' => 'lower-case', 'patient_id' => $patient->id, 'kind' => 'legacy_mrn', 'retired_at' => now(),
        ]));
    }

    public function test_long_unicode_names_and_unknown_birth_dates_are_preserved_without_conflating_statuses(): void
    {
        $name = str_repeat("\u{00e9}", 64);
        $patient = $this->patient(['first_name' => $name, 'last_name' => $name, 'date_of_birth' => null,
            'life_status' => 'alive', 'access_status' => 'restricted']);
        $patient->refresh();
        $this->assertSame($name, $patient->first_name);
        $this->assertSame($name, $patient->last_name);
        $this->assertNull($patient->date_of_birth);
        $this->assertFalse($patient->dob_estimated);
        $this->assertNull($patient->deceased_at);
        $this->assertSame('alive', $patient->life_status);
        $this->assertSame('restricted', $patient->access_status);
    }

    public function test_patient_updates_cannot_bypass_the_database_integrity_guard(): void
    {
        $patient = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patients', 'patient_identifiers'], fn () => DB::table('patients')->where('id', $patient->id)
            ->update(['version' => 0, 'first_name' => 'Must roll back']));
    }

    public function test_category_codes_are_unique(): void
    {
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_categories'], fn () => PatientCategory::factory()->create(['code' => $this->category->code]));
    }

    public function test_category_codes_require_the_same_canonical_casing_on_both_databases(): void
    {
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_categories'], fn () => PatientCategory::factory()->create(['code' => 'PRIVATE']));
    }

    public function test_contact_priorities_are_unique_per_patient_but_can_repeat_for_different_patients(): void
    {
        $patient = $this->patient();
        PatientContact::factory()->for($patient)->create();
        PatientContact::factory()->for($this->patient())->create();
        $this->assertDatabaseCount('patient_contacts', 2);
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_contacts'], fn () => PatientContact::factory()->for($patient)->create());
    }

    #[DataProvider('invalidContacts')]
    public function test_invalid_or_orphaned_contacts_are_rejected(array $attributes): void
    {
        $patient = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_contacts'], fn () => PatientContact::factory()->for($patient)->create($attributes));
    }

    public static function invalidContacts(): array
    {
        return [[['priority' => 0]], [['priority' => -1]], [['name' => ' ']], [['phone' => '']],
            [['relationship' => '']], [['patient_id' => 99999999]]];
    }

    public function test_relationships_cannot_point_to_self(): void
    {
        $patient = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_relationships'], fn () => PatientRelationship::factory()->for($patient)->for($patient, 'relatedPatient')->create());
    }

    public function test_duplicate_relationships_are_rejected_without_collapsing_distinct_relatives(): void
    {
        $parent = $this->patient();
        $children = [$this->patient(), $this->patient()];
        foreach ($children as $child) {
            PatientRelationship::factory()->for($parent)->for($child, 'relatedPatient')->create();
        }
        $this->assertDatabaseCount('patients', 3);
        $this->assertCount(2, $parent->relationships);
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_relationships'], fn () => PatientRelationship::factory()->for($parent)->for($children[0], 'relatedPatient')->create());
    }

    public function test_missing_related_patients_are_rejected(): void
    {
        $patient = $this->patient();
        $this->expectException(QueryException::class);
        $this->assertTablesUnchanged(['patient_relationships'], fn () => PatientRelationship::factory()->for($patient)->create(['related_patient_id' => 99999999]));
    }

    #[DataProvider('referencedParents')]
    public function test_referenced_records_cannot_be_hard_deleted(string $relation): void
    {
        $patient = $this->patient();
        $target = match ($relation) {
            'patient' => $patient,
            'category' => $this->category,
            'creator' => $this->registrar,
        };
        $this->expectException(QueryException::class);
        $target->delete();
    }

    public static function referencedParents(): array
    {
        return [['patient'], ['category'], ['creator']];
    }

    public function test_an_outer_failure_rolls_back_demographics_number_reservations_contacts_and_links(): void
    {
        $related = $this->patient();
        $this->expectException(RuntimeException::class);
        $this->assertTablesUnchanged(['patients', 'patient_identifiers', 'patient_contacts', 'patient_relationships'], function () use ($related): void {
            DB::transaction(function () use ($related): void {
                $patient = $this->patient();
                PatientContact::factory()->for($patient)->create();
                PatientRelationship::factory()->for($patient)->for($related, 'relatedPatient')->create();
                $patient->mrn = 'ROLLED-BACK-001';
                $patient->save();
                throw new RuntimeException('Synthetic domain failure.');
            });
        });
    }

    public function test_categories_seed_repeatably_without_overwriting_local_edits_or_creating_patients(): void
    {
        $this->seed(DatabaseSeeder::class);
        $category = PatientCategory::where('code', 'private')->sole();
        $category->update(['name' => 'Hospital private category']);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('patient_categories', 8);
        $this->assertDatabaseHas('patient_categories', ['code' => 'private', 'name' => 'Hospital private category']);
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('patient_identifiers', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('permissions', 19);
    }

    public function test_demographic_mass_assignment_cannot_choose_identity_access_or_attribution(): void
    {
        $patient = $this->patient();
        $patient->fill(['first_name' => 'Allowed', 'mrn' => 'SPOOF-001', 'access_status' => 'merged',
            'life_status' => 'deceased', 'deceased_at' => now(), 'category_id' => 99999999,
            'created_by' => 99999999, 'photo_file_id' => 99999999, 'version' => 999]);
        $this->assertSame(['first_name'], array_keys($patient->getDirty()));
    }

    private function patient(array $attributes = []): Patient
    {
        return Patient::factory()->for($this->category, 'category')->for($this->registrar, 'createdBy')->create($attributes);
    }

    private function patientData(array $attributes = []): array
    {
        return array_replace(['mrn' => 'TEST-RAW-001', 'first_name' => 'Synthetic', 'last_name' => 'Patient',
            'category_id' => $this->category->id, 'created_by' => $this->registrar->id], $attributes);
    }
}
