# EasyCare backend

Laravel PHP backend for the EasyCare React application. Database/API foundations, staff sessions, patient registration, record management and appointment booking/update APIs are implemented. The frontend saved-patient workspace is connected; hospital acceptance and later clinical workflows remain open.

## Local Windows setup

The installed development programs live in `../.tools/`; all Laravel application code lives here in `backend/`. PHP and Composer are project-local, so use the helper below instead of relying on global Windows commands.

From a terminal in this directory:

```powershell
.\dev.cmd start
```

Open **http://localhost:8000**. A successful response is:

```json
{"application":"EasyCare API","status":"running"}
```

The helper starts MySQL and the PHP development server in the background. After restarting Windows, run it again. The React frontend is separate and remains in `../easycare2/`.

## Useful commands

```powershell
.\dev.cmd status
.\dev.cmd stop
.\dev.cmd php -v
.\dev.cmd composer --version
.\dev.cmd artisan --version
.\dev.cmd artisan migrate:status
.\dev.cmd artisan test
.\dev.cmd composer verify
.\dev.cmd mysql
```

For a foreground server, run `stop` and then `.\dev.cmd serve`. Press Ctrl+C to stop that server. Type `exit` to leave the MySQL console.

## Installed foundation

- PHP 8.4, Composer 2, Laravel 13 and MySQL 8.4 LTS.
- Local MySQL database: `easycare_dev`, accessible to a dedicated database-scoped application account.
- Laravel starter migrations: users/password resets/sessions, cache/locks and jobs/failed jobs.
- Staff foundation migrations: departments, staff identity fields, roles, permissions, assignment tables and an initial-permissions baseline marker.
- Versioned API readiness endpoint: `GET /api/v1/health`.
- Staff session endpoints: CSRF bootstrap, login, current staff identity and logout under `/api/v1/auth`.
- Protected department and staff-directory reads, with validation, filtering, sorting, pagination and record-scope policies.
- A protected role/permission catalog and a console command to inspect, grant or revoke known role permissions.
- Staff administration APIs and screens for profiles, department/role assignment and activation, with password reauthentication and version checks.
- Administrator-issued, one-use password reset codes and a trusted server-console recovery command.
- One frontend identity source, server-backed profile displays and session boundaries that clear private caches across account/access changes and tabs.
- Append-only staff/security audit records, a permission-protected API and a read-only `/admin/audit` screen with filters and pagination.
- Owner-scoped private uploads/downloads, file metadata and checksum verification; `/admin/files` provides the staff library.
- A read-only `files:verify` console command checks the private file/database recovery set.
- Patient demographic/category/identifier/contact/family-link storage, with permanent number reservations and opt-in registration, search, duplicate-review and versioned editing APIs.
- Private flags, governed restriction/restore, card/category history and two-person patient merges, with stable original records and canonical lookup.
- Appointment, encounter and clinician-assignment storage, including bookings before registration, category-at-visit snapshots and one current clinician with retained assignment history. Booking, arrival and queue APIs are connected to this storage.
- Authorized booking, search, rescheduling, confirmation, cancellation, no-show and follow-up APIs, with private revision history, Lagos calendar dates and clinician interval conflict checks.
- Verified booking arrivals and walk-in visits, clinician assignment/reassignment, acceptance and completion by the assigned clinician, protected queues and immutable visit history.
- Encounter-scoped clinical storage for versioned templates/notes, vitals, diagnoses, procedures and prescription lines, with retained authorship, protected signed records and linked amendments. Clinical APIs and screens remain Tasks 26-28; see the [storage contract](docs/LOCAL_DEVELOPMENT.md#encounter-scoped-clinical-storage).
- Private `backend/.env` is configured with the generated local database password and application key.

`vendor/` contains installed PHP packages. `composer.lock` records their exact versions. `.env` and the project-local tools/database files are excluded from Git.

## First staff account and sign-in

From `backend`, create an account through private interactive prompts:

```powershell
.\dev.cmd artisan staff:create --role=admin
```

Use your own username and password; no demonstration accounts or default passwords are created. The password is entered privately and is not a command-line argument. Run migrations and `db:seed` first. The chosen role must already exist in the seeded catalog. Omitting `--role` creates an account with no module access.

The initial admin role can read the staff directory and authorization catalog, manage staff, issue password reset codes and review audit history and use their own private file library. Other initial roles can read departments and their own directory entry. Department-wide staff access is opt-in. Patient access requires separate, explicitly granted permissions. Inspect a role with `.\dev.cmd artisan access:role doctor`; see the [authorization contract](docs/LOCAL_DEVELOPMENT.md#server-side-authorization) for grants and revocations. Rerunning the seeder preserves deliberate permission removals.

Start the backend with `dev.cmd start`, then run `npm run dev` in the existing frontend directory and open its printed URL. The frontend uses `/api/v1` through Vite's local proxy to port 8000. Staff without assigned roles see an access-pending page. Authorized staff manage accounts at `/admin/users`. New accounts receive an unknown random initial password; after verifying identity, issue a reset code so the staff member can choose their password at `/forgot-password`. Codes expire after 15 minutes. If the sole administrator needs recovery, run `.\dev.cmd artisan staff:recover their-username` interactively from the trusted server console. See the [staff administration and recovery contract](docs/LOCAL_DEVELOPMENT.md#staff-administration-and-recovery). Hospital approval of the identity-verification procedure remains a release requirement.

## Where code goes

| Path | Purpose |
|---|---|
| `routes/` | Incoming routes |
| `app/Http/Controllers/` | Request handlers |
| `app/Models/` | Database models |
| `database/migrations/` | Database structure changes |
| `tests/` | Automated checks |
| `.env` | Private local configuration |

The helper uses the laptop-only runtimes in this workspace. Other developers should install PHP/Composer/MySQL on their own machines, copy `.env.example` to `.env`, configure their development database, run `composer install`, generate an app key and run migrations. This setup is not the hospital's production hosting configuration.

See [local development notes](docs/LOCAL_DEVELOPMENT.md) for database setup, tests and the API contract.

`composer verify` runs the complete SQLite and MySQL suites sequentially, using guarded test databases. See [reusable feature verification](docs/LOCAL_DEVELOPMENT.md#reusable-feature-verification) for shared test helpers, the API test recipe and browser checks to repeat as each module is added.


## Architecture and database proposal

- [Ordered backend build checklist](docs/BACKEND_BUILD_CHECKLIST.md)
- [Backend architecture PDF](docs/architecture/EasyCare_Architecture_Reviewed_v1.1.pdf)
- [Complete database dictionary](docs/architecture/Database_Dictionary_v1.1.md)
- [Proposed MySQL schema](docs/architecture/schema.mysql.sql)
- [Frontend/database review](docs/architecture/Frontend_Database_Review.md)

The architecture and frontend review are design documents dated 4 November 2026. The Laravel development foundation was installed on 6 November 2026. The full 85-table schema remains a proposal; application migrations implement staff/roles, audit history, private files, command confirmations, patient storage and visit foundations alongside Laravel's runtime tables. See the [patient API contract](docs/LOCAL_DEVELOPMENT.md#patient-registration-search-and-editing-apis) for opt-in permissions, staff-entered numbers, duplicate review and partial edits. Patient screens are connected. Booking APIs are available; arrival/queue APIs and clinical/inventory/financial workflows follow their checklist stages. See the [visit storage contract](docs/LOCAL_DEVELOPMENT.md#appointment-encounter-and-assignment-storage).

Transaction safeguards: staff creation/edits, recovery issuance and uploads now require a per-submission `Idempotency-Key`. Database changes, audit and command completion commit together; exact retries confirm an existing resource and stale versions remain rejected. See [transaction and retry rules](docs/LOCAL_DEVELOPMENT.md#transaction-and-retry-rules) for the header, outcomes, lock order and file-recovery limits.


See the [appointment API contract](docs/LOCAL_DEVELOPMENT.md#appointment-booking-and-update-apis) for opt-in permissions, required interval/offset fields, conflict responses, retained booking history and merge safeguards. Saved frontdesk and clinician screens are connected in Task 24.

See the [arrival and queue API contract](docs/LOCAL_DEVELOPMENT.md#arrival-clinician-assignment-and-queue-apis) for identity verification, opt-in permissions, clinician isolation, expected versions and retry rules. The [saved frontend screens](docs/LOCAL_DEVELOPMENT.md#saved-booking-and-clinician-screens) connect bookings through arrival, assignment and clinician completion.
