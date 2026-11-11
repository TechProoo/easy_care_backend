# EasyCare backend build checklist

Updated 11 November 2026. Follow the stages in order. Tick a task only when its stated result is demonstrated. This is an implementation checklist, not a record of completed hospital features.

## Already completed

- [x] Install the local PHP, Composer, Laravel and MySQL development foundation.
- [x] Configure the local development database connection and starter migrations.
- [x] Add the basic application status response and starter tests.
- [x] Prepare the architecture proposal, database dictionary and frontend review.
- [x] Publish the initial backend repository.

The full 85-table proposal is not implemented as application migrations. Staff/roles, audit history, private files, command safeguards, patient storage and registration/search/edit APIs are implemented and tested. Patient screen integration and later hospital workflows remain to be implemented. See each stage's evidence below.

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
- [x] **12. Connect the frontend to one real identity system.** Replace mock login and competing auth stores; protect module routes, restore sessions safely and clear user-specific cached data when accounts change.

Stage complete when each role can sign in, use only permitted operations and sign out through the real frontend.

Task 9 completed 11 November 2026: session login, CSRF bootstrap, current-user lookup and logout are implemented and connected to the frontend. Sessions rotate on login and are invalidated on logout; expired sessions, inactive accounts and changed passwords lose access. Login is throttled and no bearer token or browser-stored identity is trusted. All 67 backend tests passed on MySQL; eight browser checks passed, and the frontend builds. A private interactive `staff:create` console command supports first-account setup without a default password. Tasks 11 and 12 are partially addressed by this setup and sign-in integration; the full permissions matrix, administration/recovery UI and clinical cache isolation remain unfinished. Existing patient and clinical screens still contain demonstration data.

Task 10 completed for current APIs on 11 November 2026: known permission gates, model policies, staff scopes and a protected permission catalog are implemented. Ordinary roles initially read departments and their own directory entry; the admin preset also reads the full directory and grants catalog. Department-directory access is opt-in, null departments fail closed, and admin has no bypass. Console grant/revoke operations take effect on subsequent requests; repeat seeding preserves deliberate revocations. All 91 tests passed on MySQL (456 assertions); SQLite passed 90 tests with one MySQL-only check skipped. The additive migration and initial grants are applied locally. Staff administration writes remain task 11. This does not finalize hospital patient-access decisions or authorize the clinical demonstration screens; every future resource must add its own permission and record-scope tests. See [the authorization contract](LOCAL_DEVELOPMENT.md#server-side-authorization).

Task 11 implementation delivered locally on 11 November 2026: authorized staff creation, profile edits, department/role assignment, activation/deactivation and administrator-assisted recovery are connected to `/admin/users` and `/forgot-password`. Mutations require current-password reauthentication and version checks. Managers cannot grant or modify access above their own, change their own access controls, or delete staff. Recovery codes are hashed, expire after 15 minutes, replace older codes and work once; redemption ends existing sessions. A trusted interactive `staff:recover` console command supports sole-administrator recovery. The new migration and a separate one-time admin grant baseline are applied locally. All 113 tests passed on MySQL (647 assertions); SQLite passed 112 with one MySQL-only check skipped. Nine browser checks passed, including mobile recovery, stale-edit protection, deactivation and reactivation; no page errors occurred. The frontend builds; 126 existing TypeScript errors remain outside changed files. **Task 11 stays unchecked until the hospital approves the identity-verification and console-operator procedure.** See [the implementation and recovery contract](LOCAL_DEVELOPMENT.md#staff-administration-and-recovery).

## Stage 4: Add shared record safeguards

Task 12 completed for the current frontend on 11 November 2026: server sessions are the sole identity source, including clinician and shared profile screens. Browser-only profile/password editing and obsolete registration/OTP requests are removed from active flows. An access scope identifies the staff member, department, roles and permissions. Changes clear private component state, patient-table/flag caches, pending clinical entries, uploaded-file URLs and old browser storage. Routine refreshes of the same access scope preserve open forms. The API client aborts requests at a boundary and rejects late responses, including old `401` failures and writes still waiting for CSRF. Sign-in, sign-out and recovery notify other tabs to recheck the server; expiry and restored browser pages block private content during verification. Old history entries cannot restore another session's patient state. `npm test` provides eleven reproducible frontend isolation checks without new packages. Later patient workflows must still implement their own server authorization and patient-specific data loading; this does not make clinical demonstrations operational.

Task 12 verification: all eleven frontend tests passed. Twenty-five browser checks passed across sign-in, administration/recovery and multi-tab isolation, with no page errors. All 113 backend tests passed on MySQL (651 assertions); SQLite passed 112 tests (650 assertions), with one MySQL-only check skipped. The frontend builds. Type checking reports 125 remaining errors, all outside changed files; one existing unused declaration in a touched file was removed. Backend staff-session responses now include real creation and last-login timestamps for profile displays.

- [x] **13. Implement audit history.** Capture who performed important actions, when, on which record and with what outcome. Protect audit access and avoid recording passwords or unnecessary clinical content in logs.
- [x] **14. Implement protected file storage.** Support validated uploads, ownership, authorized downloads and file metadata. Store patient documents outside public access; prepare file storage for backups.
- [x] **15. Establish transaction and retry rules.** Use transactions for multi-record saves, locking where concurrent users can conflict and duplicate-submission protection for sensitive operations. Use version checks where one staff member could overwrite another's work.
- [x] **16. Add reusable feature verification.** Establish meaningful API/database tests for permissions, validation, audit events and rollback. Define the corresponding browser checks to repeat as modules are added.

Task 13 completed for current staff workflows on 11 November 2026: append-only audit events record staff creation/profile/access changes, recovery issuance/redemption, console grants/revocations, sign-in/out, history reads and rejected attempts on those routes. Records identify the actor, record, outcome, source and UTC time without copying credentials, submitted identities or personal profile values. Staff mutations and their success events commit together; audit failure rolls changes back. MySQL/SQLite triggers reject updates and deletes. A dedicated `audit.view` grant protects the read API and `/admin/audit`; reviewer-only roles cannot manage staff. Filters, an upper ID boundary for pagination, refresh, empty/error states and mobile layouts are connected. The MySQL trigger-installation prerequisite is checked before table creation and documented; application accounts received no global privileges. All 140 backend tests passed on MySQL (840 assertions); SQLite passed 139 (839 assertions), with one MySQL-only check skipped. Twelve frontend tests and thirty-nine browser checks passed with no page errors, and the production build passed. The full TypeScript check still has 125 existing errors outside changed files. Local migrations/seeding are applied; there are eight permissions, nineteen grants, three baseline markers and no development staff/reset/audit rows. Clinical auditing, retention and production operating procedures remain with their later stages. See [the audit contract](LOCAL_DEVELOPMENT.md#audit-history).

Task 14 completed for the shared private-file foundation on 11 November 2026: `/admin/files` uploads and lists the current staff member's own PDF/JPEG/PNG files, supports authenticated downloads and reports scan status. The APIs validate actual content MIME, extension, non-empty size and a 10 MiB limit. Ownership, random storage keys, sizes and SHA-256 digests are server-controlled. The dedicated private local disk has no public/signed storage URLs; path checks and safe attachment headers protect downloads. Stored bytes are verified before upload success and before each download. Metadata and upload audit commit together; failures roll back and attempt blob cleanup. Permissions are explicit, including file-only/read-only roles; administrators cannot bypass another uploader's ownership. File actions extend the restricted audit trail. A read-only `files:verify` command detects missing, corrupt and unreferenced bytes; the paired database/files backup procedure is documented. All 171 backend tests passed on MySQL (1,068 assertions); SQLite passed 170 (1,067 assertions), with one MySQL-only check skipped. Seventeen frontend tests and fifty-two browser checks passed with no page errors, including exact-byte downloads, upload spoofing, mobile layout, permission revocation and account switching. The production build passed; the 125 existing TypeScript errors remain outside changed files. Development migrations/grants are applied: ten permissions, twenty-one grants, four baseline markers, no staff accounts or file records; a local integrity check passed. **Files are not virus-scanned.** Patient attachments/clinical authorization and production scanner policy belong to their dependent workflows and hospital acceptance. Scheduled backups and a demonstrated restore remain task 54. See [the file/API and backup preparation contract](LOCAL_DEVELOPMENT.md#protected-files).

Task 15 completed for current staff and private-file workflows on 11 November 2026: sensitive writes require a per-submission request key. Claims, related records, success audit and resource completion commit together; exact retries confirm one resource before uniqueness/version validation can reject an already completed save. Current permissions, password reauthentication, target scope and ownership are rechecked. Changed payloads, targets, expired keys and later staff versions fail closed. Recovery secrets are shown only at first issuance; duplicate issuance neither repeats the mutation nor caches/redisplays a code. A shared row-lock order coordinates current staff/files/recovery/grant writes, existing version guards prevent overwrite, and database-only concurrency retries are bounded at three attempts. Filesystem writes are never automatically replayed; outer failures clean up only unreferenced blobs and preserve bytes when commit state is uncertain. The frontend retains unchanged pending submissions for manual confirmation, bounds requests at 30 seconds and displays retry guidance. The additive development migration is applied, with no development users, files or command records created. Verification: 200 MySQL tests (1,238 assertions), twenty-six frontend tests, thirty-two browser checks with no page errors, zero TypeScript errors and a passing production build. SQLite verification is recorded in the development notes. Hospital identity verification, clinical business rules, operational retention and backup/restore acceptance remain with their dependent tasks. See [the transaction and retry contract](LOCAL_DEVELOPMENT.md#transaction-and-retry-rules).

Task 16 completed on 11 November 2026: shared test helpers create explicitly authorized staff, validate API failures, compare full table contents for rollback, verify audit attribution/privacy and inject failures at a chosen database write. Four existing suites reuse them. Private-file fakes are isolated by database driver, process and test, with cleanup of the owned directory. Thirteen new verification cases exercise existing APIs and guard against false passes from unchanged row counts, empty snapshots and escaped audit values. `composer verify` runs SQLite then MySQL and stops on failure. All 213 MySQL tests passed (1,460 assertions); SQLite passed 209 tests (1,437 assertions), with four MySQL-only checks skipped. The repeatable browser checklist covers permissions, persistence, stale edits, duplicate commands, lost responses and account changes; it defines evidence to record for each future module. No new frontend behavior or browser run is claimed for this task. Patient access rules and hospital approvals remain open. See [the verification recipe](LOCAL_DEVELOPMENT.md#reusable-feature-verification).

Stage complete when shared safeguards work in tests and a failed operation cannot leave a partly saved record.

## Stage 5: Deliver the first complete patient workflow

- [x] **17. Create patient migrations and models.** Include demographics, categories, identifiers, contacts and relationships. Enforce patient-number uniqueness across current and historical identifiers. Add coverage structures when insurance work needs them.
- [x] **18. Build registration, search and editing APIs.** Save related records together; validate required fields, dates and duplicate candidates. Provide pagination and authorized patient lookup by ID.
- [ ] **19. Implement patient record management.** Add flags, access restrictions, card/category changes and controlled duplicate merging. Preserve identifiers and history, prevent merge cycles and audit changes.
- [ ] **20. Connect patient screens and verify isolation.** Registration success must follow a committed save. Resolve profile URLs by patient ID, remove sample-patient fallback and scope all patient state correctly.

Task 17 completed on 11 November 2026: an additive migration creates patient categories, demographics, number reservations, emergency/next-of-kin contacts and directed family relationships, with five models and synthetic factories. Foreign keys, unique keys and database guards enforce references, contact priority, distinct relatives, valid status/version state and canonical numbering. Patient insert/MRN-change triggers reserve every current number and preserve retired numbers in one globally unique registry, including raw SQL writes. Number reservations cannot be rewritten, moved, reactivated or deleted; collisions roll back the entire statement. Seven initial categories match the current registration screen; repeat seeding preserves local edits and adds no patients or patient-access grants. The migration and seeder are applied locally, with seven categories and zero patients, identifiers, contacts, family links, staff, files or commands. All 272 backend tests passed on MySQL (1,651 assertions); SQLite passed 268 (1,628 assertions), with four MySQL-only skips. The 59 new cases verify relationships, number collisions/history, rollback, Unicode/date handling, integrity guards and migration reapplication. No frontend changes or browser run were required for this storage task. Patient APIs, approved access rules, allocation format, clinical audit and screen integration remain with their dependent tasks. Coverage/insurance structures remain deferred until that work needs them. See [the patient storage contract and relationships](LOCAL_DEVELOPMENT.md#patient-storage-foundation).

Task 18 completed on 11 November 2026: six session-protected endpoints provide registration, active-record lookup/search, category options, duplicate checks and partial demographic/contact edits. Staff-entered numbers and provisional opt-in access were confirmed for this implementation; no role receives patient permissions automatically. Restricted/merged records remain hidden across direct IDs, lists, historical-number queries, duplicate candidates, edits and confirmations. Validation covers real dates, unknown/estimated dates, bounded Unicode/text/contact fields and protected-field rejection. Candidate review matches normalized name/date or patient phone, requires exact fresh acknowledgements and never merges records automatically. Related rows, number reservation, audit and command completion commit together; failed writes roll back the entire aggregate, stale edits fail, and same-key retries confirm the existing authorized version. Patient events extend the audit viewer and contain no demographic/contact values in their safe summaries; patient database exceptions omit SQL/bindings from reporting. A birth-date index is applied locally. The complete gate passed 331 MySQL tests (2,547 assertions); SQLite passed 324 tests (2,497 assertions) with seven MySQL-only skips. Fifty-six API cases and three real parallel cases were added. Twenty-six frontend tests and the TypeScript production build passed. Fourteen real-session browser checks passed with no page errors, including lost committed responses, read-only access and desktop/mobile patient audit filtering. Patient forms remain demonstrations until Task 20; hospital acceptance and the full clinical permission matrix remain open. See [the patient API contract](LOCAL_DEVELOPMENT.md#patient-registration-search-and-editing-apis).

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

**Task 19: implement patient record management.** Add flags, approved restriction/restore behavior, card/category history and controlled duplicate merging. Preserve stable patient IDs and number reservations, prevent merge cycles, audit changes and define how authorized lookup resolves merged identities. The confirmed provisional API scope hides restricted/merged records; emergency access and wider clinical rules still require hospital decisions. Stage 1 decisions remain unchecked, and Task 11 still needs hospital approval of its identity-verification and console-operator procedure. Patient form integration and browser isolation remain Task 20. No unfinished task above is marked complete merely because its table exists in the proposal.

## Reference documents

- [Backend architecture PDF](architecture/EasyCare_Architecture_Reviewed_v1.1.pdf)
- [Database dictionary](architecture/Database_Dictionary_v1.1.md)
- [Proposed MySQL schema](architecture/schema.mysql.sql)
- [Frontend/database review](architecture/Frontend_Database_Review.md)
- [Local development notes](LOCAL_DEVELOPMENT.md)
