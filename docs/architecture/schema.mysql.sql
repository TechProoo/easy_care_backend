-- EasyCare reviewed schema proposal v1.1; NOT an applied Laravel migration.

-- Target MySQL 8.4 / InnoDB. Import only into a NEW disposable schema first.

-- No DROP/DELETE statements. Runtime sessions/jobs/cache are created by Laravel migrations.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- Staff organization and department-level requests.
CREATE TABLE `departments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_departments_0` UNIQUE (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Laravel-authenticated staff; roles are assigned by authorized administrators.
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `staff_code` VARCHAR(64) NULL,
  `email` VARCHAR(191) NULL,
  `password` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(64) NOT NULL,
  `last_name` VARCHAR(64) NOT NULL,
  `middle_name` VARCHAR(64) NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT 1,
  `last_login_at` DATETIME(6) NULL,
  `email_verified_at` DATETIME(6) NULL,
  `remember_token` VARCHAR(100) NULL,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `department_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_users_0` UNIQUE (`username`),
  CONSTRAINT `uq_users_1` UNIQUE (`email`),
  CONSTRAINT `uq_users_2` UNIQUE (`staff_code`),
  CONSTRAINT `ck_users_0` CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Profile information; account completion is derived, not stored.
CREATE TABLE `staff_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `designation` VARCHAR(191) NULL,
  `sex` VARCHAR(64) NULL,
  `address` TEXT NULL,
  `state_country` VARCHAR(191) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `user_id` BIGINT UNSIGNED NOT NULL,
  `photo_file_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_staff_profiles_0` UNIQUE (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Primary and alternate phone numbers with optional country metadata.
CREATE TABLE `staff_contacts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(32) NOT NULL,
  `country_iso` CHAR(2) NULL,
  `country_dial_code` VARCHAR(8) NULL,
  `label` VARCHAR(64) NOT NULL,
  `priority` INT NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `user_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_staff_contacts_0` UNIQUE (`user_id`, `priority`),
  CONSTRAINT `ck_staff_contacts_0` CHECK (priority > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Authorization catalog.
CREATE TABLE `roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_roles_0` UNIQUE (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Authorization catalog.
CREATE TABLE `permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_permissions_0` UNIQUE (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Authorization pivot.
CREATE TABLE `user_roles` (
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Authorization pivot.
CREATE TABLE `role_permissions` (
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Private blob metadata for photos, clinical documents, tariff imports and exports.
CREATE TABLE `files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `storage_path` VARCHAR(512) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `byte_size` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `scan_status` VARCHAR(64) NOT NULL DEFAULT 'pending',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `uploaded_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_files_0` UNIQUE (`storage_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Commercial category, distinct from clinical IPD/OPD care setting.
CREATE TABLE `patient_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_patient_categories_0` UNIQUE (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Shared demographic record; one immutable internal ID across modules.
CREATE TABLE `patients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mrn` VARCHAR(64) NOT NULL,
  `first_name` VARCHAR(64) NOT NULL,
  `last_name` VARCHAR(64) NOT NULL,
  `middle_name` VARCHAR(64) NULL,
  `sex` VARCHAR(64) NULL,
  `date_of_birth` DATE NULL,
  `dob_estimated` BOOLEAN NOT NULL DEFAULT 0,
  `email` VARCHAR(191) NULL,
  `phone` VARCHAR(32) NULL,
  `marital_status` VARCHAR(64) NULL,
  `religion` VARCHAR(64) NULL,
  `occupation` VARCHAR(191) NULL,
  `state_of_origin` VARCHAR(191) NULL,
  `address` TEXT NULL,
  `life_status` VARCHAR(64) NOT NULL DEFAULT 'unknown',
  `deceased_at` DATETIME(6) NULL,
  `access_status` VARCHAR(64) NOT NULL DEFAULT 'active',
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `category_id` BIGINT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `photo_file_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_patients_0` UNIQUE (`mrn`),
  CONSTRAINT `ck_patients_0` CHECK (access_status IN ('active','restricted','merged')),
  CONSTRAINT `ck_patients_1` CHECK (life_status IN ('unknown','alive','deceased')),
  CONSTRAINT `ck_patients_2` CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Old cards/MRNs retained for lookup after conversion or approved merge.
CREATE TABLE `patient_identifiers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(64) NOT NULL,
  `kind` VARCHAR(64) NOT NULL,
  `retired_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_patient_identifiers_0` UNIQUE (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Emergency/next-of-kin contact details.
CREATE TABLE `patient_contacts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `relationship` VARCHAR(64) NOT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `priority` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_patient_contacts_0` UNIQUE (`patient_id`, `priority`),
  CONSTRAINT `ck_patient_contacts_0` CHECK (priority > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Card conversion history; internal patient ID never changes.
CREATE TABLE `patient_category_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `changed_at` DATETIME(6) NOT NULL,
  `reason` TEXT NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `previous_category_id` BIGINT UNSIGNED NOT NULL,
  `new_category_id` BIGINT UNSIGNED NOT NULL,
  `changed_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Administrative access restrictions and authorized emergency overrides; not staff RBAC.
CREATE TABLE `patient_access_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(64) NOT NULL,
  `reason` TEXT NOT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_patient_access_events_0` CHECK (action IN ('restrict','restore','emergency_override'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Approved source→target merge; retain original records and resolve canonical identity.
CREATE TABLE `patient_merges` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reason` TEXT NOT NULL,
  `approved_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `source_patient_id` BIGINT UNSIGNED NOT NULL,
  `target_patient_id` BIGINT UNSIGNED NOT NULL,
  `approved_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_patient_merges_0` UNIQUE (`source_patient_id`),
  CONSTRAINT `ck_patient_merges_0` CHECK (source_patient_id <> target_patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Mother/child or family links; never collapse relatives into one patient.
CREATE TABLE `patient_relationships` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `relationship` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `related_patient_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_patient_relationships_0` UNIQUE (`patient_id`, `related_patient_id`, `relationship`),
  CONSTRAINT `ck_patient_relationships_0` CHECK (patient_id <> related_patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Clinical/administrative flags with resolution history.
CREATE TABLE `patient_flags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(64) NOT NULL,
  `reason` TEXT NOT NULL,
  `resolved_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `resolved_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Bookings may precede registration; preserve contact snapshots.
CREATE TABLE `appointments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(64) NOT NULL,
  `last_name` VARCHAR(64) NOT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `starts_at` DATETIME(6) NOT NULL,
  `ends_at` DATETIME(6) NULL,
  `booking_channel` VARCHAR(64) NOT NULL,
  `visit_type` VARCHAR(64) NOT NULL,
  `specialist_name` VARCHAR(191) NULL,
  `specialist_email` VARCHAR(191) NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'booked',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NULL,
  `doctor_id` BIGINT UNSIGNED NULL,
  `booked_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_appointments_0` CHECK (ends_at IS NULL OR ends_at > starts_at),
  CONSTRAINT `ck_appointments_1` CHECK (status IN ('booked','confirmed','attended','cancelled','no_show'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Visit/care episode; stores category-at-visit and care setting separately.
CREATE TABLE `encounters` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_type` VARCHAR(64) NOT NULL,
  `care_setting` VARCHAR(64) NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'open',
  `opened_at` DATETIME(6) NOT NULL,
  `closed_at` DATETIME(6) NULL,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `appointment_id` BIGINT UNSIGNED NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `opened_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_encounters_0` UNIQUE (`appointment_id`),
  CONSTRAINT `uq_encounters_1` UNIQUE (`id`, `patient_id`),
  CONSTRAINT `ck_encounters_0` CHECK (care_setting IN ('OPD','IPD','EMERGENCY')),
  CONSTRAINT `ck_encounters_1` CHECK (status IN ('open','in_progress','closed','cancelled')),
  CONSTRAINT `ck_encounters_2` CHECK (closed_at IS NULL OR closed_at >= opened_at),
  CONSTRAINT `ck_encounters_3` CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Queue, sender/receiver and clinician assignment history.
CREATE TABLE `doctor_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assigned_at` DATETIME(6) NOT NULL,
  `accepted_at` DATETIME(6) NULL,
  `ended_at` DATETIME(6) NULL,
  `consultation_type` VARCHAR(64) NULL,
  `status` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `doctor_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Versioned validation schemas for all clinical form categories.
CREATE TABLE `clinical_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(191) NOT NULL,
  `version` INT NOT NULL,
  `schema_json` JSON NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_clinical_templates_0` UNIQUE (`code`, `version`),
  CONSTRAINT `ck_clinical_templates_0` CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Signed clinical form rows; dynamic keys use an approved versioned template.
CREATE TABLE `clinical_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `data` JSON NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `observed_at` DATETIME(6) NOT NULL,
  `signed_at` DATETIME(6) NULL,
  `amendment_reason` TEXT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `template_id` BIGINT UNSIGNED NOT NULL,
  `author_id` BIGINT UNSIGNED NOT NULL,
  `signed_by` BIGINT UNSIGNED NULL,
  `amends_entry_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_clinical_entries_0` CHECK (status IN ('draft','signed','voided')),
  CONSTRAINT `ck_clinical_entries_1` CHECK (status <> 'signed' OR (signed_by IS NOT NULL AND signed_at IS NOT NULL)),
  CONSTRAINT `ck_clinical_entries_2` CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Normalized clinical measurements for trends and reports.
CREATE TABLE `vital_observations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `observed_at` DATETIME(6) NOT NULL,
  `temperature_c` DECIMAL(5,2) NULL,
  `pulse_bpm` INT NULL,
  `respiratory_rate` INT NULL,
  `systolic` INT NULL,
  `diastolic` INT NULL,
  `spo2` DECIMAL(5,2) NULL,
  `height_cm` DECIMAL(6,2) NULL,
  `weight_kg` DECIMAL(7,3) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `recorded_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_vital_observations_0` CHECK (spo2 IS NULL OR (spo2 >= 0 AND spo2 <= 100)),
  CONSTRAINT `ck_vital_observations_1` CHECK (weight_kg IS NULL OR weight_kg > 0),
  CONSTRAINT `ck_vital_observations_2` CHECK (height_cm IS NULL OR height_cm > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Optional controlled diagnosis codes and human descriptions.
CREATE TABLE `diagnosis_catalog` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `coding_system` VARCHAR(64) NOT NULL,
  `description` TEXT NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_diagnosis_catalog_0` UNIQUE (`coding_system`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Encounter diagnosis with provisional/final status.
CREATE TABLE `encounter_diagnoses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `description` TEXT NOT NULL,
  `status` VARCHAR(64) NOT NULL,
  `is_primary` BOOLEAN NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `diagnosis_id` BIGINT UNSIGNED NULL,
  `recorded_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Billable service/procedure/investigation catalog.
CREATE TABLE `services` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `category` VARCHAR(64) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_services_0` UNIQUE (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Procedures actually performed, distinct from free-form surgical notes.
CREATE TABLE `performed_procedures` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `performed_at` DATETIME(6) NOT NULL,
  `details` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `service_id` BIGINT UNSIGNED NOT NULL,
  `performed_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Patient/encounter references to private files; consistent patient enforced by composite FK.
CREATE TABLE `documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(64) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `encounter_id` BIGINT UNSIGNED NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_documents_0` UNIQUE (`id`, `encounter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Hospital ward catalog.
CREATE TABLE `wards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `type` VARCHAR(64) NULL,
  `daily_rate` DECIMAL(14,2) NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_wards_0` UNIQUE (`code`),
  CONSTRAINT `ck_wards_0` CHECK (daily_rate IS NULL OR daily_rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Physical beds; availability derives from occupancy, not editable boolean.
CREATE TABLE `beds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label` VARCHAR(64) NOT NULL,
  `daily_rate` DECIMAL(14,2) NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `ward_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_beds_0` UNIQUE (`ward_id`, `label`),
  CONSTRAINT `ck_beds_0` CHECK (daily_rate IS NULL OR daily_rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- At most one admission per encounter; readmission uses a new encounter.
CREATE TABLE `admissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` VARCHAR(64) NOT NULL DEFAULT 'admitted',
  `admitted_at` DATETIME(6) NOT NULL,
  `authorized_at` DATETIME(6) NULL,
  `discharged_at` DATETIME(6) NULL,
  `discharge_reason` TEXT NULL,
  `discharge_summary` TEXT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `admitted_by` BIGINT UNSIGNED NOT NULL,
  `authorized_by` BIGINT UNSIGNED NULL,
  `discharged_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_admissions_0` UNIQUE (`encounter_id`),
  CONSTRAINT `ck_admissions_0` CHECK (status IN ('admitted','discharge_authorized','discharged')),
  CONSTRAINT `ck_admissions_1` CHECK (discharged_at IS NULL OR discharged_at >= admitted_at),
  CONSTRAINT `ck_admissions_2` CHECK (status <> 'discharged' OR (discharged_at IS NOT NULL AND discharged_by IS NOT NULL AND authorized_by IS NOT NULL AND authorized_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Immutable assignment/release history.
CREATE TABLE `bed_allocations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assigned_at` DATETIME(6) NOT NULL,
  `released_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `bed_id` BIGINT UNSIGNED NOT NULL,
  `admission_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by` BIGINT UNSIGNED NOT NULL,
  `released_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_bed_allocations_0` UNIQUE (`id`, `bed_id`, `admission_id`),
  CONSTRAINT `ck_bed_allocations_0` CHECK (released_at IS NULL OR released_at >= assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One occupant per bed and one bed per admission.
CREATE TABLE `current_bed_occupancies` (
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `bed_id` BIGINT UNSIGNED NOT NULL,
  `admission_id` BIGINT UNSIGNED NOT NULL,
  `allocation_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`bed_id`),
  CONSTRAINT `uq_current_bed_occupancies_0` UNIQUE (`admission_id`),
  CONSTRAINT `uq_current_bed_occupancies_1` UNIQUE (`allocation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Antenatal, postnatal, immunization, family-planning longitudinal episodes.
CREATE TABLE `care_programs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(64) NOT NULL,
  `started_at` DATETIME(6) NOT NULL,
  `ended_at` DATETIME(6) NULL,
  `status` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Links visit, follow-up booking and structured report summary to a care program.
CREATE TABLE `care_program_visits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `summary` JSON NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `program_id` BIGINT UNSIGNED NOT NULL,
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `next_appointment_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Delivery episode on the maternal encounter; full staged notes remain template entries.
CREATE TABLE `deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivered_at` DATETIME(6) NOT NULL,
  `mode` VARCHAR(64) NOT NULL,
  `details` JSON NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `doctor_id` BIGINT UNSIGNED NULL,
  `nurse_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_deliveries_0` UNIQUE (`id`, `encounter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per baby, including multiple births; child patient may be registered later.
CREATE TABLE `births` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `birth_order` INT NOT NULL,
  `born_at` DATETIME(6) NOT NULL,
  `sex` VARCHAR(64) NULL,
  `weight_kg` DECIMAL(6,3) NULL,
  `length_cm` DECIMAL(6,2) NULL,
  `head_circumference_cm` DECIMAL(6,2) NULL,
  `condition_at_birth` TEXT NULL,
  `immunization_note` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `child_patient_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_births_0` UNIQUE (`delivery_id`, `birth_order`),
  CONSTRAINT `uq_births_1` UNIQUE (`child_patient_id`),
  CONSTRAINT `ck_births_0` CHECK (birth_order > 0),
  CONSTRAINT `ck_births_1` CHECK (weight_kg IS NULL OR weight_kg >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Catalog of laboratory/radiology test definitions; schema version is explicit.
CREATE TABLE `investigation_tests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `department` VARCHAR(64) NOT NULL,
  `specimen` VARCHAR(191) NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `service_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_investigation_tests_0` UNIQUE (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Immutable parameter/unit/reference definitions per test version.
CREATE TABLE `investigation_schemas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` INT NOT NULL,
  `schema_json` JSON NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `test_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_investigation_schemas_0` UNIQUE (`test_id`, `version`),
  CONSTRAINT `uq_investigation_schemas_1` UNIQUE (`id`, `test_id`),
  CONSTRAINT `ck_investigation_schemas_0` CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Encounter-linked clinician request.
CREATE TABLE `investigation_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requested_at` DATETIME(6) NOT NULL,
  `status` VARCHAR(64) NOT NULL,
  `clinical_context` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `ordered_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_investigation_orders_0` UNIQUE (`id`, `encounter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Individual requested test; result attaches here, not to catalog ID.
CREATE TABLE `investigation_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `priority` VARCHAR(64) NOT NULL,
  `status` VARCHAR(64) NOT NULL,
  `specimen_ref` VARCHAR(191) NULL,
  `collected_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `order_id` BIGINT UNSIGNED NOT NULL,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_investigation_items_0` UNIQUE (`id`, `test_id`),
  CONSTRAINT `uq_investigation_items_1` UNIQUE (`id`, `encounter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Draft, verified and amended results with frozen reference units/ranges.
CREATE TABLE `result_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` INT NOT NULL,
  `values_json` JSON NOT NULL,
  `reference_snapshot` JSON NOT NULL,
  `observed_at` DATETIME(6) NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `released_at` DATETIME(6) NULL,
  `amendment_reason` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `item_id` BIGINT UNSIGNED NOT NULL,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `schema_id` BIGINT UNSIGNED NOT NULL,
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `entered_by` BIGINT UNSIGNED NOT NULL,
  `verified_by` BIGINT UNSIGNED NULL,
  `attachment_id` BIGINT UNSIGNED NULL,
  `supersedes_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_result_versions_0` UNIQUE (`item_id`, `version`),
  CONSTRAINT `ck_result_versions_0` CHECK (version > 0),
  CONSTRAINT `ck_result_versions_1` CHECK (status IN ('draft','verified','released','superseded')),
  CONSTRAINT `ck_result_versions_2` CHECK (status <> 'released' OR (verified_by IS NOT NULL AND released_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Medication catalog with category, type, strength and base-unit identity.
CREATE TABLE `drugs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `category` VARCHAR(64) NULL,
  `medication_type` VARCHAR(64) NULL,
  `strength` VARCHAR(64) NULL,
  `form` VARCHAR(64) NULL,
  `base_unit` VARCHAR(64) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_drugs_0` UNIQUE (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dated selling price, retained for historical billing.
CREATE TABLE `drug_prices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `effective_from` DATETIME(6) NOT NULL,
  `effective_until` DATETIME(6) NULL,
  `selling_price` DECIMAL(14,2) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `drug_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_drug_prices_0` UNIQUE (`drug_id`, `effective_from`),
  CONSTRAINT `ck_drug_prices_0` CHECK (selling_price >= 0),
  CONSTRAINT `ck_drug_prices_1` CHECK (effective_until IS NULL OR effective_until > effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Physical main/dispense stores; department requisitions request from a store.
CREATE TABLE `stores` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `department_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_stores_0` UNIQUE (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Per-store reorder thresholds, independent of a batch.
CREATE TABLE `store_drug_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reorder_level` DECIMAL(14,3) NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `store_id` BIGINT UNSIGNED NOT NULL,
  `drug_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_store_drug_settings_0` UNIQUE (`store_id`, `drug_id`),
  CONSTRAINT `ck_store_drug_settings_0` CHECK (reorder_level >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Lot and expiry traceability.
CREATE TABLE `drug_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lot_no` VARCHAR(64) NOT NULL,
  `expires_on` DATE NOT NULL,
  `received_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `drug_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_drug_batches_0` UNIQUE (`drug_id`, `lot_no`),
  CONSTRAINT `uq_drug_batches_1` UNIQUE (`id`, `drug_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Cached available quantity; reconciled to immutable movement ledger.
CREATE TABLE `stock_balances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `store_id` BIGINT UNSIGNED NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_stock_balances_0` UNIQUE (`store_id`, `batch_id`),
  CONSTRAINT `ck_stock_balances_0` CHECK (quantity >= 0),
  CONSTRAINT `ck_stock_balances_1` CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Append-only stock ledger; every business operation has a unique source line key.
CREATE TABLE `stock_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `signed_quantity` DECIMAL(14,3) NOT NULL,
  `movement_type` VARCHAR(64) NOT NULL,
  `source_key` VARCHAR(191) NOT NULL,
  `reason` TEXT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `balance_id` BIGINT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `reversal_of_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_stock_movements_0` UNIQUE (`source_key`),
  CONSTRAINT `ck_stock_movements_0` CHECK (signed_quantity <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Supplier organization and primary/alternate contact details.
CREATE TABLE `suppliers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `contact_title` VARCHAR(64) NULL,
  `organization_name` VARCHAR(191) NULL,
  `email` VARCHAR(191) NULL,
  `office_address` TEXT NULL,
  `phone` VARCHAR(32) NULL,
  `alternate_phone` VARCHAR(32) NULL,
  `country_iso` CHAR(2) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Supplier invoice received into stock; supplier invoice number is not globally unique.
CREATE TABLE `purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(64) NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `received_at` DATETIME(6) NULL,
  `currency` CHAR(3) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `received_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_purchases_0` UNIQUE (`supplier_id`, `invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Receipts retain units-per-pack, packs and unit-cost/price snapshots.
CREATE TABLE `purchase_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `units_per_pack` DECIMAL(14,3) NOT NULL,
  `packs_purchased` DECIMAL(14,3) NOT NULL,
  `quantity` DECIMAL(14,3) NOT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL,
  `selling_price_snapshot` DECIMAL(14,2) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `purchase_id` BIGINT UNSIGNED NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `store_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_purchase_items_0` CHECK (units_per_pack > 0),
  CONSTRAINT `ck_purchase_items_1` CHECK (packs_purchased > 0),
  CONSTRAINT `ck_purchase_items_2` CHECK (quantity > 0),
  CONSTRAINT `ck_purchase_items_3` CHECK (unit_cost >= 0),
  CONSTRAINT `ck_purchase_items_4` CHECK (selling_price_snapshot >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Clinician medication intent; signing required before dispensing.
CREATE TABLE `prescriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `signed_at` DATETIME(6) NULL,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `prescriber_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_prescriptions_0` UNIQUE (`id`, `encounter_id`),
  CONSTRAINT `ck_prescriptions_0` CHECK (status IN ('draft','signed','cancelled','completed')),
  CONSTRAINT `ck_prescriptions_1` CHECK (status <> 'signed' OR signed_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Clinical dosage/route/frequency/duration plus authorized dispensing quantity.
CREATE TABLE `prescription_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dosage` VARCHAR(191) NOT NULL,
  `route` VARCHAR(64) NOT NULL,
  `frequency` VARCHAR(191) NOT NULL,
  `duration` VARCHAR(191) NOT NULL,
  `quantity` DECIMAL(14,3) NOT NULL,
  `instructions` TEXT NULL,
  `status` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `prescription_id` BIGINT UNSIGNED NOT NULL,
  `drug_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_prescription_items_0` UNIQUE (`id`, `prescription_id`),
  CONSTRAINT `uq_prescription_items_1` UNIQUE (`id`, `drug_id`),
  CONSTRAINT `ck_prescription_items_0` CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Pharmacy stock issue, distinct from medication administration.
CREATE TABLE `dispenses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dispensed_at` DATETIME(6) NOT NULL,
  `idempotency_key` VARCHAR(191) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `prescription_id` BIGINT UNSIGNED NOT NULL,
  `store_id` BIGINT UNSIGNED NOT NULL,
  `dispensed_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_dispenses_0` UNIQUE (`idempotency_key`),
  CONSTRAINT `uq_dispenses_1` UNIQUE (`id`, `prescription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Each issue links one prescription line and batch; supports partial dispensing.
CREATE TABLE `dispense_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quantity` DECIMAL(14,3) NOT NULL,
  `unit_price` DECIMAL(14,2) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `dispense_id` BIGINT UNSIGNED NOT NULL,
  `prescription_id` BIGINT UNSIGNED NOT NULL,
  `prescription_item_id` BIGINT UNSIGNED NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `drug_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_dispense_items_0` UNIQUE (`id`, `prescription_item_id`),
  CONSTRAINT `ck_dispense_items_0` CHECK (quantity > 0),
  CONSTRAINT `ck_dispense_items_1` CHECK (unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Nursing dose served/given/declined/missed; does not re-deduct pharmacy stock.
CREATE TABLE `medication_administrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scheduled_at` DATETIME(6) NULL,
  `administered_at` DATETIME(6) NOT NULL,
  `quantity` DECIMAL(14,3) NULL,
  `dose_unit` VARCHAR(64) NULL,
  `status` VARCHAR(64) NOT NULL,
  `remark` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `prescription_item_id` BIGINT UNSIGNED NOT NULL,
  `dispense_item_id` BIGINT UNSIGNED NULL,
  `administered_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_medication_administrations_0` CHECK (quantity IS NULL OR quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Reporting projection of vaccine administration linked to the actual dose event.
CREATE TABLE `vaccinations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vaccine_name` VARCHAR(191) NOT NULL,
  `age_grade` VARCHAR(64) NULL,
  `dose` VARCHAR(64) NOT NULL,
  `route` VARCHAR(64) NOT NULL,
  `site` VARCHAR(64) NULL,
  `administered_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `administration_id` BIGINT UNSIGNED NULL,
  `batch_id` BIGINT UNSIGNED NULL,
  `administered_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_vaccinations_0` UNIQUE (`administration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Department OR patient requests for medication; not a clinician prescription.
CREATE TABLE `requisitions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_scope` VARCHAR(64) NOT NULL,
  `care_setting` VARCHAR(64) NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `requested_at` DATETIME(6) NOT NULL,
  `requester_name` VARCHAR(191) NULL,
  `requester_phone` VARCHAR(32) NULL,
  `remark` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `department_id` BIGINT UNSIGNED NULL,
  `encounter_id` BIGINT UNSIGNED NULL,
  `requested_by` BIGINT UNSIGNED NOT NULL,
  `store_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_requisitions_0` CHECK (request_scope IN ('department','patient')),
  CONSTRAINT `ck_requisitions_1` CHECK ((request_scope = 'department' AND department_id IS NOT NULL AND encounter_id IS NULL) OR (request_scope = 'patient' AND encounter_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Requested drug amounts and units; fulfillment must be explicitly approved.
CREATE TABLE `requisition_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quantity` DECIMAL(14,3) NOT NULL,
  `unit` VARCHAR(64) NOT NULL,
  `unit_price_snapshot` DECIMAL(14,2) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `requisition_id` BIGINT UNSIGNED NOT NULL,
  `drug_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_requisition_items_0` CHECK (quantity > 0),
  CONSTRAINT `ck_requisition_items_1` CHECK (unit_price_snapshot IS NULL OR unit_price_snapshot >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Store-to-store movements; posted transaction produces matched ledger entries.
CREATE TABLE `stock_transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `posted_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `from_store_id` BIGINT UNSIGNED NOT NULL,
  `to_store_id` BIGINT UNSIGNED NOT NULL,
  `posted_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_stock_transfers_0` CHECK (from_store_id <> to_store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Batch quantity moved between stores.
CREATE TABLE `stock_transfer_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quantity` DECIMAL(14,3) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `transfer_id` BIGINT UNSIGNED NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_stock_transfer_items_0` CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Damage, expiry, count corrections, patient returns and department issues.
CREATE TABLE `stock_adjustments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(64) NOT NULL,
  `reason` TEXT NOT NULL,
  `status` VARCHAR(64) NOT NULL,
  `posted_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `approved_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Reasoned batch adjustment; returns link original dispense and carry disposition.
CREATE TABLE `stock_adjustment_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quantity_delta` DECIMAL(14,3) NOT NULL,
  `disposition` VARCHAR(64) NULL,
  `refund_amount` DECIMAL(14,2) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `adjustment_id` BIGINT UNSIGNED NOT NULL,
  `balance_id` BIGINT UNSIGNED NOT NULL,
  `dispense_item_id` BIGINT UNSIGNED NULL,
  `requisition_item_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_stock_adjustment_items_0` CHECK (quantity_delta <> 0),
  CONSTRAINT `ck_stock_adjustment_items_1` CHECK (refund_amount IS NULL OR refund_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Insurer/company/staff/private payer organizations as displayed by the HMO screens.
CREATE TABLE `hmo_providers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `payer_type` VARCHAR(64) NOT NULL,
  `preauth_required` BOOLEAN NOT NULL DEFAULT 0,
  `office_address` TEXT NULL,
  `phone` VARCHAR(32) NULL,
  `alternate_phone` VARCHAR(32) NULL,
  `contact_person` VARCHAR(191) NULL,
  `email` VARCHAR(191) NULL,
  `billing_frequency` VARCHAR(64) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_hmo_providers_0` UNIQUE (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Private source file, parse status and validation errors; never filename-only.
CREATE TABLE `tariff_imports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` VARCHAR(64) NOT NULL,
  `validation_errors` JSON NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `provider_id` BIGINT UNSIGNED NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `imported_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Provider plan/treatment guide.
CREATE TABLE `insurance_plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `treatment_guide` TEXT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `provider_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_insurance_plans_0` UNIQUE (`provider_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Time-bound membership; retain policy, employer, group and eligibility details.
CREATE TABLE `patient_coverages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_no` VARCHAR(64) NOT NULL,
  `group_number` VARCHAR(64) NULL,
  `employer_name` VARCHAR(191) NULL,
  `valid_from` DATE NOT NULL,
  `valid_until` DATE NULL,
  `verification_status` VARCHAR(64) NOT NULL,
  `verified_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `verified_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_patient_coverages_0` UNIQUE (`id`, `patient_id`),
  CONSTRAINT `ck_patient_coverages_0` CHECK (valid_until IS NULL OR valid_until >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dated plan/service price; overlapping periods require a locked application check.
CREATE TABLE `tariffs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `effective_from` DATE NOT NULL,
  `effective_until` DATE NULL,
  `price` DECIMAL(14,2) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `service_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_tariffs_0` UNIQUE (`plan_id`, `service_id`, `effective_from`),
  CONSTRAINT `ck_tariffs_0` CHECK (price >= 0),
  CONSTRAINT `ck_tariffs_1` CHECK (effective_until IS NULL OR effective_until > effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Insurer approval for encounter service.
CREATE TABLE `preauthorizations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` VARCHAR(191) NOT NULL,
  `status` VARCHAR(64) NOT NULL,
  `approved_amount` DECIMAL(14,2) NULL,
  `expires_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `coverage_id` BIGINT UNSIGNED NOT NULL,
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `service_id` BIGINT UNSIGNED NOT NULL,
  `recorded_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_preauthorizations_0` CHECK (approved_amount IS NULL OR approved_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Visit invoice; totals computed from frozen line items and allocated receipts.
CREATE TABLE `invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(64) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `issued_at` DATETIME(6) NULL,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `encounter_id` BIGINT UNSIGNED NOT NULL,
  `coverage_id` BIGINT UNSIGNED NULL,
  `posted_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_invoices_0` UNIQUE (`invoice_no`),
  CONSTRAINT `uq_invoices_1` UNIQUE (`id`, `patient_id`),
  CONSTRAINT `ck_invoices_0` CHECK (status IN ('draft','posted','voided')),
  CONSTRAINT `ck_invoices_1` CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Service/duration quantities, historical prices, discounts and patient/insurer shares.
CREATE TABLE `invoice_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `description` VARCHAR(191) NOT NULL,
  `quantity` DECIMAL(14,3) NOT NULL,
  `unit_price` DECIMAL(14,2) NOT NULL,
  `discount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `net_amount` DECIMAL(14,2) NOT NULL,
  `patient_share` DECIMAL(14,2) NOT NULL,
  `insurer_share` DECIMAL(14,2) NOT NULL,
  `billing_source_key` VARCHAR(191) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `service_id` BIGINT UNSIGNED NOT NULL,
  `preauthorization_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_invoice_items_0` UNIQUE (`billing_source_key`),
  CONSTRAINT `ck_invoice_items_0` CHECK (quantity > 0),
  CONSTRAINT `ck_invoice_items_1` CHECK (unit_price >= 0),
  CONSTRAINT `ck_invoice_items_2` CHECK (discount >= 0),
  CONSTRAINT `ck_invoice_items_3` CHECK (net_amount >= 0),
  CONSTRAINT `ck_invoice_items_4` CHECK (patient_share >= 0),
  CONSTRAINT `ck_invoice_items_5` CHECK (insurer_share >= 0),
  CONSTRAINT `ck_invoice_items_6` CHECK (patient_share + insurer_share = net_amount),
  CONSTRAINT `ck_invoice_items_7` CHECK (discount <= quantity * unit_price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Patient deposits/payments independent of invoice issuance; original payer details retained.
CREATE TABLE `receipts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_no` VARCHAR(64) NOT NULL,
  `type` VARCHAR(64) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `method` VARCHAR(64) NOT NULL,
  `reference` VARCHAR(191) NULL,
  `payer_name` VARCHAR(191) NOT NULL,
  `payer_phone` VARCHAR(32) NULL,
  `paid_at` DATETIME(6) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `received_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_receipts_0` UNIQUE (`receipt_no`),
  CONSTRAINT `uq_receipts_1` UNIQUE (`id`, `patient_id`),
  CONSTRAINT `ck_receipts_0` CHECK (amount > 0),
  CONSTRAINT `ck_receipts_1` CHECK (type IN ('deposit','payment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Deposit/payment can pay multiple invoices; cross-patient allocation is forbidden.
CREATE TABLE `payment_allocations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `amount` DECIMAL(14,2) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `receipt_id` BIGINT UNSIGNED NOT NULL,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `patient_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_payment_allocations_0` UNIQUE (`receipt_id`, `invoice_id`),
  CONSTRAINT `ck_payment_allocations_0` CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Authorized return of unallocated receipt funds; never edit the original receipt.
CREATE TABLE `refunds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `amount` DECIMAL(14,2) NOT NULL,
  `reason` TEXT NOT NULL,
  `refunded_at` DATETIME(6) NOT NULL,
  `reference` VARCHAR(191) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `receipt_id` BIGINT UNSIGNED NOT NULL,
  `approved_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_refunds_0` CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Initial scope: one claim per invoice with event-based resubmission history.
CREATE TABLE `claims` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` VARCHAR(64) NOT NULL,
  `external_ref` VARCHAR(191) NULL,
  `submitted_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_claims_0` UNIQUE (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Claim submission, partial approval, rejection and resubmission history.
CREATE TABLE `claim_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_status` VARCHAR(64) NULL,
  `to_status` VARCHAR(64) NOT NULL,
  `reason` TEXT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `claim_id` BIGINT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Line-level claim decision and authorized amount; retains invoice-line relationship.
CREATE TABLE `claim_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `decision` VARCHAR(64) NOT NULL,
  `approved_amount` DECIMAL(14,2) NULL,
  `reason` TEXT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `claim_id` BIGINT UNSIGNED NOT NULL,
  `invoice_item_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_claim_items_0` UNIQUE (`claim_id`, `invoice_item_id`),
  CONSTRAINT `ck_claim_items_0` CHECK (approved_amount IS NULL OR approved_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Insurer payments with immutable references.
CREATE TABLE `claim_settlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `amount` DECIMAL(14,2) NOT NULL,
  `received_at` DATETIME(6) NOT NULL,
  `reference` VARCHAR(191) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `claim_id` BIGINT UNSIGNED NOT NULL,
  `recorded_by` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_claim_settlements_0` UNIQUE (`claim_id`, `reference`),
  CONSTRAINT `ck_claim_settlements_0` CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Patient or personal recurring schedules; delivery is local by default.
CREATE TABLE `reminders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(191) NOT NULL,
  `note` TEXT NULL,
  `due_at` DATETIME(6) NOT NULL,
  `snooze_minutes` INT NULL,
  `recurrence_rule` VARCHAR(191) NULL,
  `status` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `patient_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_reminders_0` CHECK (snooze_minutes IS NULL OR snooze_minutes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Recipient-scoped notifications, deduplicated by source event.
CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_key` VARCHAR(191) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `payload` JSON NOT NULL,
  `read_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `recipient_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_notifications_0` UNIQUE (`recipient_id`, `event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Authorized export lifecycle; reauthentication may be required for sensitive reports.
CREATE TABLE `report_exports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_type` VARCHAR(64) NOT NULL,
  `filters` JSON NOT NULL,
  `status` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `requested_by` BIGINT UNSIGNED NOT NULL,
  `file_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Restricted append-only access/change audit. subject_type/id are explicitly logical references.
CREATE TABLE `audit_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(191) NOT NULL,
  `subject_type` VARCHAR(64) NOT NULL,
  `subject_id` BIGINT UNSIGNED NULL,
  `request_id` VARCHAR(64) NOT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  `safe_summary` JSON NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actor_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Atomic command deduplication; complete record/resource outcome inside command transaction.
CREATE TABLE `idempotency_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route` VARCHAR(191) NOT NULL,
  `request_key` VARCHAR(191) NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(64) NOT NULL,
  `response_code` INT NULL,
  `resource_type` VARCHAR(64) NULL,
  `resource_id` BIGINT UNSIGNED NULL,
  `expires_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `user_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `uq_idempotency_requests_0` UNIQUE (`user_id`, `route`, `request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `users` ADD CONSTRAINT `fk_users_0` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ;

ALTER TABLE `staff_profiles` ADD CONSTRAINT `fk_staff_profiles_0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `staff_profiles` ADD CONSTRAINT `fk_staff_profiles_1` FOREIGN KEY (`photo_file_id`) REFERENCES `files` (`id`) ;

ALTER TABLE `staff_contacts` ADD CONSTRAINT `fk_staff_contacts_0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `user_roles` ADD CONSTRAINT `fk_user_roles_0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `user_roles` ADD CONSTRAINT `fk_user_roles_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ;

ALTER TABLE `role_permissions` ADD CONSTRAINT `fk_role_permissions_0` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ;

ALTER TABLE `role_permissions` ADD CONSTRAINT `fk_role_permissions_1` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ;

ALTER TABLE `files` ADD CONSTRAINT `fk_files_0` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `patients` ADD CONSTRAINT `fk_patients_0` FOREIGN KEY (`category_id`) REFERENCES `patient_categories` (`id`) ;

ALTER TABLE `patients` ADD CONSTRAINT `fk_patients_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `patients` ADD CONSTRAINT `fk_patients_2` FOREIGN KEY (`photo_file_id`) REFERENCES `files` (`id`) ;

CREATE INDEX `ix_patients_0` ON `patients` (`last_name`, `first_name`);

CREATE INDEX `ix_patients_1` ON `patients` (`phone`);

ALTER TABLE `patient_identifiers` ADD CONSTRAINT `fk_patient_identifiers_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_contacts` ADD CONSTRAINT `fk_patient_contacts_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_category_history` ADD CONSTRAINT `fk_patient_category_history_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_category_history` ADD CONSTRAINT `fk_patient_category_history_1` FOREIGN KEY (`previous_category_id`) REFERENCES `patient_categories` (`id`) ;

ALTER TABLE `patient_category_history` ADD CONSTRAINT `fk_patient_category_history_2` FOREIGN KEY (`new_category_id`) REFERENCES `patient_categories` (`id`) ;

ALTER TABLE `patient_category_history` ADD CONSTRAINT `fk_patient_category_history_3` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `patient_access_events` ADD CONSTRAINT `fk_patient_access_events_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_access_events` ADD CONSTRAINT `fk_patient_access_events_1` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `patient_merges` ADD CONSTRAINT `fk_patient_merges_0` FOREIGN KEY (`source_patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_merges` ADD CONSTRAINT `fk_patient_merges_1` FOREIGN KEY (`target_patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_merges` ADD CONSTRAINT `fk_patient_merges_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `patient_relationships` ADD CONSTRAINT `fk_patient_relationships_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_relationships` ADD CONSTRAINT `fk_patient_relationships_1` FOREIGN KEY (`related_patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_flags` ADD CONSTRAINT `fk_patient_flags_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_flags` ADD CONSTRAINT `fk_patient_flags_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `patient_flags` ADD CONSTRAINT `fk_patient_flags_2` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_2` FOREIGN KEY (`booked_by`) REFERENCES `users` (`id`) ;

CREATE INDEX `ix_appointments_0` ON `appointments` (`starts_at`, `status`);

CREATE INDEX `ix_appointments_1` ON `appointments` (`doctor_id`, `starts_at`);

ALTER TABLE `encounters` ADD CONSTRAINT `fk_encounters_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `encounters` ADD CONSTRAINT `fk_encounters_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ;

ALTER TABLE `encounters` ADD CONSTRAINT `fk_encounters_2` FOREIGN KEY (`category_id`) REFERENCES `patient_categories` (`id`) ;

ALTER TABLE `encounters` ADD CONSTRAINT `fk_encounters_3` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`) ;

CREATE INDEX `ix_encounters_0` ON `encounters` (`patient_id`, `opened_at`);

ALTER TABLE `doctor_assignments` ADD CONSTRAINT `fk_doctor_assignments_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `doctor_assignments` ADD CONSTRAINT `fk_doctor_assignments_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `doctor_assignments` ADD CONSTRAINT `fk_doctor_assignments_2` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ;

CREATE INDEX `ix_doctor_assignments_0` ON `doctor_assignments` (`doctor_id`, `status`, `assigned_at`);

ALTER TABLE `clinical_entries` ADD CONSTRAINT `fk_clinical_entries_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `clinical_entries` ADD CONSTRAINT `fk_clinical_entries_1` FOREIGN KEY (`template_id`) REFERENCES `clinical_templates` (`id`) ;

ALTER TABLE `clinical_entries` ADD CONSTRAINT `fk_clinical_entries_2` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `clinical_entries` ADD CONSTRAINT `fk_clinical_entries_3` FOREIGN KEY (`signed_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `clinical_entries` ADD CONSTRAINT `fk_clinical_entries_4` FOREIGN KEY (`amends_entry_id`) REFERENCES `clinical_entries` (`id`) ;

CREATE INDEX `ix_clinical_entries_0` ON `clinical_entries` (`encounter_id`, `observed_at`);

ALTER TABLE `vital_observations` ADD CONSTRAINT `fk_vital_observations_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `vital_observations` ADD CONSTRAINT `fk_vital_observations_1` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ;

CREATE INDEX `ix_vital_observations_0` ON `vital_observations` (`encounter_id`, `observed_at`);

ALTER TABLE `encounter_diagnoses` ADD CONSTRAINT `fk_encounter_diagnoses_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `encounter_diagnoses` ADD CONSTRAINT `fk_encounter_diagnoses_1` FOREIGN KEY (`diagnosis_id`) REFERENCES `diagnosis_catalog` (`id`) ;

ALTER TABLE `encounter_diagnoses` ADD CONSTRAINT `fk_encounter_diagnoses_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `performed_procedures` ADD CONSTRAINT `fk_performed_procedures_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `performed_procedures` ADD CONSTRAINT `fk_performed_procedures_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ;

ALTER TABLE `performed_procedures` ADD CONSTRAINT `fk_performed_procedures_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `documents` ADD CONSTRAINT `fk_documents_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `documents` ADD CONSTRAINT `fk_documents_1` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `documents` ADD CONSTRAINT `fk_documents_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ;

ALTER TABLE `documents` ADD CONSTRAINT `fk_documents_3` FOREIGN KEY (`encounter_id`, `patient_id`) REFERENCES `encounters` (`id`, `patient_id`) ;

ALTER TABLE `beds` ADD CONSTRAINT `fk_beds_0` FOREIGN KEY (`ward_id`) REFERENCES `wards` (`id`) ;

ALTER TABLE `admissions` ADD CONSTRAINT `fk_admissions_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `admissions` ADD CONSTRAINT `fk_admissions_1` FOREIGN KEY (`admitted_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `admissions` ADD CONSTRAINT `fk_admissions_2` FOREIGN KEY (`authorized_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `admissions` ADD CONSTRAINT `fk_admissions_3` FOREIGN KEY (`discharged_by`) REFERENCES `users` (`id`) ;

CREATE INDEX `ix_admissions_0` ON `admissions` (`status`, `admitted_at`);

ALTER TABLE `bed_allocations` ADD CONSTRAINT `fk_bed_allocations_0` FOREIGN KEY (`bed_id`) REFERENCES `beds` (`id`) ;

ALTER TABLE `bed_allocations` ADD CONSTRAINT `fk_bed_allocations_1` FOREIGN KEY (`admission_id`) REFERENCES `admissions` (`id`) ;

ALTER TABLE `bed_allocations` ADD CONSTRAINT `fk_bed_allocations_2` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `bed_allocations` ADD CONSTRAINT `fk_bed_allocations_3` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `current_bed_occupancies` ADD CONSTRAINT `fk_current_bed_occupancies_0` FOREIGN KEY (`bed_id`) REFERENCES `beds` (`id`) ;

ALTER TABLE `current_bed_occupancies` ADD CONSTRAINT `fk_current_bed_occupancies_1` FOREIGN KEY (`admission_id`) REFERENCES `admissions` (`id`) ;

ALTER TABLE `current_bed_occupancies` ADD CONSTRAINT `fk_current_bed_occupancies_2` FOREIGN KEY (`allocation_id`) REFERENCES `bed_allocations` (`id`) ;

ALTER TABLE `current_bed_occupancies` ADD CONSTRAINT `fk_current_bed_occupancies_3` FOREIGN KEY (`allocation_id`, `bed_id`, `admission_id`) REFERENCES `bed_allocations` (`id`, `bed_id`, `admission_id`) ;

ALTER TABLE `care_programs` ADD CONSTRAINT `fk_care_programs_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `care_programs` ADD CONSTRAINT `fk_care_programs_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `care_program_visits` ADD CONSTRAINT `fk_care_program_visits_0` FOREIGN KEY (`program_id`) REFERENCES `care_programs` (`id`) ;

ALTER TABLE `care_program_visits` ADD CONSTRAINT `fk_care_program_visits_1` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `care_program_visits` ADD CONSTRAINT `fk_care_program_visits_2` FOREIGN KEY (`next_appointment_id`) REFERENCES `appointments` (`id`) ;

ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_2` FOREIGN KEY (`nurse_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `births` ADD CONSTRAINT `fk_births_0` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ;

ALTER TABLE `births` ADD CONSTRAINT `fk_births_1` FOREIGN KEY (`child_patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `investigation_tests` ADD CONSTRAINT `fk_investigation_tests_0` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ;

ALTER TABLE `investigation_schemas` ADD CONSTRAINT `fk_investigation_schemas_0` FOREIGN KEY (`test_id`) REFERENCES `investigation_tests` (`id`) ;

ALTER TABLE `investigation_orders` ADD CONSTRAINT `fk_investigation_orders_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `investigation_orders` ADD CONSTRAINT `fk_investigation_orders_1` FOREIGN KEY (`ordered_by`) REFERENCES `users` (`id`) ;

CREATE INDEX `ix_investigation_orders_0` ON `investigation_orders` (`status`, `requested_at`);

ALTER TABLE `investigation_items` ADD CONSTRAINT `fk_investigation_items_0` FOREIGN KEY (`order_id`) REFERENCES `investigation_orders` (`id`) ;

ALTER TABLE `investigation_items` ADD CONSTRAINT `fk_investigation_items_1` FOREIGN KEY (`test_id`) REFERENCES `investigation_tests` (`id`) ;

ALTER TABLE `investigation_items` ADD CONSTRAINT `fk_investigation_items_2` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `investigation_items` ADD CONSTRAINT `fk_investigation_items_3` FOREIGN KEY (`order_id`, `encounter_id`) REFERENCES `investigation_orders` (`id`, `encounter_id`) ;

CREATE INDEX `ix_investigation_items_0` ON `investigation_items` (`status`, `test_id`);

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_0` FOREIGN KEY (`item_id`) REFERENCES `investigation_items` (`id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_1` FOREIGN KEY (`test_id`) REFERENCES `investigation_tests` (`id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_2` FOREIGN KEY (`schema_id`) REFERENCES `investigation_schemas` (`id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_3` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_4` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_5` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_6` FOREIGN KEY (`attachment_id`) REFERENCES `documents` (`id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_7` FOREIGN KEY (`supersedes_id`) REFERENCES `result_versions` (`id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_8` FOREIGN KEY (`item_id`, `test_id`) REFERENCES `investigation_items` (`id`, `test_id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_9` FOREIGN KEY (`schema_id`, `test_id`) REFERENCES `investigation_schemas` (`id`, `test_id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_10` FOREIGN KEY (`item_id`, `encounter_id`) REFERENCES `investigation_items` (`id`, `encounter_id`) ;

ALTER TABLE `result_versions` ADD CONSTRAINT `fk_result_versions_11` FOREIGN KEY (`attachment_id`, `encounter_id`) REFERENCES `documents` (`id`, `encounter_id`) ;

ALTER TABLE `drug_prices` ADD CONSTRAINT `fk_drug_prices_0` FOREIGN KEY (`drug_id`) REFERENCES `drugs` (`id`) ;

ALTER TABLE `stores` ADD CONSTRAINT `fk_stores_0` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ;

ALTER TABLE `store_drug_settings` ADD CONSTRAINT `fk_store_drug_settings_0` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ;

ALTER TABLE `store_drug_settings` ADD CONSTRAINT `fk_store_drug_settings_1` FOREIGN KEY (`drug_id`) REFERENCES `drugs` (`id`) ;

ALTER TABLE `drug_batches` ADD CONSTRAINT `fk_drug_batches_0` FOREIGN KEY (`drug_id`) REFERENCES `drugs` (`id`) ;

CREATE INDEX `ix_drug_batches_0` ON `drug_batches` (`drug_id`, `expires_on`);

ALTER TABLE `stock_balances` ADD CONSTRAINT `fk_stock_balances_0` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ;

ALTER TABLE `stock_balances` ADD CONSTRAINT `fk_stock_balances_1` FOREIGN KEY (`batch_id`) REFERENCES `drug_batches` (`id`) ;

ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_0` FOREIGN KEY (`balance_id`) REFERENCES `stock_balances` (`id`) ;

ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_1` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_2` FOREIGN KEY (`reversal_of_id`) REFERENCES `stock_movements` (`id`) ;

CREATE INDEX `ix_stock_movements_0` ON `stock_movements` (`balance_id`, `occurred_at`);

ALTER TABLE `purchases` ADD CONSTRAINT `fk_purchases_0` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ;

ALTER TABLE `purchases` ADD CONSTRAINT `fk_purchases_1` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `purchase_items` ADD CONSTRAINT `fk_purchase_items_0` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ;

ALTER TABLE `purchase_items` ADD CONSTRAINT `fk_purchase_items_1` FOREIGN KEY (`batch_id`) REFERENCES `drug_batches` (`id`) ;

ALTER TABLE `purchase_items` ADD CONSTRAINT `fk_purchase_items_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ;

ALTER TABLE `prescriptions` ADD CONSTRAINT `fk_prescriptions_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `prescriptions` ADD CONSTRAINT `fk_prescriptions_1` FOREIGN KEY (`prescriber_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `prescription_items` ADD CONSTRAINT `fk_prescription_items_0` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ;

ALTER TABLE `prescription_items` ADD CONSTRAINT `fk_prescription_items_1` FOREIGN KEY (`drug_id`) REFERENCES `drugs` (`id`) ;

ALTER TABLE `dispenses` ADD CONSTRAINT `fk_dispenses_0` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ;

ALTER TABLE `dispenses` ADD CONSTRAINT `fk_dispenses_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ;

ALTER TABLE `dispenses` ADD CONSTRAINT `fk_dispenses_2` FOREIGN KEY (`dispensed_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `dispense_items` ADD CONSTRAINT `fk_dispense_items_0` FOREIGN KEY (`dispense_id`) REFERENCES `dispenses` (`id`) ;

ALTER TABLE `dispense_items` ADD CONSTRAINT `fk_dispense_items_1` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ;

ALTER TABLE `dispense_items` ADD CONSTRAINT `fk_dispense_items_2` FOREIGN KEY (`prescription_item_id`) REFERENCES `prescription_items` (`id`) ;

ALTER TABLE `dispense_items` ADD CONSTRAINT `fk_dispense_items_3` FOREIGN KEY (`batch_id`) REFERENCES `drug_batches` (`id`) ;

ALTER TABLE `dispense_items` ADD CONSTRAINT `fk_dispense_items_4` FOREIGN KEY (`drug_id`) REFERENCES `drugs` (`id`) ;

ALTER TABLE `dispense_items` ADD CONSTRAINT `fk_dispense_items_5` FOREIGN KEY (`dispense_id`, `prescription_id`) REFERENCES `dispenses` (`id`, `prescription_id`) ;

ALTER TABLE `dispense_items` ADD CONSTRAINT `fk_dispense_items_6` FOREIGN KEY (`prescription_item_id`, `prescription_id`) REFERENCES `prescription_items` (`id`, `prescription_id`) ;

ALTER TABLE `dispense_items` ADD CONSTRAINT `fk_dispense_items_7` FOREIGN KEY (`prescription_item_id`, `drug_id`) REFERENCES `prescription_items` (`id`, `drug_id`) ;

ALTER TABLE `dispense_items` ADD CONSTRAINT `fk_dispense_items_8` FOREIGN KEY (`batch_id`, `drug_id`) REFERENCES `drug_batches` (`id`, `drug_id`) ;

ALTER TABLE `medication_administrations` ADD CONSTRAINT `fk_medication_administrations_0` FOREIGN KEY (`prescription_item_id`) REFERENCES `prescription_items` (`id`) ;

ALTER TABLE `medication_administrations` ADD CONSTRAINT `fk_medication_administrations_1` FOREIGN KEY (`dispense_item_id`) REFERENCES `dispense_items` (`id`) ;

ALTER TABLE `medication_administrations` ADD CONSTRAINT `fk_medication_administrations_2` FOREIGN KEY (`administered_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `medication_administrations` ADD CONSTRAINT `fk_medication_administrations_3` FOREIGN KEY (`dispense_item_id`, `prescription_item_id`) REFERENCES `dispense_items` (`id`, `prescription_item_id`) ;

ALTER TABLE `vaccinations` ADD CONSTRAINT `fk_vaccinations_0` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `vaccinations` ADD CONSTRAINT `fk_vaccinations_1` FOREIGN KEY (`administration_id`) REFERENCES `medication_administrations` (`id`) ;

ALTER TABLE `vaccinations` ADD CONSTRAINT `fk_vaccinations_2` FOREIGN KEY (`batch_id`) REFERENCES `drug_batches` (`id`) ;

ALTER TABLE `vaccinations` ADD CONSTRAINT `fk_vaccinations_3` FOREIGN KEY (`administered_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `requisitions` ADD CONSTRAINT `fk_requisitions_0` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ;

ALTER TABLE `requisitions` ADD CONSTRAINT `fk_requisitions_1` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `requisitions` ADD CONSTRAINT `fk_requisitions_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `requisitions` ADD CONSTRAINT `fk_requisitions_3` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ;

ALTER TABLE `requisition_items` ADD CONSTRAINT `fk_requisition_items_0` FOREIGN KEY (`requisition_id`) REFERENCES `requisitions` (`id`) ;

ALTER TABLE `requisition_items` ADD CONSTRAINT `fk_requisition_items_1` FOREIGN KEY (`drug_id`) REFERENCES `drugs` (`id`) ;

ALTER TABLE `stock_transfers` ADD CONSTRAINT `fk_stock_transfers_0` FOREIGN KEY (`from_store_id`) REFERENCES `stores` (`id`) ;

ALTER TABLE `stock_transfers` ADD CONSTRAINT `fk_stock_transfers_1` FOREIGN KEY (`to_store_id`) REFERENCES `stores` (`id`) ;

ALTER TABLE `stock_transfers` ADD CONSTRAINT `fk_stock_transfers_2` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `stock_transfer_items` ADD CONSTRAINT `fk_stock_transfer_items_0` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`id`) ;

ALTER TABLE `stock_transfer_items` ADD CONSTRAINT `fk_stock_transfer_items_1` FOREIGN KEY (`batch_id`) REFERENCES `drug_batches` (`id`) ;

ALTER TABLE `stock_adjustments` ADD CONSTRAINT `fk_stock_adjustments_0` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `stock_adjustment_items` ADD CONSTRAINT `fk_stock_adjustment_items_0` FOREIGN KEY (`adjustment_id`) REFERENCES `stock_adjustments` (`id`) ;

ALTER TABLE `stock_adjustment_items` ADD CONSTRAINT `fk_stock_adjustment_items_1` FOREIGN KEY (`balance_id`) REFERENCES `stock_balances` (`id`) ;

ALTER TABLE `stock_adjustment_items` ADD CONSTRAINT `fk_stock_adjustment_items_2` FOREIGN KEY (`dispense_item_id`) REFERENCES `dispense_items` (`id`) ;

ALTER TABLE `stock_adjustment_items` ADD CONSTRAINT `fk_stock_adjustment_items_3` FOREIGN KEY (`requisition_item_id`) REFERENCES `requisition_items` (`id`) ;

ALTER TABLE `tariff_imports` ADD CONSTRAINT `fk_tariff_imports_0` FOREIGN KEY (`provider_id`) REFERENCES `hmo_providers` (`id`) ;

ALTER TABLE `tariff_imports` ADD CONSTRAINT `fk_tariff_imports_1` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ;

ALTER TABLE `tariff_imports` ADD CONSTRAINT `fk_tariff_imports_2` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `insurance_plans` ADD CONSTRAINT `fk_insurance_plans_0` FOREIGN KEY (`provider_id`) REFERENCES `hmo_providers` (`id`) ;

ALTER TABLE `patient_coverages` ADD CONSTRAINT `fk_patient_coverages_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `patient_coverages` ADD CONSTRAINT `fk_patient_coverages_1` FOREIGN KEY (`plan_id`) REFERENCES `insurance_plans` (`id`) ;

ALTER TABLE `patient_coverages` ADD CONSTRAINT `fk_patient_coverages_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `tariffs` ADD CONSTRAINT `fk_tariffs_0` FOREIGN KEY (`plan_id`) REFERENCES `insurance_plans` (`id`) ;

ALTER TABLE `tariffs` ADD CONSTRAINT `fk_tariffs_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ;

ALTER TABLE `preauthorizations` ADD CONSTRAINT `fk_preauthorizations_0` FOREIGN KEY (`coverage_id`) REFERENCES `patient_coverages` (`id`) ;

ALTER TABLE `preauthorizations` ADD CONSTRAINT `fk_preauthorizations_1` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `preauthorizations` ADD CONSTRAINT `fk_preauthorizations_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `preauthorizations` ADD CONSTRAINT `fk_preauthorizations_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ;

ALTER TABLE `preauthorizations` ADD CONSTRAINT `fk_preauthorizations_4` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `preauthorizations` ADD CONSTRAINT `fk_preauthorizations_5` FOREIGN KEY (`coverage_id`, `patient_id`) REFERENCES `patient_coverages` (`id`, `patient_id`) ;

ALTER TABLE `preauthorizations` ADD CONSTRAINT `fk_preauthorizations_6` FOREIGN KEY (`encounter_id`, `patient_id`) REFERENCES `encounters` (`id`, `patient_id`) ;

ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoices_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoices_1` FOREIGN KEY (`encounter_id`) REFERENCES `encounters` (`id`) ;

ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoices_2` FOREIGN KEY (`coverage_id`) REFERENCES `patient_coverages` (`id`) ;

ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoices_3` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoices_4` FOREIGN KEY (`encounter_id`, `patient_id`) REFERENCES `encounters` (`id`, `patient_id`) ;

ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoices_5` FOREIGN KEY (`coverage_id`, `patient_id`) REFERENCES `patient_coverages` (`id`, `patient_id`) ;

CREATE INDEX `ix_invoices_0` ON `invoices` (`status`, `issued_at`);

ALTER TABLE `invoice_items` ADD CONSTRAINT `fk_invoice_items_0` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ;

ALTER TABLE `invoice_items` ADD CONSTRAINT `fk_invoice_items_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ;

ALTER TABLE `invoice_items` ADD CONSTRAINT `fk_invoice_items_2` FOREIGN KEY (`preauthorization_id`) REFERENCES `preauthorizations` (`id`) ;

ALTER TABLE `receipts` ADD CONSTRAINT `fk_receipts_0` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `receipts` ADD CONSTRAINT `fk_receipts_1` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ;

CREATE INDEX `ix_receipts_0` ON `receipts` (`patient_id`, `paid_at`);

ALTER TABLE `payment_allocations` ADD CONSTRAINT `fk_payment_allocations_0` FOREIGN KEY (`receipt_id`) REFERENCES `receipts` (`id`) ;

ALTER TABLE `payment_allocations` ADD CONSTRAINT `fk_payment_allocations_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ;

ALTER TABLE `payment_allocations` ADD CONSTRAINT `fk_payment_allocations_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

ALTER TABLE `payment_allocations` ADD CONSTRAINT `fk_payment_allocations_3` FOREIGN KEY (`receipt_id`, `patient_id`) REFERENCES `receipts` (`id`, `patient_id`) ;

ALTER TABLE `payment_allocations` ADD CONSTRAINT `fk_payment_allocations_4` FOREIGN KEY (`invoice_id`, `patient_id`) REFERENCES `invoices` (`id`, `patient_id`) ;

ALTER TABLE `refunds` ADD CONSTRAINT `fk_refunds_0` FOREIGN KEY (`receipt_id`) REFERENCES `receipts` (`id`) ;

ALTER TABLE `refunds` ADD CONSTRAINT `fk_refunds_1` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `claims` ADD CONSTRAINT `fk_claims_0` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ;

ALTER TABLE `claim_events` ADD CONSTRAINT `fk_claim_events_0` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`id`) ;

ALTER TABLE `claim_events` ADD CONSTRAINT `fk_claim_events_1` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `claim_items` ADD CONSTRAINT `fk_claim_items_0` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`id`) ;

ALTER TABLE `claim_items` ADD CONSTRAINT `fk_claim_items_1` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ;

ALTER TABLE `claim_settlements` ADD CONSTRAINT `fk_claim_settlements_0` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`id`) ;

ALTER TABLE `claim_settlements` ADD CONSTRAINT `fk_claim_settlements_1` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `reminders` ADD CONSTRAINT `fk_reminders_0` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ;

ALTER TABLE `reminders` ADD CONSTRAINT `fk_reminders_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ;

CREATE INDEX `ix_reminders_0` ON `reminders` (`owner_id`, `status`, `due_at`);

ALTER TABLE `notifications` ADD CONSTRAINT `fk_notifications_0` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ;

CREATE INDEX `ix_notifications_0` ON `notifications` (`recipient_id`, `read_at`, `created_at`);

ALTER TABLE `report_exports` ADD CONSTRAINT `fk_report_exports_0` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ;

ALTER TABLE `report_exports` ADD CONSTRAINT `fk_report_exports_1` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ;

CREATE INDEX `ix_report_exports_0` ON `report_exports` (`requested_by`, `created_at`);

ALTER TABLE `audit_events` ADD CONSTRAINT `fk_audit_events_0` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ;

CREATE INDEX `ix_audit_events_0` ON `audit_events` (`subject_type`, `subject_id`, `occurred_at`);

ALTER TABLE `idempotency_requests` ADD CONSTRAINT `fk_idempotency_requests_0` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ;
