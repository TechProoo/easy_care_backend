# Database model v1.1

Proposed, not deployed. MySQL 8.4 integration and Laravel workflow tests remain required.

## departments

Staff organization and department-level requests.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: code

Foreign keys:

Checks:

## users

Laravel-authenticated staff; roles are assigned by authorized administrators.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| username | VARCHAR(64) NOT NULL |
| staff_code | VARCHAR(64) NULL |
| email | VARCHAR(191) NULL |
| password | VARCHAR(255) NOT NULL |
| first_name | VARCHAR(64) NOT NULL |
| last_name | VARCHAR(64) NOT NULL |
| middle_name | VARCHAR(64) NULL |
| is_active | BOOLEAN NOT NULL DEFAULT 1 |
| last_login_at | DATETIME(6) NULL |
| email_verified_at | DATETIME(6) NULL |
| remember_token | VARCHAR(100) NULL |
| version | INT NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| department_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: username; email; staff_code

Foreign keys: department_id → departments(id)

Checks: version > 0

## staff_profiles

Profile information; account completion is derived, not stored.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| designation | VARCHAR(191) NULL |
| sex | VARCHAR(64) NULL |
| address | TEXT NULL |
| state_country | VARCHAR(191) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| user_id | BIGINT UNSIGNED NOT NULL |
| photo_file_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: user_id

Foreign keys: user_id → users(id); photo_file_id → files(id)

Checks:

## staff_contacts

Primary and alternate phone numbers with optional country metadata.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| phone | VARCHAR(32) NOT NULL |
| country_iso | CHAR(2) NULL |
| country_dial_code | VARCHAR(8) NULL |
| label | VARCHAR(64) NOT NULL |
| priority | INT NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| user_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: user_id, priority

Foreign keys: user_id → users(id)

Checks: priority > 0

## roles

Authorization catalog.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: code

Foreign keys:

Checks:

## permissions

Authorization catalog.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: code

Foreign keys:

Checks:

## user_roles

Authorization pivot.

| Column | SQL type |
|---|---|
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| user_id | BIGINT UNSIGNED NOT NULL |
| role_id | BIGINT UNSIGNED NOT NULL |

Primary key: user_id, role_id

Unique keys:

Foreign keys: user_id → users(id); role_id → roles(id)

Checks:

## role_permissions

Authorization pivot.

| Column | SQL type |
|---|---|
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| role_id | BIGINT UNSIGNED NOT NULL |
| permission_id | BIGINT UNSIGNED NOT NULL |

Primary key: role_id, permission_id

Unique keys:

Foreign keys: role_id → roles(id); permission_id → permissions(id)

Checks:

## files

Private blob metadata for photos, clinical documents, tariff imports and exports.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| storage_path | VARCHAR(512) NOT NULL |
| original_name | VARCHAR(255) NOT NULL |
| mime_type | VARCHAR(100) NOT NULL |
| byte_size | BIGINT UNSIGNED NOT NULL |
| sha256 | CHAR(64) NOT NULL |
| scan_status | VARCHAR(64) NOT NULL DEFAULT 'pending' |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| uploaded_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: storage_path

Foreign keys: uploaded_by → users(id)

Checks:

## patient_categories

Commercial category, distinct from clinical IPD/OPD care setting.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: code

Foreign keys:

Checks:

## patients

Shared demographic record; one immutable internal ID across modules.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| mrn | VARCHAR(64) NOT NULL |
| first_name | VARCHAR(64) NOT NULL |
| last_name | VARCHAR(64) NOT NULL |
| middle_name | VARCHAR(64) NULL |
| sex | VARCHAR(64) NULL |
| date_of_birth | DATE NULL |
| dob_estimated | BOOLEAN NOT NULL DEFAULT 0 |
| email | VARCHAR(191) NULL |
| phone | VARCHAR(32) NULL |
| marital_status | VARCHAR(64) NULL |
| religion | VARCHAR(64) NULL |
| occupation | VARCHAR(191) NULL |
| state_of_origin | VARCHAR(191) NULL |
| address | TEXT NULL |
| life_status | VARCHAR(64) NOT NULL DEFAULT 'unknown' |
| deceased_at | DATETIME(6) NULL |
| access_status | VARCHAR(64) NOT NULL DEFAULT 'active' |
| version | INT NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| category_id | BIGINT UNSIGNED NOT NULL |
| created_by | BIGINT UNSIGNED NOT NULL |
| photo_file_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: mrn

Foreign keys: category_id → patient_categories(id); created_by → users(id); photo_file_id → files(id)

Checks: access_status IN ('active','restricted','merged'); life_status IN ('unknown','alive','deceased'); version > 0

## patient_identifiers

Old cards/MRNs retained for lookup after conversion or approved merge.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| identifier | VARCHAR(64) NOT NULL |
| kind | VARCHAR(64) NOT NULL |
| retired_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: identifier

Foreign keys: patient_id → patients(id)

Checks:

## patient_contacts

Emergency/next-of-kin contact details.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| name | VARCHAR(191) NOT NULL |
| relationship | VARCHAR(64) NOT NULL |
| phone | VARCHAR(32) NOT NULL |
| priority | INT NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: patient_id, priority

Foreign keys: patient_id → patients(id)

Checks: priority > 0

## patient_category_history

Card conversion history; internal patient ID never changes.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| changed_at | DATETIME(6) NOT NULL |
| reason | TEXT NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| previous_category_id | BIGINT UNSIGNED NOT NULL |
| new_category_id | BIGINT UNSIGNED NOT NULL |
| changed_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: patient_id → patients(id); previous_category_id → patient_categories(id); new_category_id → patient_categories(id); changed_by → users(id)

Checks:

## patient_access_events

Administrative access restrictions and authorized emergency overrides; not staff RBAC.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| action | VARCHAR(64) NOT NULL |
| reason | TEXT NOT NULL |
| occurred_at | DATETIME(6) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| actor_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: patient_id → patients(id); actor_id → users(id)

Checks: action IN ('restrict','restore','emergency_override')

## patient_merges

Approved source→target merge; retain original records and resolve canonical identity.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| reason | TEXT NOT NULL |
| approved_at | DATETIME(6) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| source_patient_id | BIGINT UNSIGNED NOT NULL |
| target_patient_id | BIGINT UNSIGNED NOT NULL |
| approved_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: source_patient_id

Foreign keys: source_patient_id → patients(id); target_patient_id → patients(id); approved_by → users(id)

Checks: source_patient_id <> target_patient_id

## patient_relationships

Mother/child or family links; never collapse relatives into one patient.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| relationship | VARCHAR(64) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| related_patient_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: patient_id, related_patient_id, relationship

Foreign keys: patient_id → patients(id); related_patient_id → patients(id)

Checks: patient_id <> related_patient_id

## patient_flags

Clinical/administrative flags with resolution history.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| type | VARCHAR(64) NOT NULL |
| reason | TEXT NOT NULL |
| resolved_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| created_by | BIGINT UNSIGNED NOT NULL |
| resolved_by | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys:

Foreign keys: patient_id → patients(id); created_by → users(id); resolved_by → users(id)

Checks:

## appointments

Bookings may precede registration; preserve contact snapshots.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| first_name | VARCHAR(64) NOT NULL |
| last_name | VARCHAR(64) NOT NULL |
| phone | VARCHAR(32) NOT NULL |
| starts_at | DATETIME(6) NOT NULL |
| ends_at | DATETIME(6) NULL |
| booking_channel | VARCHAR(64) NOT NULL |
| visit_type | VARCHAR(64) NOT NULL |
| specialist_name | VARCHAR(191) NULL |
| specialist_email | VARCHAR(191) NULL |
| status | VARCHAR(64) NOT NULL DEFAULT 'booked' |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NULL |
| doctor_id | BIGINT UNSIGNED NULL |
| booked_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: patient_id → patients(id); doctor_id → users(id); booked_by → users(id)

Checks: ends_at IS NULL OR ends_at > starts_at; status IN ('booked','confirmed','attended','cancelled','no_show')

## encounters

Visit/care episode; stores category-at-visit and care setting separately.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| visit_type | VARCHAR(64) NOT NULL |
| care_setting | VARCHAR(64) NOT NULL |
| status | VARCHAR(64) NOT NULL DEFAULT 'open' |
| opened_at | DATETIME(6) NOT NULL |
| closed_at | DATETIME(6) NULL |
| version | INT NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| appointment_id | BIGINT UNSIGNED NULL |
| category_id | BIGINT UNSIGNED NOT NULL |
| opened_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: appointment_id; id, patient_id

Foreign keys: patient_id → patients(id); appointment_id → appointments(id); category_id → patient_categories(id); opened_by → users(id)

Checks: care_setting IN ('OPD','IPD','EMERGENCY'); status IN ('open','in_progress','closed','cancelled'); closed_at IS NULL OR closed_at >= opened_at; version > 0

## doctor_assignments

Queue, sender/receiver and clinician assignment history.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| assigned_at | DATETIME(6) NOT NULL |
| accepted_at | DATETIME(6) NULL |
| ended_at | DATETIME(6) NULL |
| consultation_type | VARCHAR(64) NULL |
| status | VARCHAR(64) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| doctor_id | BIGINT UNSIGNED NOT NULL |
| assigned_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: encounter_id → encounters(id); doctor_id → users(id); assigned_by → users(id)

Checks:

## clinical_templates

Versioned validation schemas for all clinical form categories.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(191) NOT NULL |
| version | INT NOT NULL |
| schema_json | JSON NOT NULL |
| is_active | BOOLEAN NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: code, version

Foreign keys:

Checks: version > 0

## clinical_entries

Signed clinical form rows; dynamic keys use an approved versioned template.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| data | JSON NOT NULL |
| status | VARCHAR(64) NOT NULL DEFAULT 'draft' |
| observed_at | DATETIME(6) NOT NULL |
| signed_at | DATETIME(6) NULL |
| amendment_reason | TEXT NULL |
| version | INT NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| template_id | BIGINT UNSIGNED NOT NULL |
| author_id | BIGINT UNSIGNED NOT NULL |
| signed_by | BIGINT UNSIGNED NULL |
| amends_entry_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys:

Foreign keys: encounter_id → encounters(id); template_id → clinical_templates(id); author_id → users(id); signed_by → users(id); amends_entry_id → clinical_entries(id)

Checks: status IN ('draft','signed','voided'); status <> 'signed' OR (signed_by IS NOT NULL AND signed_at IS NOT NULL); version > 0

## vital_observations

Normalized clinical measurements for trends and reports.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| observed_at | DATETIME(6) NOT NULL |
| temperature_c | DECIMAL(5,2) NULL |
| pulse_bpm | INT NULL |
| respiratory_rate | INT NULL |
| systolic | INT NULL |
| diastolic | INT NULL |
| spo2 | DECIMAL(5,2) NULL |
| height_cm | DECIMAL(6,2) NULL |
| weight_kg | DECIMAL(7,3) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| recorded_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: encounter_id → encounters(id); recorded_by → users(id)

Checks: spo2 IS NULL OR (spo2 >= 0 AND spo2 <= 100); weight_kg IS NULL OR weight_kg > 0; height_cm IS NULL OR height_cm > 0

## diagnosis_catalog

Optional controlled diagnosis codes and human descriptions.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| coding_system | VARCHAR(64) NOT NULL |
| description | TEXT NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: coding_system, code

Foreign keys:

Checks:

## encounter_diagnoses

Encounter diagnosis with provisional/final status.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| description | TEXT NOT NULL |
| status | VARCHAR(64) NOT NULL |
| is_primary | BOOLEAN NOT NULL DEFAULT 0 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| diagnosis_id | BIGINT UNSIGNED NULL |
| recorded_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: encounter_id → encounters(id); diagnosis_id → diagnosis_catalog(id); recorded_by → users(id)

Checks:

## services

Billable service/procedure/investigation catalog.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| category | VARCHAR(64) NOT NULL |
| is_active | BOOLEAN NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: code

Foreign keys:

Checks:

## performed_procedures

Procedures actually performed, distinct from free-form surgical notes.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| performed_at | DATETIME(6) NOT NULL |
| details | TEXT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| service_id | BIGINT UNSIGNED NOT NULL |
| performed_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: encounter_id → encounters(id); service_id → services(id); performed_by → users(id)

Checks:

## documents

Patient/encounter references to private files; consistent patient enforced by composite FK.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| category | VARCHAR(64) NOT NULL |
| description | TEXT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| encounter_id | BIGINT UNSIGNED NULL |
| file_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: id, encounter_id

Foreign keys: patient_id → patients(id); encounter_id → encounters(id); file_id → files(id); encounter_id, patient_id → encounters(id, patient_id)

Checks:

## wards

Hospital ward catalog.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| type | VARCHAR(64) NULL |
| daily_rate | DECIMAL(14,2) NULL |
| is_active | BOOLEAN NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: code

Foreign keys:

Checks: daily_rate IS NULL OR daily_rate >= 0

## beds

Physical beds; availability derives from occupancy, not editable boolean.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| label | VARCHAR(64) NOT NULL |
| daily_rate | DECIMAL(14,2) NULL |
| is_active | BOOLEAN NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| ward_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: ward_id, label

Foreign keys: ward_id → wards(id)

Checks: daily_rate IS NULL OR daily_rate >= 0

## admissions

At most one admission per encounter; readmission uses a new encounter.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| status | VARCHAR(64) NOT NULL DEFAULT 'admitted' |
| admitted_at | DATETIME(6) NOT NULL |
| authorized_at | DATETIME(6) NULL |
| discharged_at | DATETIME(6) NULL |
| discharge_reason | TEXT NULL |
| discharge_summary | TEXT NULL |
| version | INT NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| admitted_by | BIGINT UNSIGNED NOT NULL |
| authorized_by | BIGINT UNSIGNED NULL |
| discharged_by | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: encounter_id

Foreign keys: encounter_id → encounters(id); admitted_by → users(id); authorized_by → users(id); discharged_by → users(id)

Checks: status IN ('admitted','discharge_authorized','discharged'); discharged_at IS NULL OR discharged_at >= admitted_at; status <> 'discharged' OR (discharged_at IS NOT NULL AND discharged_by IS NOT NULL AND authorized_by IS NOT NULL AND authorized_at IS NOT NULL)

## bed_allocations

Immutable assignment/release history.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| assigned_at | DATETIME(6) NOT NULL |
| released_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| bed_id | BIGINT UNSIGNED NOT NULL |
| admission_id | BIGINT UNSIGNED NOT NULL |
| assigned_by | BIGINT UNSIGNED NOT NULL |
| released_by | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: id, bed_id, admission_id

Foreign keys: bed_id → beds(id); admission_id → admissions(id); assigned_by → users(id); released_by → users(id)

Checks: released_at IS NULL OR released_at >= assigned_at

## current_bed_occupancies

One occupant per bed and one bed per admission.

| Column | SQL type |
|---|---|
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| bed_id | BIGINT UNSIGNED NOT NULL |
| admission_id | BIGINT UNSIGNED NOT NULL |
| allocation_id | BIGINT UNSIGNED NOT NULL |

Primary key: bed_id

Unique keys: admission_id; allocation_id

Foreign keys: bed_id → beds(id); admission_id → admissions(id); allocation_id → bed_allocations(id); allocation_id, bed_id, admission_id → bed_allocations(id, bed_id, admission_id)

Checks:

## care_programs

Antenatal, postnatal, immunization, family-planning longitudinal episodes.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| type | VARCHAR(64) NOT NULL |
| started_at | DATETIME(6) NOT NULL |
| ended_at | DATETIME(6) NULL |
| status | VARCHAR(64) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| created_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: patient_id → patients(id); created_by → users(id)

Checks:

## care_program_visits

Links visit, follow-up booking and structured report summary to a care program.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| summary | JSON NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| program_id | BIGINT UNSIGNED NOT NULL |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| next_appointment_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys:

Foreign keys: program_id → care_programs(id); encounter_id → encounters(id); next_appointment_id → appointments(id)

Checks:

## deliveries

Delivery episode on the maternal encounter; full staged notes remain template entries.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| delivered_at | DATETIME(6) NOT NULL |
| mode | VARCHAR(64) NOT NULL |
| details | JSON NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| doctor_id | BIGINT UNSIGNED NULL |
| nurse_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: id, encounter_id

Foreign keys: encounter_id → encounters(id); doctor_id → users(id); nurse_id → users(id)

Checks:

## births

One row per baby, including multiple births; child patient may be registered later.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| birth_order | INT NOT NULL |
| born_at | DATETIME(6) NOT NULL |
| sex | VARCHAR(64) NULL |
| weight_kg | DECIMAL(6,3) NULL |
| length_cm | DECIMAL(6,2) NULL |
| head_circumference_cm | DECIMAL(6,2) NULL |
| condition_at_birth | TEXT NULL |
| immunization_note | TEXT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| delivery_id | BIGINT UNSIGNED NOT NULL |
| child_patient_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: delivery_id, birth_order; child_patient_id

Foreign keys: delivery_id → deliveries(id); child_patient_id → patients(id)

Checks: birth_order > 0; weight_kg IS NULL OR weight_kg >= 0

## investigation_tests

Catalog of laboratory/radiology test definitions; schema version is explicit.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| department | VARCHAR(64) NOT NULL |
| specimen | VARCHAR(191) NULL |
| is_active | BOOLEAN NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| service_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: code

Foreign keys: service_id → services(id)

Checks:

## investigation_schemas

Immutable parameter/unit/reference definitions per test version.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| version | INT NOT NULL |
| schema_json | JSON NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| test_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: test_id, version; id, test_id

Foreign keys: test_id → investigation_tests(id)

Checks: version > 0

## investigation_orders

Encounter-linked clinician request.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| requested_at | DATETIME(6) NOT NULL |
| status | VARCHAR(64) NOT NULL |
| clinical_context | TEXT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| ordered_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: id, encounter_id

Foreign keys: encounter_id → encounters(id); ordered_by → users(id)

Checks:

## investigation_items

Individual requested test; result attaches here, not to catalog ID.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| priority | VARCHAR(64) NOT NULL |
| status | VARCHAR(64) NOT NULL |
| specimen_ref | VARCHAR(191) NULL |
| collected_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| order_id | BIGINT UNSIGNED NOT NULL |
| test_id | BIGINT UNSIGNED NOT NULL |
| encounter_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: id, test_id; id, encounter_id

Foreign keys: order_id → investigation_orders(id); test_id → investigation_tests(id); encounter_id → encounters(id); order_id, encounter_id → investigation_orders(id, encounter_id)

Checks:

## result_versions

Draft, verified and amended results with frozen reference units/ranges.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| version | INT NOT NULL |
| values_json | JSON NOT NULL |
| reference_snapshot | JSON NOT NULL |
| observed_at | DATETIME(6) NOT NULL |
| status | VARCHAR(64) NOT NULL DEFAULT 'draft' |
| released_at | DATETIME(6) NULL |
| amendment_reason | TEXT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| item_id | BIGINT UNSIGNED NOT NULL |
| test_id | BIGINT UNSIGNED NOT NULL |
| schema_id | BIGINT UNSIGNED NOT NULL |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| entered_by | BIGINT UNSIGNED NOT NULL |
| verified_by | BIGINT UNSIGNED NULL |
| attachment_id | BIGINT UNSIGNED NULL |
| supersedes_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: item_id, version

Foreign keys: item_id → investigation_items(id); test_id → investigation_tests(id); schema_id → investigation_schemas(id); encounter_id → encounters(id); entered_by → users(id); verified_by → users(id); attachment_id → documents(id); supersedes_id → result_versions(id); item_id, test_id → investigation_items(id, test_id); schema_id, test_id → investigation_schemas(id, test_id); item_id, encounter_id → investigation_items(id, encounter_id); attachment_id, encounter_id → documents(id, encounter_id)

Checks: version > 0; status IN ('draft','verified','released','superseded'); status <> 'released' OR (verified_by IS NOT NULL AND released_at IS NOT NULL)

## drugs

Medication catalog with category, type, strength and base-unit identity.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| category | VARCHAR(64) NULL |
| medication_type | VARCHAR(64) NULL |
| strength | VARCHAR(64) NULL |
| form | VARCHAR(64) NULL |
| base_unit | VARCHAR(64) NOT NULL |
| is_active | BOOLEAN NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: code

Foreign keys:

Checks:

## drug_prices

Dated selling price, retained for historical billing.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| effective_from | DATETIME(6) NOT NULL |
| effective_until | DATETIME(6) NULL |
| selling_price | DECIMAL(14,2) NOT NULL |
| currency | CHAR(3) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| drug_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: drug_id, effective_from

Foreign keys: drug_id → drugs(id)

Checks: selling_price >= 0; effective_until IS NULL OR effective_until > effective_from

## stores

Physical main/dispense stores; department requisitions request from a store.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| department_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: code

Foreign keys: department_id → departments(id)

Checks:

## store_drug_settings

Per-store reorder thresholds, independent of a batch.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| reorder_level | DECIMAL(14,3) NOT NULL DEFAULT 0 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| store_id | BIGINT UNSIGNED NOT NULL |
| drug_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: store_id, drug_id

Foreign keys: store_id → stores(id); drug_id → drugs(id)

Checks: reorder_level >= 0

## drug_batches

Lot and expiry traceability.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| lot_no | VARCHAR(64) NOT NULL |
| expires_on | DATE NOT NULL |
| received_at | DATETIME(6) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| drug_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: drug_id, lot_no; id, drug_id

Foreign keys: drug_id → drugs(id)

Checks:

## stock_balances

Cached available quantity; reconciled to immutable movement ledger.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| quantity | DECIMAL(14,3) NOT NULL DEFAULT 0 |
| version | INT NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| store_id | BIGINT UNSIGNED NOT NULL |
| batch_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: store_id, batch_id

Foreign keys: store_id → stores(id); batch_id → drug_batches(id)

Checks: quantity >= 0; version > 0

## stock_movements

Append-only stock ledger; every business operation has a unique source line key.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| signed_quantity | DECIMAL(14,3) NOT NULL |
| movement_type | VARCHAR(64) NOT NULL |
| source_key | VARCHAR(191) NOT NULL |
| reason | TEXT NULL |
| occurred_at | DATETIME(6) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| balance_id | BIGINT UNSIGNED NOT NULL |
| actor_id | BIGINT UNSIGNED NOT NULL |
| reversal_of_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: source_key

Foreign keys: balance_id → stock_balances(id); actor_id → users(id); reversal_of_id → stock_movements(id)

Checks: signed_quantity <> 0

## suppliers

Supplier organization and primary/alternate contact details.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| name | VARCHAR(191) NOT NULL |
| contact_title | VARCHAR(64) NULL |
| organization_name | VARCHAR(191) NULL |
| email | VARCHAR(191) NULL |
| office_address | TEXT NULL |
| phone | VARCHAR(32) NULL |
| alternate_phone | VARCHAR(32) NULL |
| country_iso | CHAR(2) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys:

Foreign keys:

Checks:

## purchases

Supplier invoice received into stock; supplier invoice number is not globally unique.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| invoice_number | VARCHAR(64) NOT NULL |
| status | VARCHAR(64) NOT NULL DEFAULT 'draft' |
| received_at | DATETIME(6) NULL |
| currency | CHAR(3) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| supplier_id | BIGINT UNSIGNED NOT NULL |
| received_by | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: supplier_id, invoice_number

Foreign keys: supplier_id → suppliers(id); received_by → users(id)

Checks:

## purchase_items

Receipts retain units-per-pack, packs and unit-cost/price snapshots.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| units_per_pack | DECIMAL(14,3) NOT NULL |
| packs_purchased | DECIMAL(14,3) NOT NULL |
| quantity | DECIMAL(14,3) NOT NULL |
| unit_cost | DECIMAL(14,2) NOT NULL |
| selling_price_snapshot | DECIMAL(14,2) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| purchase_id | BIGINT UNSIGNED NOT NULL |
| batch_id | BIGINT UNSIGNED NOT NULL |
| store_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: purchase_id → purchases(id); batch_id → drug_batches(id); store_id → stores(id)

Checks: units_per_pack > 0; packs_purchased > 0; quantity > 0; unit_cost >= 0; selling_price_snapshot >= 0

## prescriptions

Clinician medication intent; signing required before dispensing.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| status | VARCHAR(64) NOT NULL DEFAULT 'draft' |
| signed_at | DATETIME(6) NULL |
| version | INT NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| prescriber_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: id, encounter_id

Foreign keys: encounter_id → encounters(id); prescriber_id → users(id)

Checks: status IN ('draft','signed','cancelled','completed'); status <> 'signed' OR signed_at IS NOT NULL

## prescription_items

Clinical dosage/route/frequency/duration plus authorized dispensing quantity.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| dosage | VARCHAR(191) NOT NULL |
| route | VARCHAR(64) NOT NULL |
| frequency | VARCHAR(191) NOT NULL |
| duration | VARCHAR(191) NOT NULL |
| quantity | DECIMAL(14,3) NOT NULL |
| instructions | TEXT NULL |
| status | VARCHAR(64) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| prescription_id | BIGINT UNSIGNED NOT NULL |
| drug_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: id, prescription_id; id, drug_id

Foreign keys: prescription_id → prescriptions(id); drug_id → drugs(id)

Checks: quantity > 0

## dispenses

Pharmacy stock issue, distinct from medication administration.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| dispensed_at | DATETIME(6) NOT NULL |
| idempotency_key | VARCHAR(191) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| prescription_id | BIGINT UNSIGNED NOT NULL |
| store_id | BIGINT UNSIGNED NOT NULL |
| dispensed_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: idempotency_key; id, prescription_id

Foreign keys: prescription_id → prescriptions(id); store_id → stores(id); dispensed_by → users(id)

Checks:

## dispense_items

Each issue links one prescription line and batch; supports partial dispensing.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| quantity | DECIMAL(14,3) NOT NULL |
| unit_price | DECIMAL(14,2) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| dispense_id | BIGINT UNSIGNED NOT NULL |
| prescription_id | BIGINT UNSIGNED NOT NULL |
| prescription_item_id | BIGINT UNSIGNED NOT NULL |
| batch_id | BIGINT UNSIGNED NOT NULL |
| drug_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: id, prescription_item_id

Foreign keys: dispense_id → dispenses(id); prescription_id → prescriptions(id); prescription_item_id → prescription_items(id); batch_id → drug_batches(id); drug_id → drugs(id); dispense_id, prescription_id → dispenses(id, prescription_id); prescription_item_id, prescription_id → prescription_items(id, prescription_id); prescription_item_id, drug_id → prescription_items(id, drug_id); batch_id, drug_id → drug_batches(id, drug_id)

Checks: quantity > 0; unit_price >= 0

## medication_administrations

Nursing dose served/given/declined/missed; does not re-deduct pharmacy stock.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| scheduled_at | DATETIME(6) NULL |
| administered_at | DATETIME(6) NOT NULL |
| quantity | DECIMAL(14,3) NULL |
| dose_unit | VARCHAR(64) NULL |
| status | VARCHAR(64) NOT NULL |
| remark | TEXT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| prescription_item_id | BIGINT UNSIGNED NOT NULL |
| dispense_item_id | BIGINT UNSIGNED NULL |
| administered_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: prescription_item_id → prescription_items(id); dispense_item_id → dispense_items(id); administered_by → users(id); dispense_item_id, prescription_item_id → dispense_items(id, prescription_item_id)

Checks: quantity IS NULL OR quantity > 0

## vaccinations

Reporting projection of vaccine administration linked to the actual dose event.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| vaccine_name | VARCHAR(191) NOT NULL |
| age_grade | VARCHAR(64) NULL |
| dose | VARCHAR(64) NOT NULL |
| route | VARCHAR(64) NOT NULL |
| site | VARCHAR(64) NULL |
| administered_at | DATETIME(6) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| administration_id | BIGINT UNSIGNED NULL |
| batch_id | BIGINT UNSIGNED NULL |
| administered_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: administration_id

Foreign keys: encounter_id → encounters(id); administration_id → medication_administrations(id); batch_id → drug_batches(id); administered_by → users(id)

Checks:

## requisitions

Department OR patient requests for medication; not a clinician prescription.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| request_scope | VARCHAR(64) NOT NULL |
| care_setting | VARCHAR(64) NULL |
| status | VARCHAR(64) NOT NULL DEFAULT 'draft' |
| requested_at | DATETIME(6) NOT NULL |
| requester_name | VARCHAR(191) NULL |
| requester_phone | VARCHAR(32) NULL |
| remark | TEXT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| department_id | BIGINT UNSIGNED NULL |
| encounter_id | BIGINT UNSIGNED NULL |
| requested_by | BIGINT UNSIGNED NOT NULL |
| store_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: department_id → departments(id); encounter_id → encounters(id); requested_by → users(id); store_id → stores(id)

Checks: request_scope IN ('department','patient'); (request_scope = 'department' AND department_id IS NOT NULL AND encounter_id IS NULL) OR (request_scope = 'patient' AND encounter_id IS NOT NULL)

## requisition_items

Requested drug amounts and units; fulfillment must be explicitly approved.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| quantity | DECIMAL(14,3) NOT NULL |
| unit | VARCHAR(64) NOT NULL |
| unit_price_snapshot | DECIMAL(14,2) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| requisition_id | BIGINT UNSIGNED NOT NULL |
| drug_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: requisition_id → requisitions(id); drug_id → drugs(id)

Checks: quantity > 0; unit_price_snapshot IS NULL OR unit_price_snapshot >= 0

## stock_transfers

Store-to-store movements; posted transaction produces matched ledger entries.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| status | VARCHAR(64) NOT NULL DEFAULT 'draft' |
| posted_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| from_store_id | BIGINT UNSIGNED NOT NULL |
| to_store_id | BIGINT UNSIGNED NOT NULL |
| posted_by | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys:

Foreign keys: from_store_id → stores(id); to_store_id → stores(id); posted_by → users(id)

Checks: from_store_id <> to_store_id

## stock_transfer_items

Batch quantity moved between stores.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| quantity | DECIMAL(14,3) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| transfer_id | BIGINT UNSIGNED NOT NULL |
| batch_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: transfer_id → stock_transfers(id); batch_id → drug_batches(id)

Checks: quantity > 0

## stock_adjustments

Damage, expiry, count corrections, patient returns and department issues.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| type | VARCHAR(64) NOT NULL |
| reason | TEXT NOT NULL |
| status | VARCHAR(64) NOT NULL |
| posted_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| approved_by | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys:

Foreign keys: approved_by → users(id)

Checks:

## stock_adjustment_items

Reasoned batch adjustment; returns link original dispense and carry disposition.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| quantity_delta | DECIMAL(14,3) NOT NULL |
| disposition | VARCHAR(64) NULL |
| refund_amount | DECIMAL(14,2) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| adjustment_id | BIGINT UNSIGNED NOT NULL |
| balance_id | BIGINT UNSIGNED NOT NULL |
| dispense_item_id | BIGINT UNSIGNED NULL |
| requisition_item_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys:

Foreign keys: adjustment_id → stock_adjustments(id); balance_id → stock_balances(id); dispense_item_id → dispense_items(id); requisition_item_id → requisition_items(id)

Checks: quantity_delta <> 0; refund_amount IS NULL OR refund_amount >= 0

## hmo_providers

Insurer/company/staff/private payer organizations as displayed by the HMO screens.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| payer_type | VARCHAR(64) NOT NULL |
| preauth_required | BOOLEAN NOT NULL DEFAULT 0 |
| office_address | TEXT NULL |
| phone | VARCHAR(32) NULL |
| alternate_phone | VARCHAR(32) NULL |
| contact_person | VARCHAR(191) NULL |
| email | VARCHAR(191) NULL |
| billing_frequency | VARCHAR(64) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |

Primary key: id

Unique keys: code

Foreign keys:

Checks:

## tariff_imports

Private source file, parse status and validation errors; never filename-only.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| status | VARCHAR(64) NOT NULL |
| validation_errors | JSON NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| provider_id | BIGINT UNSIGNED NOT NULL |
| file_id | BIGINT UNSIGNED NOT NULL |
| imported_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: provider_id → hmo_providers(id); file_id → files(id); imported_by → users(id)

Checks:

## insurance_plans

Provider plan/treatment guide.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| code | VARCHAR(64) NOT NULL |
| name | VARCHAR(191) NOT NULL |
| treatment_guide | TEXT NULL |
| is_active | BOOLEAN NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| provider_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: provider_id, code

Foreign keys: provider_id → hmo_providers(id)

Checks:

## patient_coverages

Time-bound membership; retain policy, employer, group and eligibility details.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| member_no | VARCHAR(64) NOT NULL |
| group_number | VARCHAR(64) NULL |
| employer_name | VARCHAR(191) NULL |
| valid_from | DATE NOT NULL |
| valid_until | DATE NULL |
| verification_status | VARCHAR(64) NOT NULL |
| verified_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| plan_id | BIGINT UNSIGNED NOT NULL |
| verified_by | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: id, patient_id

Foreign keys: patient_id → patients(id); plan_id → insurance_plans(id); verified_by → users(id)

Checks: valid_until IS NULL OR valid_until >= valid_from

## tariffs

Dated plan/service price; overlapping periods require a locked application check.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| effective_from | DATE NOT NULL |
| effective_until | DATE NULL |
| price | DECIMAL(14,2) NOT NULL |
| currency | CHAR(3) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| plan_id | BIGINT UNSIGNED NOT NULL |
| service_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: plan_id, service_id, effective_from

Foreign keys: plan_id → insurance_plans(id); service_id → services(id)

Checks: price >= 0; effective_until IS NULL OR effective_until > effective_from

## preauthorizations

Insurer approval for encounter service.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| reference | VARCHAR(191) NOT NULL |
| status | VARCHAR(64) NOT NULL |
| approved_amount | DECIMAL(14,2) NULL |
| expires_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| coverage_id | BIGINT UNSIGNED NOT NULL |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| patient_id | BIGINT UNSIGNED NOT NULL |
| service_id | BIGINT UNSIGNED NOT NULL |
| recorded_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: coverage_id → patient_coverages(id); encounter_id → encounters(id); patient_id → patients(id); service_id → services(id); recorded_by → users(id); coverage_id, patient_id → patient_coverages(id, patient_id); encounter_id, patient_id → encounters(id, patient_id)

Checks: approved_amount IS NULL OR approved_amount >= 0

## invoices

Visit invoice; totals computed from frozen line items and allocated receipts.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| invoice_no | VARCHAR(64) NOT NULL |
| currency | CHAR(3) NOT NULL |
| status | VARCHAR(64) NOT NULL DEFAULT 'draft' |
| issued_at | DATETIME(6) NULL |
| version | INT NOT NULL DEFAULT 1 |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| encounter_id | BIGINT UNSIGNED NOT NULL |
| coverage_id | BIGINT UNSIGNED NULL |
| posted_by | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: invoice_no; id, patient_id

Foreign keys: patient_id → patients(id); encounter_id → encounters(id); coverage_id → patient_coverages(id); posted_by → users(id); encounter_id, patient_id → encounters(id, patient_id); coverage_id, patient_id → patient_coverages(id, patient_id)

Checks: status IN ('draft','posted','voided'); version > 0

## invoice_items

Service/duration quantities, historical prices, discounts and patient/insurer shares.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| description | VARCHAR(191) NOT NULL |
| quantity | DECIMAL(14,3) NOT NULL |
| unit_price | DECIMAL(14,2) NOT NULL |
| discount | DECIMAL(14,2) NOT NULL DEFAULT 0 |
| net_amount | DECIMAL(14,2) NOT NULL |
| patient_share | DECIMAL(14,2) NOT NULL |
| insurer_share | DECIMAL(14,2) NOT NULL |
| billing_source_key | VARCHAR(191) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| invoice_id | BIGINT UNSIGNED NOT NULL |
| service_id | BIGINT UNSIGNED NOT NULL |
| preauthorization_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys: billing_source_key

Foreign keys: invoice_id → invoices(id); service_id → services(id); preauthorization_id → preauthorizations(id)

Checks: quantity > 0; unit_price >= 0; discount >= 0; net_amount >= 0; patient_share >= 0; insurer_share >= 0; patient_share + insurer_share = net_amount; discount <= quantity * unit_price

## receipts

Patient deposits/payments independent of invoice issuance; original payer details retained.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| receipt_no | VARCHAR(64) NOT NULL |
| type | VARCHAR(64) NOT NULL |
| amount | DECIMAL(14,2) NOT NULL |
| currency | CHAR(3) NOT NULL |
| method | VARCHAR(64) NOT NULL |
| reference | VARCHAR(191) NULL |
| payer_name | VARCHAR(191) NOT NULL |
| payer_phone | VARCHAR(32) NULL |
| paid_at | DATETIME(6) NOT NULL |
| description | TEXT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| patient_id | BIGINT UNSIGNED NOT NULL |
| received_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: receipt_no; id, patient_id

Foreign keys: patient_id → patients(id); received_by → users(id)

Checks: amount > 0; type IN ('deposit','payment')

## payment_allocations

Deposit/payment can pay multiple invoices; cross-patient allocation is forbidden.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| amount | DECIMAL(14,2) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| receipt_id | BIGINT UNSIGNED NOT NULL |
| invoice_id | BIGINT UNSIGNED NOT NULL |
| patient_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: receipt_id, invoice_id

Foreign keys: receipt_id → receipts(id); invoice_id → invoices(id); patient_id → patients(id); receipt_id, patient_id → receipts(id, patient_id); invoice_id, patient_id → invoices(id, patient_id)

Checks: amount > 0

## refunds

Authorized return of unallocated receipt funds; never edit the original receipt.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| amount | DECIMAL(14,2) NOT NULL |
| reason | TEXT NOT NULL |
| refunded_at | DATETIME(6) NOT NULL |
| reference | VARCHAR(191) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| receipt_id | BIGINT UNSIGNED NOT NULL |
| approved_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: receipt_id → receipts(id); approved_by → users(id)

Checks: amount > 0

## claims

Initial scope: one claim per invoice with event-based resubmission history.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| status | VARCHAR(64) NOT NULL |
| external_ref | VARCHAR(191) NULL |
| submitted_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| invoice_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: invoice_id

Foreign keys: invoice_id → invoices(id)

Checks:

## claim_events

Claim submission, partial approval, rejection and resubmission history.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| from_status | VARCHAR(64) NULL |
| to_status | VARCHAR(64) NOT NULL |
| reason | TEXT NULL |
| occurred_at | DATETIME(6) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| claim_id | BIGINT UNSIGNED NOT NULL |
| actor_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys:

Foreign keys: claim_id → claims(id); actor_id → users(id)

Checks:

## claim_items

Line-level claim decision and authorized amount; retains invoice-line relationship.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| decision | VARCHAR(64) NOT NULL |
| approved_amount | DECIMAL(14,2) NULL |
| reason | TEXT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| claim_id | BIGINT UNSIGNED NOT NULL |
| invoice_item_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: claim_id, invoice_item_id

Foreign keys: claim_id → claims(id); invoice_item_id → invoice_items(id)

Checks: approved_amount IS NULL OR approved_amount >= 0

## claim_settlements

Insurer payments with immutable references.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| amount | DECIMAL(14,2) NOT NULL |
| received_at | DATETIME(6) NOT NULL |
| reference | VARCHAR(191) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| claim_id | BIGINT UNSIGNED NOT NULL |
| recorded_by | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: claim_id, reference

Foreign keys: claim_id → claims(id); recorded_by → users(id)

Checks: amount > 0

## reminders

Patient or personal recurring schedules; delivery is local by default.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| title | VARCHAR(191) NOT NULL |
| note | TEXT NULL |
| due_at | DATETIME(6) NOT NULL |
| snooze_minutes | INT NULL |
| recurrence_rule | VARCHAR(191) NULL |
| status | VARCHAR(64) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| owner_id | BIGINT UNSIGNED NOT NULL |
| patient_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys:

Foreign keys: owner_id → users(id); patient_id → patients(id)

Checks: snooze_minutes IS NULL OR snooze_minutes > 0

## notifications

Recipient-scoped notifications, deduplicated by source event.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| event_key | VARCHAR(191) NOT NULL |
| event_type | VARCHAR(64) NOT NULL |
| payload | JSON NOT NULL |
| read_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| recipient_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: recipient_id, event_key

Foreign keys: recipient_id → users(id)

Checks:

## report_exports

Authorized export lifecycle; reauthentication may be required for sensitive reports.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| report_type | VARCHAR(64) NOT NULL |
| filters | JSON NOT NULL |
| status | VARCHAR(64) NOT NULL |
| expires_at | DATETIME(6) NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| requested_by | BIGINT UNSIGNED NOT NULL |
| file_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys:

Foreign keys: requested_by → users(id); file_id → files(id)

Checks:

## audit_events

Restricted append-only access/change audit. subject_type/id are explicitly logical references.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| action | VARCHAR(191) NOT NULL |
| subject_type | VARCHAR(64) NOT NULL |
| subject_id | BIGINT UNSIGNED NULL |
| request_id | VARCHAR(64) NOT NULL |
| occurred_at | DATETIME(6) NOT NULL |
| safe_summary | JSON NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| actor_id | BIGINT UNSIGNED NULL |

Primary key: id

Unique keys:

Foreign keys: actor_id → users(id)

Checks:

## idempotency_requests

Atomic command deduplication; complete record/resource outcome inside command transaction.

| Column | SQL type |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| route | VARCHAR(191) NOT NULL |
| request_key | VARCHAR(191) NOT NULL |
| request_hash | CHAR(64) NOT NULL |
| status | VARCHAR(64) NOT NULL |
| response_code | INT NULL |
| resource_type | VARCHAR(64) NULL |
| resource_id | BIGINT UNSIGNED NULL |
| expires_at | DATETIME(6) NOT NULL |
| created_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| updated_at | DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) |
| user_id | BIGINT UNSIGNED NOT NULL |

Primary key: id

Unique keys: user_id, route, request_key

Foreign keys: user_id → users(id)

Checks:
