# Frontend-to-database review v1.1

4 November 2026. Static code review and portable schema tests; not browser UAT or MySQL execution.

## Verification result

{
  "date": "2026-11-04",
  "source_files": 742,
  "import_reachable_files": 629,
  "route_declarations": 138,
  "distinct_route_patterns": 129,
  "frontend_types": 483,
  "exact_duplicate_groups": 54,
  "placeholder_routes": 13,
  "unmatched_literal_navigation": 7,
  "production_build_passed": true,
  "type_errors": 154,
  "lint_errors": 19,
  "lint_warnings": 26,
  "schema": {
    "engine": "SQLite in-memory projection",
    "mysql_executed": false,
    "tests_run": 36,
    "failures": 0,
    "errors": 0,
    "table_count": 85,
    "foreign_key_count": 201,
    "scope": "Referential/uniqueness/CHECK scenarios only; no MySQL dialect, row locks, Laravel services, browser UAT or performance validation."
  },
  "frontend_modified": false,
  "backend_implemented": false,
  "verdict": "Not production-ready. Schema structurally reviewed and portable constraint tests pass; actual MySQL/Laravel/browser validation pending."
}

## Findings

### F01 / Critical: Clinical tables are global, not patient/encounter scoped

Evidence: `src/pages/doctor/patientProfile/hooks/useMedicalTable.ts:67`

tables and pendingSectionEntries are keyed by section only. Require encounter-scoped storage/query keys; never attach one patient’s rows to another.

### F02 / Critical: Direct doctor profile URL can show the sample patient

Evidence: `src/pages/doctor/patientProfile/PatientProfile.tsx:101`

Patient comes from location.state or MOCK_PATIENT; route :id is not resolved. Fetch by route ID and show 404/loading instead of fallback.

### F03 / High: Two independent auth stores; production routes are public preview

Evidence: `src/routes/RoleBasedRoutes.tsx:100; src/context/AuthContext.tsx:40`

Replace mock password/session storage, enforce Laravel policies, use one authenticated session and clear identity-scoped caches.

### F04 / High: Patient registration reports success without saving

Evidence: `src/modules/frontdesk/pages/Registration/Registration.tsx:172`

Submission logs formData and changes step. Backend transaction must save patient/contact/coverage; success follows committed response only.

### F05 / High: Doctor ward route uses a nurse-only context

Evidence: `src/routes/RoleBasedRoutes.tsx:162; src/pages/nurse/patient-management/available-ward/components/WardBedsLog.tsx:47`

DoctorLayout supplies a different provider. Code inspection indicates the nurse hook throws on this route. Replace with shared API state.

### F06 / High: Several routed registration fragments receive no form state

Evidence: `src/modules/frontdesk/routes.tsx:40`

Three step components require formData but are mounted directly without props. Route through the wizard or provide explicit loaders/state.

### F07 / High: No operational API integration in clinical/stock/billing screens

Evidence: `src/api/client/apClient.ts:10`

The API call sites found are auth hooks. The routed app uses mocks/local state; schema coverage alone cannot make these saves persist.

### F08 / High: Export authorization uses a fixed demonstration OTP

Evidence: `src/constant/ExportOtpModal.tsx:9`

1234 is a demo gate, not authorization. Protect export generation/download on the server; use real session reauthentication if required.

### F09 / Medium: Navigation includes unregistered dashboard destinations

Evidence: `src/modules/frontdesk/pages/manage-card/ManageCard.tsx:50`

Seven reachable literal navigation occurrences target /frontdesk/dashboard or /dashboard; wildcard silently returns to preview. Use agreed module home paths.

### F10 / Medium: HMO registration loses tariff upload and organization detail

Evidence: `src/modules/hmo/pages/hmo-management/registration/components/HmoRegistrationForm.tsx:53`

Only filename is retained; organizationName is not sent and contact person is derived from a phone field. Persist file_id and distinct provider/contact data.

### F11 / Medium: Patient life status is mislabeled as insurance eligibility

Evidence: `src/modules/frontdesk/pages/Registration/PatientInsuranceReg.tsx:14`

Alive/deceased options must map to patients.life_status, separately from coverage verification and staff access status.

### F12 / Medium: Offline server deployment still pulls a Google font

Evidence: `src/index.css:1`

Bundle the font and all required assets locally; verify hospital workflows with internet access disabled.

## Workflow coverage

Every routed feature is mapped below. Mapping means a proposed storage location exists; it does not prove APIs, schemas for every JSON field, or workflows are implemented.

### Preview / fallback

Tables: None

Evidence: src/pages/preview/PreviewEntry.tsx

Navigation only. Disable public module preview in production.

### Staff identity / account

Tables: users, staff_profiles, staff_contacts, roles, permissions, user_roles, role_permissions, files

Evidence: src/context/AuthContext.tsx; src/pages/doctor/account/types.ts

One Laravel session; profile role labels are not authorization grants.

### Patient registration / edit

Tables: patients, patient_categories, patient_contacts, patient_coverages, insurance_plans, hmo_providers, files, patient_identifiers

Evidence: src/modules/frontdesk/pages/Registration/Registration.tsx

Maps surname/lastName to last_name. Birth date, age estimate and life status are separate from insurance eligibility.

### HMO organization registration

Tables: hmo_providers, insurance_plans, tariffs, tariff_imports, files, services

Evidence: src/modules/hmo/pages/hmo-management/registration/components/HmoRegistrationForm.tsx

Organization and tariff import are not patient registration. Requires actual file upload, parse validation and effective-dated tariffs.

### Card conversion / merge

Tables: patients, patient_identifiers, patient_category_history, patient_merges, patient_categories, audit_events

Evidence: src/modules/frontdesk/pages/manage-card/ManageCard.tsx

Keep original identity/history. Prevent merge cycles; authorize and review duplicates; audit category conversion.

### Patient access restrictions

Tables: patients, patient_access_events, users, audit_events

Evidence: src/modules/frontdesk/pages/manage-access/ManageAccess.tsx

Do not conflate a patient restriction with staff RBAC or permanently block emergency care.

### Patient registration log

Tables: patients, patient_categories, patient_contacts, users

Evidence: src/modules/frontdesk/pages/RegistrationLog/components/register.tsx

Read-only patient registry with registration actor/time and category; no independently editable log rows.

### Appointments

Tables: appointments, patients, users, doctor_assignments

Evidence: src/modules/frontdesk/pages/book-appointment/components/CreateBooking.tsx

patient_id is nullable until registration. Keep caller/contact snapshot, channel, visit type and external specialist details.

### Clinician assignment / queue

Tables: doctor_assignments, encounters, users

Evidence: src/modules/frontdesk/pages/doctors-assignment/components/DoctorLog.tsx

Preserve sender, receiver, consultation type, acceptance and assignment history.

### Previous patient records

Tables: patients, encounters, clinical_entries, documents, files, encounter_diagnoses, result_versions

Evidence: src/pages/shared/previousPatientRecords/PreviousPatientRecords.tsx

Shared read model, never another writable patient copy. Route ID must resolve on server.

### Flags

Tables: patient_flags, patients, users, audit_events

Evidence: src/components/patient/FlagPatientPanel.tsx

Actor/time/reason and resolution; server policy on patient scope.

### Available wards / beds

Tables: wards, beds, admissions, bed_allocations, current_bed_occupancies

Evidence: src/pages/nurse/patient-management/available-ward/components/WardBedsLog.tsx

Bed rate supported. Availability derives from active allocations; safe delete/deactivate rules.

### Admission / discharge

Tables: admissions, encounters, wards, beds, bed_allocations, current_bed_occupancies, users

Evidence: src/pages/nurse/shared/context/PatientManagementContext.tsx

One shared episode; doctor authorization separate from nursing completion; no fake duration constants.

### Maternal, family-planning and immunization reports

Tables: care_programs, care_program_visits, clinical_entries, clinical_templates, encounters, appointments, deliveries, births, vaccinations, patient_relationships

Evidence: src/pages/nurse/reports; src/pages/doctor/patientProfile/components/categories

Structured report projections plus versioned clinical payloads. Individual baby records for twins/multiples.

### Reports / nursing notes

Tables: clinical_entries, clinical_templates, encounters, users

Evidence: src/pages/doctor/patientProfile/components/categories

Notes are encounter-bound; sign/amend rather than overwrite released documentation.

### Medication requests / requisitions

Tables: requisitions, requisition_items, departments, stores, drugs, encounters, stock_adjustments, stock_adjustment_items

Evidence: src/pages/nurse/perform-action/make-request/MakeRequest.tsx; src/pages/diagnostics-and-radiologist/components/request-form

The diagnostics Make Request UI also requests medications. Do not map all of this screen to investigation_orders.

### Prescriptions / dispensing reports

Tables: prescriptions, prescription_items, dispenses, dispense_items, medication_administrations, drugs, encounters

Evidence: src/pages/nurse/reports/dispensed-drugs/data/mockDispensedDrugRecords.ts

Pharmacy issuing stock and nurse serving a dose are different events.

### Reminders

Tables: reminders, notifications, users, patients

Evidence: src/modules/frontdesk/pages/set-reminder/components/SetReminder.tsx

Persist due time, snooze interval, recurrence and note. Personal schedules may omit patient_id.

### Notifications

Tables: notifications, users

Evidence: src/data/mockNotifications.ts

Per-recipient read status, event source and deduplication; backend limits PHI in payload.

### Pharmacy stores / inventory

Tables: drugs, drug_prices, stores, store_drug_settings, drug_batches, stock_balances, stock_movements

Evidence: src/modules/pharmacy/pages/main-store/data/mainStoreFigma.ts

Normalize medication category/type, base units, prices and reorder thresholds. Do not persist formatted money strings.

### Purchasing / suppliers

Tables: suppliers, purchases, purchase_items, drug_batches, stores, stock_movements, stock_balances

Evidence: src/modules/pharmacy/pages/purchases/data/purchasedMedicationsFigma.ts

Supplier-specific invoice uniqueness; contacts; units-per-pack; quantity/cost/price snapshots; real batch IDs.

### Batch tracking / damage / expiry

Tables: drug_batches, stock_balances, store_drug_settings, stock_adjustments, stock_adjustment_items, stock_movements

Evidence: src/modules/pharmacy/pages/med-tracking/data/medTrackingFigma.ts

Ledger corrections with disposition; stock status derives from expiry and available quantities.

### Medication return

Tables: stock_adjustments, stock_adjustment_items, stock_movements, dispense_items, refunds

Evidence: src/modules/pharmacy/pages/return-med/data/returnMedFigma.ts

Reference original issue; assess condition and remaining returnable amount; do not automatically restock damaged returns.

### Investigation catalog

Tables: investigation_tests, investigation_schemas, services

Evidence: src/pages/diagnostics-and-radiologist/investigative-list/data/investigationListFigma.ts

Catalog IDs define tests; result/order IDs identify patient-specific activity.

### Investigation result entry

Tables: investigation_orders, investigation_items, investigation_tests, investigation_schemas, result_versions, documents, files

Evidence: src/pages/diagnostics-and-radiologist/investigation-profile/InvestigationProfile.tsx

Versioned schemas support CBC, glucose/OGTT, LFT/cardiac markers, electrolytes/urea and ultrasound. Preserve units, reference ranges and author/verification metadata.

### Investigation / visitation logs

Tables: encounters, patients, investigation_orders, investigation_items, result_versions

Evidence: src/pages/diagnostics-and-radiologist/visitation/data/visitationLogFigma.ts

Frontdesk visitation is encounter history; diagnostics views join only the relevant investigations.

### HMO accounts / bills

Tables: hmo_providers, patient_coverages, insurance_plans, tariffs, services, invoices, invoice_items, receipts, payment_allocations

Evidence: src/modules/hmo/pages/hmo-management/bill-summary/data/mockBillSummaryRecords.ts

Report accumulated/paid/outstanding from invoice lines and allocations; do not save independent editable totals.

### Claims

Tables: claims, claim_items, claim_events, claim_settlements, invoices, invoice_items, preauthorizations

Evidence: src/modules/hmo/pages/hmo-management/claims-processor/data/mockClaimsProcessorRecords.ts

Line-level preauthorization and decision; claim history supports resubmissions and partial approvals.

### Insurance registration log

Tables: patient_coverages, patients, insurance_plans, hmo_providers

Evidence: src/modules/hmo/pages/reports/ins-registration-logs/data/mockHmoInsRegistrationLogRecords.ts

Coverage enrollment history joins patient and payer; avoid duplicating demographics.

### Patient clinical / financial profile

Tables: patients, encounters, clinical_templates, clinical_entries, vital_observations, encounter_diagnoses, performed_procedures, prescriptions, prescription_items, medication_administrations, vaccinations, investigation_items, result_versions, documents, files, invoices, invoice_items, receipts, payment_allocations, refunds, claims

Evidence: src/pages/doctor/patientProfile/PatientProfile.tsx; src/modules/hmo/pages/patient-profile

Same patient identity across modules. Server resolves route ID, record scope and active encounter; never default to a sample patient.

### Dashboards

Tables: patients, encounters, admissions, doctor_assignments, investigation_orders, stock_balances, invoices, notifications

Evidence: src/routes/RoleBasedRoutes.tsx

Derived role-scoped read queries. No dashboard-specific patient copy.

## Every route declaration

| Route | Feature | Status | Source |
|---|---|---|---|
| /frontdesk | Dashboards | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:35 |
| /frontdesk | Dashboards | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:36 |
| /frontdesk/edit/:id | Patient registration / edit | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:37 |
| /frontdesk/visitation-log | Investigation / visitation logs | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:38 |
| /frontdesk/registration | Patient registration / edit | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:39 |
| /frontdesk/patient-info-reg | Patient registration / edit | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:40 |
| /frontdesk/patient-Insurance-reg | Patient registration / edit | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:41 |
| /frontdesk/patient-emergency-reg | Patient registration / edit | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:42 |
| /frontdesk/patient-reg-success | Patient registration / edit | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:43 |
| /frontdesk/notifications | Notifications | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:44 |
| /frontdesk/manage-access | Patient access restrictions | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:45 |
| /frontdesk/manage-card | Card conversion / merge | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:46 |
| /frontdesk/reminder | Reminders | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:47 |
| /frontdesk/reminder/set-reminder | Reminders | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:48 |
| /frontdesk/reminder/view-schedule | Reminders | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:49 |
| /frontdesk/book-appointment | Appointments | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:50 |
| /frontdesk/doctor-assignments | Clinician assignment / queue | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:51 |
| /frontdesk/ante-natal | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:52 |
| /frontdesk/child-birth | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:53 |
| /frontdesk/post-natal | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:54 |
| /frontdesk/immunization | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:55 |
| /frontdesk/family-planning | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:56 |
| /frontdesk/registration-log | Patient registration log | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:57 |
| /frontdesk/flag-profile/:id | Flags | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:58 |
| /frontdesk/patient-profile/:id | Patient clinical / financial profile | Proposed schema mapping; runtime not verified | src/modules/frontdesk/routes.tsx:59 |
| /hmo | Dashboards | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:69 |
| /hmo | Dashboards | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:70 |
| /hmo/dashboard | Dashboards | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:71 |
| /hmo/notifications | Notifications | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:72 |
| /hmo/registration | HMO organization registration | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:73 |
| /hmo/account-review | HMO accounts / bills | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:74 |
| /hmo/bill-summary | HMO accounts / bills | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:75 |
| /hmo/claims-processor | Claims | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:76 |
| /hmo/admission | Admission / discharge | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:77 |
| /hmo/available-ward | Available wards / beds | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:78 |
| /hmo/discharge | Admission / discharge | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:79 |
| /hmo/service-list | HMO accounts / bills | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:80 |
| /hmo/reminder | Reminders | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:81 |
| /hmo/ante-natal-logs | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:82 |
| /hmo/child-birth-logs | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:83 |
| /hmo/discharged-logs | Admission / discharge | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:84 |
| /hmo/ins-registration-logs | Insurance registration log | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:85 |
| /hmo/pre-authorisation-logs | Claims | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:86 |
| /hmo/patient-profile/:id | Patient clinical / financial profile | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:90 |
| /hmo/flag-profile/:id | Flags | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:91 |
| /hmo/previous-patient-records/:patientId | Previous patient records | Proposed schema mapping; runtime not verified | src/modules/hmo/routes.tsx:92 |
| /pharmacy | Dashboards | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:34 |
| /pharmacy | Dashboards | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:35 |
| /pharmacy/notifications | Notifications | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:36 |
| /pharmacy/main-store | Pharmacy stores / inventory | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:37 |
| /pharmacy/purchases | Purchasing / suppliers | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:38 |
| /pharmacy/dispense-store | Pharmacy stores / inventory | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:39 |
| /pharmacy/med-tracking | Batch tracking / damage / expiry | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:40 |
| /pharmacy/store-request | Medication requests / requisitions | UI placeholder | src/modules/pharmacy/routes.tsx:41 |
| /pharmacy/return-med | Medication return | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:45 |
| /pharmacy/set-reminder | Reminders | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:46 |
| /pharmacy/admission-logs | Admission / discharge | UI placeholder | src/modules/pharmacy/routes.tsx:47 |
| /pharmacy/discharge-logs | Admission / discharge | UI placeholder | src/modules/pharmacy/routes.tsx:51 |
| /pharmacy/purchase-logs | Purchasing / suppliers | Proposed schema mapping; runtime not verified | src/modules/pharmacy/routes.tsx:55 |
| /pharmacy/dispensed-med-logs | Prescriptions / dispensing reports | UI placeholder | src/modules/pharmacy/routes.tsx:56 |
| /pharmacy/inventory | Pharmacy stores / inventory | UI placeholder | src/modules/pharmacy/routes.tsx:60 |
| /pharmacy/main-store-logs | Pharmacy stores / inventory | UI placeholder | src/modules/pharmacy/routes.tsx:61 |
| /pharmacy/request-logs | Medication requests / requisitions | UI placeholder | src/modules/pharmacy/routes.tsx:65 |
| /pharmacy/return-logs | Medication return | UI placeholder | src/modules/pharmacy/routes.tsx:69 |
| /pharmacy/patient-profile/:id | Patient clinical / financial profile | UI placeholder | src/modules/pharmacy/routes.tsx:73 |
| /pharmacy/previous-patient-records/:id | Previous patient records | UI placeholder | src/modules/pharmacy/routes.tsx:77 |
| /diagnostics-and-radiologist | Dashboards | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:17 |
| /diagnostics-and-radiologist | Dashboards | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:21 |
| /diagnostics-and-radiologist/dashboard | Dashboards | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:22 |
| /diagnostics-and-radiologist/notifications | Notifications | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:23 |
| /diagnostics-and-radiologist/make-request | Medication requests / requisitions | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:24 |
| /diagnostics-and-radiologist/investigations-list | Investigation catalog | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:25 |
| /diagnostics-and-radiologist/set-reminder | Reminders | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:26 |
| /diagnostics-and-radiologist/investigation-logs | Investigation / visitation logs | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:27 |
| /diagnostics-and-radiologist/request-logs | Medication requests / requisitions | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:28 |
| /diagnostics-and-radiologist/visitation-logs | Investigation / visitation logs | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:29 |
| /diagnostics-and-radiologist/patient-profile/:id | Patient clinical / financial profile | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:30 |
| /diagnostics-and-radiologist/previous-patient-records/:id | Previous patient records | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:31 |
| /diagnostics-and-radiologist/investigation-profile/:id | Investigation result entry | Proposed schema mapping; runtime not verified | src/pages/diagnostics-and-radiologist/routes.tsx:35 |
| / | Preview / fallback | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:86 |
| /auth | Staff identity / account | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:88 |
| /auth/verification | Staff identity / account | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:94 |
| /forgot-password | Staff identity / account | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:98 |
| /frontdesk | Dashboards | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:104 |
| /frontdesk/previous-patient-records/:patientId | Previous patient records | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:105 |
| /frontdesk/book-appointment | Appointments | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:109 |
| /nurse | Dashboards | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:122 |
| /nurse | Dashboards | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:123 |
| /nurse/dashboard | Dashboards | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:124 |
| /nurse/notifications | Notifications | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:125 |
| /nurse/admission | Admission / discharge | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:126 |
| /nurse/available-ward | Available wards / beds | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:127 |
| /nurse/discharge | Admission / discharge | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:128 |
| /nurse/make-request | Medication requests / requisitions | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:129 |
| /nurse/reminder | Reminders | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:130 |
| /nurse/reports/admission | Admission / discharge | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:131 |
| /nurse/reports/discharge | Admission / discharge | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:132 |
| /nurse/child-birth | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:133 |
| /nurse/ante-natal | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:134 |
| /nurse/post-natal | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:135 |
| /nurse/family-planning | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:136 |
| /nurse/immunization | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:137 |
| /nurse/dispensed-drugs | Prescriptions / dispensing reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:138 |
| /nurse/report-writing | Reports / nursing notes | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:139 |
| /nurse/requisition | Medication requests / requisitions | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:140 |
| /nurse/patient-profile/:id | Patient clinical / financial profile | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:141 |
| /nurse/flag-profile/:id | Flags | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:142 |
| /nurse/previous-patient-records/:patientId | Previous patient records | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:143 |
| /doctor | Dashboards | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:150 |
| /doctor | Dashboards | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:151 |
| /doctor/notifications-doctor | Notifications | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:152 |
| /doctor/patient-profile/:id | Patient clinical / financial profile | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:153 |
| /doctor/account | Staff identity / account | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:154 |
| /doctor/account/profile | Staff identity / account | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:155 |
| /doctor/flag-profile/:id | Flags | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:156 |
| /doctor/previous-patient-records/:patientId | Previous patient records | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:157 |
| /doctor/admission | Admission / discharge | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:161 |
| /doctor/available-ward | Available wards / beds | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:162 |
| /doctor/dispensed-drugs | Prescriptions / dispensing reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:163 |
| /doctor/discharge | Admission / discharge | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:164 |
| /doctor/make-request | Medication requests / requisitions | UI placeholder | src/routes/RoleBasedRoutes.tsx:165 |
| /doctor/set-reminder | Reminders | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:169 |
| /doctor/reports/admission | Admission / discharge | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:170 |
| /doctor/reports/discharge | Admission / discharge | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:171 |
| /doctor/doctor-logs | Clinician assignment / queue | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:172 |
| /doctor/doctor-assignments | Clinician assignment / queue | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:173 |
| /doctor/immunization | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:174 |
| /doctor/ante-natal | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:175 |
| /doctor/child-birth | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:176 |
| /doctor/post-natal | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:177 |
| /doctor/registration-log | Patient registration log | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:178 |
| /doctor/family-planning | Maternal, family-planning and immunization reports | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:179 |
| /doctor/report-writing | Reports / nursing notes | UI placeholder | src/routes/RoleBasedRoutes.tsx:183 |
| /doctor/requisition | Medication requests / requisitions | UI placeholder | src/routes/RoleBasedRoutes.tsx:187 |
| /admin | Staff identity / account | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:195 |
| /admin | Staff identity / account | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:196 |
| /admin/users | Staff identity / account | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:197 |
| /* | Preview / fallback | Proposed schema mapping; runtime not verified | src/routes/RoleBasedRoutes.tsx:201 |

## Boundaries that remain application responsibilities

- Canonical identity resolution after a merge; prevent cycles, preserve original records and identifiers, and serialize changes to both patient rows.
- Insert each current/retired MRN into patient_identifiers in the same transaction; prevent identifier collisions across primary and historical namespaces.
- Authorization and patient scope, active admission per patient, consistent program-visit patient, follow-up ownership, and same-item amendment chains.
- Check selected batches belong to the issuing store; lock prescription items/balances and prohibit over-dispensing, expired issues and over-returns.
- Treat signed entries and released results as immutable. Validate every JSON payload against an approved clinical/test template; field candidates are not schemas.
- Prevent overlapping tariff/drug price periods and appointment slot collisions using agreed locking rules.
- Compute pack quantities and rounded financial amounts on the server. Lock receipts/invoices and prevent allocations + refunds exceeding received funds.
- Enforce same-invoice/service/encounter claim and preauthorization links, currency agreement, payer splits, and non-duplicated billing sources.
- Use transactional stock ledger posting and queue/outbox delivery; reconcile balance caches. Nursing administration must not duplicate pharmacy deductions.
- Decide clinical meaning for patient restriction, alive/deceased, insurance eligibility, deposit/refund and staged delivery report fields with hospital users.

## Required completion evidence

Run verify_mysql.py on disposable MySQL 8.4, implement Laravel migrations/services/policies, then test concurrent allocation/dispensing/payment and browser workflows for every role. No claim of “works perfectly” is supported before those gates pass.

## Primary references

[MySQL foreign keys](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html), [MySQL CHECK constraints](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html), [InnoDB locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html). Accessed 4 November 2026.
