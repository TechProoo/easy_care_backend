# EasyCare backend build checklist

Updated 11 November 2026. Follow the stages in order. Tick a task only when its stated result is demonstrated. This is an implementation checklist, not a record of completed hospital features.

## Already completed

- [x] Install the local PHP, Composer, Laravel and MySQL development foundation.
- [x] Configure the local development database connection and starter migrations.
- [x] Add the basic application status response and starter tests.
- [x] Prepare the architecture proposal, database dictionary and frontend review.
- [x] Publish the initial backend repository.

The full 85-table proposal is not implemented as application migrations. The staff/role foundation is now migrated and covered by database/API tests; patient and other hospital workflows remain unimplemented. See the Stage 2 evidence below.

## How to use this checklist

Start at task 1. Keep schema changes, backend behavior, frontend integration and tests together for each feature. Do not wait until the end to connect the frontend or test permissions. Create domain tables as their stages need them; the full proposal is a reference, not a requirement to create all tables before the first working feature.

For every completed feature, verify valid input, invalid input, unauthorized access, another patient's records, persistence after refresh, and relevant transaction failures. Record the result in the task or its linked issue. Commit reviewed work at each completed stage; push according to the team's workflow. Keep passwords, real patient records and runtime files out of commits.

## Stage 1: Confirm the implementation scope

- [ ] **1. Agree the first release and workflow order.** Review the existing screens with hospital representatives and identify required workflows, placeholders and deferred features. Record who owns acceptance for each module.
- [ ] **2. Agree roles and permissions.** Define who can view, create, change, approve, export or administer each record, including patient restrictions and emergency access rules.
- [ ] **3. Resolve hospital-specific rules.** Agree patient numbering, duplicate handling, visit types, appointment rules, admission/discharge authority, clinical sign-off, billing, insurance, stock units and local timezone. Confirm required clinical fields with the relevant staff.
- [ ] **4. Reconcile the database proposal with those decisions.** Map each required screen field to storage and validation rules. Resolve gaps before implementing the affected module; keep the dictionary and API contracts current as decisions change.

Stage complete when the first release has an agreed scope, permissions matrix and recorded business rules.

## Stage 2: Establish the database and API foundation

- [x] **5. Separate development, test and hospital configuration.** Use distinct databases, private environment settings and synthetic test data. Automated tests must not target hospital records or the everyday development database.
- [x] **6. Verify the proposed schema on disposable MySQL 8.4.** Check actual MySQL types, constraints, indexes and dependency order; resolve differences from the earlier SQLite projection. Exercise critical integrity rules, not only table creation.
- [x] **7. Create the first Laravel domain migrations.** Reconcile the starter users table with the staff design; implement departments, roles, permissions and their relationships. Add reference seed data and database-backed models as needed. A clean database must migrate and seed successfully.
- [x] **8. Establish the API contract.** Configure API routing, consistent validation errors, pagination, filtering, date/money formats and frontend connection settings. Document how authentication and cross-origin development requests will work.

Stage complete when a clean test database can be built and the frontend can call a real, consistently formatted backend endpoint.

Completed 10 November 2026: additive staff migrations are applied to `easycare_dev`; reference seeding is repeatable. `easycare_test` has a separate restricted account and guarded test configuration. A production configuration template is prepared; hospital hosting is not deployed. The complete proposal was imported into a disposable MySQL 8.4.13 schema and passed 38 relationship/constraint/locking checks. The API returns versioned JSON, validates department queries, provides pagination/sorting/filtering, restricts reference-data access and permits the configured frontend origin. The live readiness endpoint returned HTTP 200. See [the API contract and test commands](LOCAL_DEVELOPMENT.md). This does not mark staff login, the permissions matrix or any clinical workflow complete.

## Stage 3: Build staff accounts and authorization

- [x] **9. Implement staff login, logout and session handling.** Use secure password hashing, browser session protection, login throttling and account activation checks. Test that logout ends access and disabled staff cannot sign in.
- [x] **10. Implement server-side authorization for current APIs.** Enforce role permissions and record scope on every protected resource endpoint. A hidden frontend button must not be the only restriction. Extend policies and scope with each later workflow.
- [ ] **11. Implement staff administration and account recovery.** Support authorized staff creation, department/role assignment, profile changes, deactivation and a hospital-approved password reset process that works with the local deployment.
- [ ] **12. Connect the frontend to one real identity system.** Replace mock login and competing auth stores; protect module routes, restore sessions safely and clear user-specific cached data when accounts change.

Stage complete when each role can sign in, use only permitted operations and sign out through the real frontend.

Task 9 completed 11 November 2026: session login, CSRF bootstrap, current-user lookup and logout are implemented and connected to the frontend. Sessions rotate on login and are invalidated on logout; expired sessions, inactive accounts and changed passwords lose access. Login is throttled and no bearer token or browser-stored identity is trusted. All 67 backend tests passed on MySQL; eight browser checks passed, and the frontend builds. A private interactive `staff:create` console command supports first-account setup without a default password. Tasks 11 and 12 are partially addressed by this setup and sign-in integration; the full permissions matrix, administration/recovery UI and clinical cache isolation remain unfinished. Existing patient and clinical screens still contain demonstration data.

Task 10 completed for current APIs on 11 November 2026: known permission gates, model policies, staff scopes and a protected permission catalog are implemented. Ordinary roles initially read departments and their own directory entry; the admin preset also reads the full directory and grants catalog. Department-directory access is opt-in, null departments fail closed, and admin has no bypass. Console grant/revoke operations take effect on subsequent requests; repeat seeding preserves deliberate revocations. All 91 tests passed on MySQL (456 assertions); SQLite passed 90 tests with one MySQL-only check skipped. The additive migration and initial grants are applied locally. Staff administration writes remain task 11. This does not finalize hospital patient-access decisions or authorize the clinical demonstration screens; every future resource must add its own permission and record-scope tests. See [the authorization contract](LOCAL_DEVELOPMENT.md#server-side-authorization).

Task 11 implementation delivered locally on 11 November 2026: authorized staff creation, profile edits, department/role assignment, activation/deactivation and administrator-assisted recovery are connected to `/admin/users` and `/forgot-password`. Mutations require current-password reauthentication and version checks. Managers cannot grant or modify access above their own, change their own access controls, or delete staff. Recovery codes are hashed, expire after 15 minutes, replace older codes and work once; redemption ends existing sessions. A trusted interactive `staff:recover` console command supports sole-administrator recovery. The new migration and a separate one-time admin grant baseline are applied locally. All 113 tests passed on MySQL (647 assertions); SQLite passed 112 with one MySQL-only check skipped. Nine browser checks passed, including mobile recovery, stale-edit protection, deactivation and reactivation; no page errors occurred. The frontend builds; 126 existing TypeScript errors remain outside changed files. **Task 11 stays unchecked until the hospital approves the identity-verification and console-operator procedure.** See [the implementation and recovery contract](LOCAL_DEVELOPMENT.md#staff-administration-and-recovery).

## Stage 4: Add shared record safeguards

- [ ] **13. Implement audit history.** Capture who performed important actions, when, on which record and with what outcome. Protect audit access and avoid recording passwords or unnecessary clinical content in logs.
- [ ] **14. Implement protected file storage.** Support validated uploads, ownership, authorized downloads and file metadata. Store patient documents outside public access; prepare file storage for backups.
- [ ] **15. Establish transaction and retry rules.** Use transactions for multi-record saves, locking where concurrent users can conflict and duplicate-submission protection for sensitive operations. Use version checks where one staff member could overwrite another's work.
- [ ] **16. Add reusable feature verification.** Establish meaningful API/database tests for permissions, validation, audit events and rollback. Define the corresponding browser checks to repeat as modules are added.

Stage complete when shared safeguards work in tests and a failed operation cannot leave a partly saved record.

## Stage 5: Deliver the first complete patient workflow

- [ ] **17. Create patient migrations and models.** Include demographics, categories, identifiers, contacts and relationships. Enforce patient-number uniqueness across current and historical identifiers. Add coverage structures when insurance work needs them.
- [ ] **18. Build registration, search and editing APIs.** Save related records together; validate required fields, dates and duplicate candidates. Provide pagination and authorized patient lookup by ID.
- [ ] **19. Implement patient record management.** Add flags, access restrictions, card/category changes and controlled duplicate merging. Preserve identifiers and history, prevent merge cycles and audit changes.
- [ ] **20. Connect patient screens and verify isolation.** Registration success must follow a committed save. Resolve profile URLs by patient ID, remove sample-patient fallback and scope all patient state correctly.

Stage complete when staff can log in, register a patient, refresh, search and reopen the saved record without seeing another patient's data. This is the first major end-to-end milestone.

## Stage 6: Build appointments, visits and queues

- [ ] **21. Create appointment, encounter and assignment records.** Define visit statuses and transitions, including booking before registration where required. Preserve booking contact details and assignment history.
- [ ] **22. Implement appointment booking and updates.** Support rescheduling, cancellation, follow-ups and the agreed conflict rules. Verify simultaneous requests cannot bypass slot restrictions.
- [ ] **23. Implement arrival, clinician assignment and queues.** Link a registered patient to the correct visit, assign or reassign staff and record acceptance, progress and completion with authorized transitions.
- [ ] **24. Connect frontdesk and clinician screens.** Replace mock bookings, queues and visitation logs. Test a booking from creation through arrival and doctor acceptance.

Stage complete when frontdesk and clinicians see the same saved visit and assignment state.

## Stage 7: Build clinical records and nursing observations

- [ ] **25. Create encounter-scoped clinical storage.** Add vitals, clinical templates/entries, diagnoses, procedures, prescriptions and their relationships as required by the agreed screens.
- [ ] **26. Implement clinical entry and review.** Support structured notes, vitals, diagnoses and procedures with approved field validation. Preserve the author and distinguish draft, signed and amended records.
- [ ] **27. Implement prescriptions and patient history.** Link prescriptions to the correct patient and encounter; retain medication instructions and status. Present an authorized history without making duplicate writable patient records.
- [ ] **28. Connect doctor and nurse clinical screens.** Replace global clinical state with patient/encounter-scoped requests. Test two patients and two concurrent staff sessions; signed records must be amended through history rather than silently overwritten.

Stage complete when clinical work persists against the correct encounter and authorized staff can review its history.

## Stage 8: Build admissions and specialist nursing workflows

- [ ] **29. Create wards, beds and admissions.** Implement bed allocation and transfer history. Enforce the agreed active-admission rules and prevent two patients from occupying the same bed concurrently.
- [ ] **30. Implement admission, transfer and discharge.** Separate clinician authorization from nursing completion where required; record actual timestamps and derive bed availability from occupancy.
- [ ] **31. Implement care programs.** Add approved antenatal, postnatal, family-planning, delivery, birth and immunization workflows. Link each baby separately for multiple births and validate program/patient relationships.
- [ ] **32. Connect nursing screens and shared ward views.** Replace mock admission/report state and incompatible role-specific contexts. Verify that all authorized roles see consistent occupancy and episode history.

Stage complete when a patient can be admitted, transferred and discharged without bed conflicts, and required care-program records persist.

## Stage 9: Build laboratory and radiology workflows

- [ ] **33. Create investigation catalogs and result templates.** Define tests, units, reference ranges, approved result structures and service references. Version templates so later changes do not alter old results.
- [ ] **34. Implement investigation requests and work queues.** Link orders/items to the patient, encounter and requesting clinician; support agreed collection, progress and cancellation states.
- [ ] **35. Implement result entry, verification and release.** Store authors, reviewers, attachments and result versions. Restrict release and retain amendment history after publication.
- [ ] **36. Connect diagnostics and clinician result screens.** Verify ordering, processing, result release and authorized viewing through the frontend. Keep medication requisitions distinct from investigation requests.

Stage complete when a clinician can request an investigation and read its properly released result on the same patient record.

## Stage 10: Build pharmacy, purchasing and stock control

- [ ] **37. Create medication and stock foundations.** Implement drugs, base units and pack conversions, suppliers, stores, batches, prices, stock movements and balances. Agree rounding and valuation behavior where required.
- [ ] **38. Implement purchasing and stock operations.** Support receipts, department requisitions, approvals, transfers, adjustments, damage and expiry. Preserve invoice/batch references and reconcile balances with the stock ledger.
- [ ] **39. Implement dispensing, returns and nursing administration.** Lock prescription quantities and selected stock; prevent expired issues, negative stock, over-dispensing and excessive returns. Record administered doses separately so nursing does not deduct issued stock again.
- [ ] **40. Connect pharmacy and medication screens.** Verify purchase-to-dispense flows, partial fulfillment, returns and concurrent stock requests. Separate usable returns from damaged stock; add low-stock/expiry views.

Stage complete when every stock change is traceable and two users cannot issue the same remaining stock.

## Stage 11: Build billing and insurance

- [ ] **41. Create prices and billing records.** Implement services, effective-dated prices, invoices and items, receipts, allocations and refunds. Store numeric monetary values and historical price snapshots; agree currency and rounding rules.
- [ ] **42. Implement cash/private billing.** Generate charges once from their source, support agreed deposits and partial payments, calculate balances on the server and authorize corrections/refunds. Lock financial records to prevent duplicate posting or over-allocation.
- [ ] **43. Implement HMO workflows.** Add providers, plans, patient coverage, validated tariff imports, preauthorizations, claims, decisions, resubmissions and settlements. Enforce payer splits and consistent patient/invoice/service links.
- [ ] **44. Connect financial screens and reconcile totals.** Verify invoices, receipts, refunds, outstanding balances and HMO accounts against their underlying transactions, including concurrent payment and partial claim scenarios.

Stage complete when patient and payer balances can be explained from recorded charges, payments, refunds and settlements.

## Stage 12: Complete shared operational views

- [ ] **45. Implement persistent reminders and notifications.** Support recipients, due times, recurrence, snooze and read status. Configure background jobs, retries and duplicate prevention for local-server operation.
- [ ] **46. Implement role-scoped dashboards and reports.** Derive figures from saved operational records with consistent filters and dates; do not maintain separate editable totals or patient copies.
- [ ] **47. Implement authorized exports and printing.** Add required documents and reports; check permission when generating and downloading files, audit exports and remove demonstration OTP gates.
- [ ] **48. Finish the frontend-to-API coverage pass.** Check every required route and form against the API contract. Remove operational mock data, repair broken navigation/form wiring and handle loading, empty, forbidden, not-found and failed-save states. Resolve build/type/lint issues that prevent a reliable release.

Stage complete when all required frontend workflows use real persisted records and each report/export obeys role permissions.

## Stage 13: Verify the complete system

- [ ] **49. Run full backend workflow tests on MySQL.** Cover each module, migration paths, permissions, patient isolation, validation, duplicate submissions and rollback. Expand tests with each preceding stage; this task is the final combined run.
- [ ] **50. Run concurrency and performance checks.** Exercise competing bed allocations, stock issues, appointments, edits and payments. Check indexes, pagination, expected patient volumes and multiple simultaneous hospital users.
- [ ] **51. Run browser acceptance tests with hospital staff.** Follow realistic scenarios for every role from registration through care, diagnostics, medication, discharge and payment. Fix critical issues and obtain recorded acceptance for the release scope.
- [ ] **52. Verify operation without internet access.** Bundle required fonts/assets locally; verify sessions, reports, notifications and recovery workflows on the hospital LAN. Report failed saves honestly and define behavior during server/network outages; a local server does not provide offline browser synchronization automatically.

Stage complete when recorded evidence demonstrates the agreed release workflows on the actual database and supported browsers.

## Stage 14: Prepare and release the hospital installation

- [ ] **53. Set up the hospital server.** Install the production web server/PHP/MySQL configuration; build frontend assets, configure LAN addressing and HTTPS, restrict direct database access and arrange service startup after reboot. Disable debug output and use production environment settings; do not use the development server for hospital hosting.
- [ ] **54. Implement and prove recovery.** Back up both MySQL and uploaded files to protected storage separate from the live data. Agree schedule, retention and recovery targets; restore into an isolated installation and document the successful recovery procedure.
- [ ] **55. Prepare operations and release handover.** Configure scheduler/queue supervision, logs, disk and service monitoring, update procedures and accountable administrators. Prepare staff accounts, reference data and any agreed legacy-data import with validation. Document deployment/rollback and staff operating instructions.
- [ ] **56. Run the production readiness review and controlled launch.** Confirm acceptance, access restrictions, recovery evidence, migration and rollback plans; pilot with selected staff, resolve pilot issues, then obtain hospital authorization for wider use and hand over support ownership.

Stage complete when the hospital has accepted the working release and can operate, maintain and recover it.

## Current next task

**Task 12: finish frontend identity and cache isolation.** Staff administration and recovery are implemented for local review; task 11 still needs hospital approval of its identity-verification and console-operator procedure. Finish the remaining identity/cache checks before patient registration. Stage 1 hospital decisions remain unchecked and must be confirmed before dependent workflows are finalized. No unfinished task above is marked complete merely because its table exists in the proposal.

## Reference documents

- [Backend architecture PDF](architecture/EasyCare_Architecture_Reviewed_v1.1.pdf)
- [Database dictionary](architecture/Database_Dictionary_v1.1.md)
- [Proposed MySQL schema](architecture/schema.mysql.sql)
- [Frontend/database review](architecture/Frontend_Database_Review.md)
- [Local development notes](LOCAL_DEVELOPMENT.md)
