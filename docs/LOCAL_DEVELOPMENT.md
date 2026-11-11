# EasyCare local development

This is the laptop development setup, separate from the eventual hospital-server deployment.

```text
easycare/
  backend/       Laravel PHP application and backend source
  easycare2/     Existing React + TypeScript frontend
  docs/          Architecture and development notes
  .tools/        Local PHP, Composer, MySQL, credentials and database files
```

Laravel belongs in `backend/`. `.tools/` contains development programs, not application source. It is excluded from Git. The backend's `.env` contains local configuration and is also excluded from Git. The setup uses project-local runtimes; it does not require adding PHP or Composer to your Windows system PATH.

## Start working

Open a terminal in `C:\Users\HP\Desktop\easycare\backend`:

```powershell
.\dev.cmd start
```

Open **http://localhost:8000** for the backend. The existing React frontend remains a separate application; start it from `easycare2` using `npm run dev` when working on the UI.

`start` launches the local database and backend in the background. They bind to your laptop's loopback address. After restarting Windows, run the same command again.

```powershell
.\dev.cmd status                  # Check installed versions and running processes
.\dev.cmd stop                    # Stop this local backend and MySQL instance
.\dev.cmd serve                   # Run Laravel in the foreground; Ctrl+C stops it
.\dev.cmd php -v                  # Check PHP
.\dev.cmd composer --version      # Check Composer
.\dev.cmd artisan --version       # Check Laravel
.\dev.cmd artisan migrate:status  # See which database migrations have run
.\dev.cmd artisan test            # Run the fast SQLite/in-memory suite
.\dev.cmd mysql                   # Open the local application's MySQL console
```

Use `stop` before switching from background `start` to foreground `serve`, because both use port 8000. Run `exit` to leave the MySQL console.

## What the tools do

| Tool | Purpose |
|---|---|
| PHP | Executes the backend code |
| Composer | Installs PHP packages from `composer.json` and `composer.lock` |
| Laravel | Organizes routes, controllers, models, validation and application services |
| MySQL | Stores shared application data |
| Artisan | Laravel's command-line tool, invoked through `dev.cmd artisan` |
| Migration | A version-controlled PHP file that creates or changes database tables |

The local database is named `easycare_dev`, with an application account restricted to that database. The generated password is already set in `backend/.env`; the helper reads the private local configuration when opening MySQL.

## Where you will write code

- `backend/routes/`: incoming web/API routes.
- `backend/app/Http/Controllers/`: HTTP request handlers.
- `backend/app/Models/`: database-backed models.
- `backend/database/migrations/`: database structure changes.
- `backend/tests/`: automated checks.
- `backend/.env`: private laptop settings, including the database connection.

The application migrations add staff/roles, audit history, private files, command confirmations and the patient storage foundation to Laravel's runtime tables. Staff login uses the existing session table. Patient APIs, screen integration and clinical workflows are later stages. The complete architecture SQL remains a proposal; it is not imported into the development database.

## Database and API foundation

Apply the application migrations and seed the reference catalogs:

```powershell
.\dev.cmd artisan migrate
.\dev.cmd artisan db:seed
```

The schema adds `departments`, `roles`, `permissions`, `user_roles` and `role_permissions`, plus staff identity fields on `users`. A department has many staff; staff have many roles through `user_roles`; roles have many permissions through `role_permissions`. Foreign keys prevent orphan relationships, unique keys prevent duplicate codes/assignments and MySQL checks require a positive staff version. Deleting a referenced department is restricted; deleting an authorization catalog record removes its pivot links, without deleting staff or the other catalog records.

The repeatable seeder adds seven initial department/role entries matching the current frontend modules and the thirteen known permissions defined in `config/authorization.php`. It applies each initial grant set once, tracked by `permission_baselines`. These are editable starting values; final hospital patient access rules still require agreement. Seeding preserves existing catalog names and subsequent grant/revocation decisions. It creates no staff accounts or staff-role assignments. Factories are for synthetic tests only. The initial counts are seven departments, seven roles, thirteen permissions, twenty-one role-permission links and four baseline markers. Seven starting patient categories are also seeded, without patient records or automatic patient-access grants.

The additive migration preserves existing staff IDs, passwords, email addresses and the original `name` column. Existing users receive `legacy_<id>` usernames and provisional split name fields; review these when staff account management is implemented. The application maintains `name` for compatibility when structured names change. Email is optional; its existing 255-character capacity is retained. The proposal's smaller email length and microsecond timestamps on existing starter columns are not retroactively imposed. Staff profiles/contacts and the other hospital tables will arrive with the corresponding features.

Rollback removes the foundation catalogs and added fields while retaining the starter user data. Because the starter schema requires email, rollback refuses before changing anything if any staff email is null. Assign valid addresses first when deliberately reverting to that schema. MySQL DDL is not fully transactional: a failed migration must be inspected before retrying; do not use `migrate:fresh` against the development or hospital database.

## Isolated test configuration

The default `artisan test` suite uses SQLite in memory for fast checks. MySQL is the authoritative database for dialect, constraint and migration behavior.

Create a private `.env.testing` from `.env.testing.example`, generate its own application key, and set its test password. Use a dedicated local MySQL database/account; the account must have privileges only on `easycare_test`:

```sql
CREATE DATABASE easycare_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'easycare_test'@'localhost' IDENTIFIED BY 'replace-with-your-private-test-password';
GRANT ALL PRIVILEGES ON easycare_test.* TO 'easycare_test'@'localhost';
```

The local workspace already has this isolated database/account configured. Credentials remain in the ignored `.env.testing`. On another machine, an administrator runs the SQL above and chooses the password; do not use the example text as a password.

```powershell
.\dev.cmd artisan key:generate --env=testing
.\dev.cmd artisan test --compact
.\dev.cmd php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml --no-progress
```

Use the direct PHPUnit command for the alternate configuration; the installed Artisan test wrapper can supply a duplicate configuration option. MySQL tests reset the disposable test schema. Before migration/refresh traits run, the test base rejects cached configuration, non-testing environments, SQLite files, database URLs, remote MySQL hosts and any MySQL database/account other than `easycare_test`. The MySQL account's restricted grants add a second boundary. Do not enable parallel testing against this fixed database.

`.env.production.example` is a configuration template for the later hospital installation, with a separate database/account, HTTPS cookies and debug disabled. It does not provision or deploy a production server. Actual environment files, keys and database contents stay private.

On 10 November 2026, the full 85-table proposal and its 201 foreign keys were imported into a randomly named disposable MySQL 8.4.13 schema. The existing architecture suite passed 38 checks, including restrictive relationships, uniqueness, financial/clinical constraints and bed/stock row locking. Its test adapter was corrected for MySQL savepoint syntax and explicit transaction starts; synthetic string fixtures now respect declared column lengths. The schema itself did not need a DDL change. The disposable schema was removed afterward. The workspace runner and detailed results are in `../docs/architecture/verify_mysql.py` and `../docs/architecture/mysql-validation.json`; the installed Laravel foundation has its own reproducible PHPUnit suite described above. This verifies structural rules, not the unimplemented clinical services or complete concurrent hospital workflows.

## API contract: version 1

Base path: `/api/v1`. Send `Accept: application/json`; send JSON request bodies with `Content-Type: application/json` for write endpoints. JSON keys use `snake_case`. Successful resources are wrapped in `data`; paginated collections additionally include Laravel's `links` and `meta` objects. Return an empty collection as `200`, with `data: []`.

Implemented routes:

| Method and path | Access | Response |
|---|---|---|
| `GET /api/v1/health` | Public | `data.application`, `data.api_version`, `data.status`; `503` if MySQL cannot be queried. No database connection details are returned. |
| `GET /api/v1/auth/csrf-token` | Public, session-backed | `data.csrf_token`; starts an anonymous browser session. |
| `POST /api/v1/auth/login` | CSRF protected | Accepts `email_or_username` and `password`; returns `data.user`, `data.csrf_token`, `data.expires_at`. |
| `GET /api/v1/auth/me` | Active signed-in staff | Current identity, CSRF token and expiry; `401` for invalid sessions. |
| `POST /api/v1/auth/logout` | Active signed-in staff, CSRF protected | `204`, invalidates the current server session. |
| `GET /api/v1/departments` | Active signed-in staff with `reference-data.view` | Paginated department resources: `id`, `code`, `name`, `created_at`, `updated_at`. |
| `GET /api/v1/departments/{department}` | Same department-read permission | One department resource. |
| `GET /api/v1/staff` | `staff.view_any`, or `staff.view_department` with an assigned department | Paginated directory entries within the viewer's permitted scope. |
| `GET /api/v1/staff/{user}` | Scope policy for that staff record | One directory entry; inaccessible and missing staff IDs both return `404`. |
| `GET /api/v1/access-control` | `authorization.view` | `data.permissions` (code/name) and `data.roles` (code/name/permission codes), restricted to known permission codes. |
| `GET /api/v1/patients`, `GET /api/v1/patients/{patient}` | `patients.view`, active records only | Paginated patient summaries or a detailed demographic/contact resource. |
| `GET /api/v1/patients/options` | `patients.view` | Current category IDs/codes/names. |
| `POST /api/v1/patients/duplicate-check` | `patients.view`, CSRF protected | Up to twenty visible candidates and `meta.has_more`. No mutation. |
| `POST /api/v1/patients` | `patients.view` and `patients.create`, CSRF protected | Transactional registration, `201`; requires `Idempotency-Key`. |
| `PATCH /api/v1/patients/{patient}` | `patients.view` and `patients.update`, active records only, CSRF protected | Versioned demographic/contact edit, `200`; requires `Idempotency-Key`. |

The existing `/` response and framework `/up` route remain available. `/up` checks application liveness; the versioned health route also checks database readiness.

Department query parameters:

| Parameter | Rules | Default |
|---|---|---|
| `q` | Optional text, at most 100 characters; literal substring in code or name | No filter |
| `sort` | `code`, `-code`, `name`, `-name`; minus means descending | `code` |
| `page` | Integer, at least 1 | `1` |
| `per_page` | Integer from 1 to 100 | `25` |

Lists use an ID tie-breaker for stable ordering. SQL wildcard characters in `q` are searched literally. Unknown query fields are ignored and are not copied into pagination links. No department write endpoint is exposed at this stage.

The staff directory uses the same query bounds and pagination format. Its `q` searches name or username; allowed sorts are `name`, `-name`, `username` and `-username`, defaulting to `name`. Each entry contains only `id`, `name`, `username`, `staff_code`, `is_active` and department code/name/ID. It excludes email, credentials, roles and permission grants. Scope is applied before searching and pagination, so counts and search results cannot include inaccessible staff.

Errors always return JSON under `/api`, including requests without an Accept header. The shape is:

```json
{
  "message": "The submitted data is invalid.",
  "code": "validation_failed",
  "errors": { "per_page": ["The per page field must not be greater than 100."] }
}
```

`errors` is present for validation failures. Other errors contain `message` and `code`. API exceptions do not return stack traces, SQL text or exception messages, even when local debug is enabled. Validation messages remain readable so clients can display field errors.

| HTTP status | Code | Client behavior |
|---|---|---|
| `400` | `bad_request` | Correct the request |
| `401` | `unauthenticated` | Require sign-in |
| `403` | `forbidden` | Show access denied |
| `404` | `not_found` | Show missing resource |
| `405` | `method_not_allowed` | Use a supported method; `Allow` is preserved |
| `409` | `conflict` | Reload current state before resolving the conflict |
| `415` | `unsupported_media_type` | Correct the content type |
| `419` | `session_expired` | Renew the session and CSRF state |
| `422` | `validation_failed` | Display field errors |
| `429` | `too_many_requests` | Wait according to `Retry-After` |
| `500` | `server_error` | Show a generic error; inspect private server logs |
| `503` | `service_unavailable` | Retry later; investigate server/database readiness |

The API limiter permits 120 requests per minute per client IP. Revisit the limit with realistic shared-network usage before hospital deployment. Times returned by current resources are ISO 8601 UTC strings ending in `Z`. Future date-only clinical values must remain `YYYY-MM-DD`; future money and precise quantities should use decimal strings, with currency/units specified by each resource contract.

Foundation verification on 10 November 2026: all 51 PHPUnit tests passed on MySQL (138 assertions). The fast SQLite suite passed 50 tests (137 assertions), with the MySQL-only version-constraint test skipped. Coverage includes clean migrations, preservation/rollback of existing starter users, guarded rollback for missing email, relationship integrity, repeatable seeding, access denial, validation, pagination, CORS, sanitized failures and test-database isolation. The live health endpoint returned HTTP 200 with the configured frontend CORS origin. Task 10 subsequently extends the permission catalog and grants as described below.

## Server-side authorization

Laravel gates check explicit permission codes; model policies enforce record scope. Every current resource endpoint requires a valid active staff session and a gate/policy. The current-user and logout routes require the staff session itself. Frontend role guards do not authorize API requests. An admin role has no unconditional bypass: removing a permission removes its access. Unknown permission codes and wildcards grant nothing. Multiple assigned roles combine their known permissions without duplicates.

The initial permission presets are:

| Permission | Initial admin | Other six initial roles | Meaning |
|---|---|---|---|
| `reference-data.view` | Yes | Yes | Read department reference data |
| `staff.view_self` | Yes | Yes | Read one's own directory entry |
| `staff.view_department` | No | No | Opt-in directory access to staff in one's assigned department |
| `staff.view_any` | Yes | No | Read the complete staff directory |
| `authorization.view` | Yes | No | Read roles and their known grants |
| `staff.manage` | Yes | No | Read administration records and create/update staff accounts |
| `staff.reset_password` | Yes | No | Issue reset codes; also requires `staff.manage` |

An account without a role or grant can still inspect its own signed-in identity and log out, but has no resource access. Own-entry access alone does not allow enumeration through the staff list. Department access never treats an unassigned department as a group: a staff member without a department cannot use department-wide access. An explicit self grant and department grant form a union; a full-directory grant covers all staff. Caller-supplied department IDs cannot widen the scope. Unauthorized staff IDs return the same generic `404` as missing IDs.

Effective permissions are queried from the database on each request. Revoking a permission or staff-role assignment, or changing the viewer's department, affects subsequent requests in an existing session. Current-user responses also return fresh, sorted permission codes. Authenticated resource responses and failures use `Cache-Control: no-store, private`. Staff creation/update policies require `staff.manage`; department write policies and all staff deletion policies deny access.

Run these commands from the server console, using exact catalog codes:

```powershell
.\dev.cmd artisan access:role doctor
.\dev.cmd artisan access:role doctor staff.view_department
.\dev.cmd artisan access:role doctor staff.view_department --revoke
```

The first command only lists grants. The second grants department-directory access to everyone assigned the doctor role; the third removes it. Grant/revoke commands are idempotent and reject unknown roles, unknown codes and an unseeded permission catalog. Console access is an administrative boundary; the API exposes no grant or role-membership mutation endpoint yet. `staff:create --role=...` assigns an existing role without changing its grants.

For an existing development installation, run `artisan migrate` followed by `artisan db:seed`. Task 10 added `permission_baselines`; task 11 adds `staff_password_resets`. The seeder creates the catalog and applies each baseline in one transaction only when its marker is absent. The `staff-administration-v1` baseline explicitly grants the admin role the two new management permissions once. It preserves older read-permission revocations. Subsequent seed runs preserve removals and custom grants. Future grants need a deliberately defined new baseline or an explicit console grant. Retain baseline markers with database backups. Rolling back the marker-table migration removes its markers but leaves seeded grants; recreating and reseeding afterward would apply both initial baselines again. Do not reset markers to refresh catalogs.

These rules cover the implemented staff/reference APIs. Patient, encounter, prescription, stock and financial access rules are not implemented yet. Each new resource needs an explicit permission, model policy, database query scope and denial tests with its workflow. Department membership alone must never be assumed to grant access to clinical records. Hospital-specific access, emergency access, approvals and exports remain subject to task 2's decisions.

Task 10 verification on 11 November 2026: all 91 backend tests passed on MySQL (456 assertions); SQLite passed 90 tests (455 assertions), with one MySQL-only check skipped. Coverage includes all six ordinary role presets, explicit grants, admin revocation, null departments, inaccessible IDs, scoped search/pagination, unknown permissions, duplicate grants, failed initialization rollback and seed preservation of revocations. Database-backed session tests verify permission revocation and department changes without signing in again. A route audit checks authentication and authorization middleware on every current resource route. The additive migration and baseline are applied to `easycare_dev`, with no staff accounts or role assignments created; the live health endpoint returns HTTP 200.

## Frontend connection and authentication boundary

The frontend API client and tracked development configuration use `/api/v1`. Vite proxies this path to `http://127.0.0.1:8000`, so cookies stay on the frontend's hostname. The previous cloud API URL has been removed. Production hosting must route this same prefix to Laravel. `FRONTEND_URLS` remains an explicit origin allowlist for deliberately separate origins; development defaults are `http://localhost:5173` and `http://127.0.0.1:5173`. CORS supports credentialed requests, CSRF headers and preflight. CORS is browser configuration, not authorization.

For example, the frontend can check the public endpoint with:

```javascript
const response = await fetch('/api/v1/health', {
  headers: { Accept: 'application/json' },
});
const result = await response.json();
```

Session routes use Laravel's `web` middleware and the database session driver. The browser sends cookies with `credentials: include`. Before login or logout it retrieves the CSRF token and sends `X-CSRF-TOKEN`; Laravel's request-forgery middleware also accepts valid same-origin browser requests. Login rotates both session ID and CSRF token and destroys the old session. Identity cookies are HTTP-only, SameSite=Lax by default, and secure under the production template's HTTPS configuration. No bearer token is returned or required.

Unknown accounts, wrong passwords and inactive accounts receive the same `422` validation error. Laravel verifies the password hash. Login permits five failed attempts per normalized identifier/IP pair per minute, plus twenty login requests per IP per minute; `429` preserves `Retry-After`. Identity-specific limiter keys are hashed. Successful login clears its failure counter and records `last_login_at`. Login while already signed in returns `409`; logout is required before changing accounts. Extra input fields cannot assign roles or activate accounts.

`data.user` includes staff ID, username, names, email, active status, department, assigned roles, effective permission codes, `created_at` and nullable `last_login_at`. Passwords and remember tokens are omitted. `data.expires_at` is the current estimated session expiry in UTC. Protected routes check the active account and session password fingerprint. Disabling an account or changing its password prevents subsequent authenticated access. Logout invalidates the current session and regenerates CSRF state. Auth responses and API errors use `Cache-Control: no-store, private`.

The frontend uses one in-memory identity store shared by the global auth context and clinician components. It restores identity from `/auth/me` after reload and blocks private routes while identity is unknown or the server is unreachable. Module guards use server-returned roles; administration additionally requires `staff.manage`. UI checks do not replace backend policies. All active profile views use the server record. Profile changes go through staff administration; the old browser-only name, role and password editors are removed. No profile/password or patient record is written to browser storage. Obsolete authentication entry points use the same sign-in component; unsupported public signup/OTP hooks reject locally without requesting nonexistent APIs.

An access scope includes staff ID, department ID, active status, role codes and permission codes. A change resets the private React tree, including forms, contextual patient state and notifications. Registered cleanups clear module-level medical tables, pending entries, general-consultation state, patient flags and pharmacy callbacks. The boundary also revokes session-owned object URLs and removes only known legacy private keys (`easycare-auth`, `easyCareSession`, `mockUser`, `easyCareDoctorProfile`, `mockPatients`, `patients`) from local/session storage; unrelated preferences remain. Disabled browser storage does not prevent authentication. Metadata-only refreshes and reordered roles/permissions do not reset the scope or open forms. Clinical screens remain demonstrations and do not persist hospital records.

The API client captures the access revision before CSRF bootstrap, checks it before sending and before returning a response, and aborts pending requests on a boundary. A late success or `401` from the previous scope cannot populate data or sign out the new staff member. A write waiting for an old CSRF bootstrap is not sent under a new account. Aborting a browser request cannot undo a server operation that has already committed; writes are never automatically replayed after an account change. Only login/logout may refresh CSRF and retry once after `419`.

Sign-in, confirmed sign-out and completed recovery notify other tabs using a random event nonce through BroadcastChannel and a storage-event fallback. Notifications contain no identity or credentials and never authorize access: receivers clear private state and query `/auth/me`. Focus/visibility checks refresh the current session, and a restored browser-cache page blocks and verifies before reuse. Server-side access revocations are detected at the next API call or session check. Local expiry hides private content while the server check is pending. Idle pages do not send an endless heartbeat that keeps sessions alive. Logout waits for a server response; a failed logout can be retried. Browser history state from an earlier access scope is discarded before private routes render, including on back/forward navigation.

When adding another cache or request path, use the shared `apiFetch` client and register any module-level private cache with `registerSessionCleanup` in `src/lib/sessionScope.ts`. Create preview/download object URLs through `createSessionObjectUrl`. React component state belongs below `SessionBoundary`. Future patient APIs still need patient/encounter scopes, authorization policies and route-ID loading with their workflows; session isolation does not implement those services.

Run the frontend regression suite from `easycare2` with `npm test`. It uses Node's test runner and the already-installed TypeScript compiler to load production modules; no new dependency is installed. Verified runtime: Node 24.19.0. Eleven tests cover refresh preservation, permission changes, selective storage removal, storage-disabled operation, late responses, stale `401`, CSRF waits, connection uncertainty, object-URL revocation and clinical-cache separation. Type checking remains a separate check.

Task 12 verification on 11 November 2026: eleven frontend tests and twenty-five browser checks passed, with no browser page errors. The browser checks include multi-tab sign-out/account switching, server-backed profiles, preservation of same-account forms, history-state sanitization, restored-page verification, server role revocation and a storage-disabled expiry check that holds the server response while confirming private content is hidden. All 113 backend tests passed on MySQL (651 assertions); SQLite passed 112 (650 assertions), with one MySQL-only check skipped. The frontend builds. The complete type check reports 125 errors outside the changed files, so it remains an outstanding release check. One pre-existing unused declaration in a touched upload component was removed.

No public registration or OTP activation endpoint exists. Authorized administrators create accounts and issue recovery codes through `/admin/users`; staff redeem codes at `/forgot-password`. Patient and clinical screens still contain demonstration data; signing in does not make them operational or production-ready. Hospital patient-access decisions, complete audit history and clinical cache isolation remain on the checklist.

Create the first staff account from the server console:

```powershell
.\dev.cmd artisan db:seed
.\dev.cmd artisan staff:create --role=admin
```

The command privately prompts for username, names, optional email and a confirmed password. Usernames use lowercase letters, digits, dots, underscores or hyphens. Passwords require at least 12 characters, mixed case and a number, within bcrypt's 72-byte limit. Duplicate identifiers and unknown roles are rejected; creation and role assignment use a transaction. The role flag is optional and never defaults to administrator. Selecting a role does not add permission grants. Non-interactive execution is refused, keeping passwords out of command history and arguments. No staff account is automatically provisioned.

Verification on 11 November 2026: all 67 backend tests passed on MySQL (244 assertions); SQLite passed 66 tests (243 assertions), with one MySQL-only check skipped. Session tests use database-backed cookies and enable real CSRF verification instead of its usual test bypass. Eight browser checks passed: forged local identity, invalid credentials, login, refresh, role denial, connection failure/retry, logout and unassigned-role handling. No JavaScript page errors occurred. The frontend builds; its full TypeScript check still reports 126 errors outside the changed authentication files, so it is not a clean release check yet.

## Staff administration and recovery

The frontend screen is `/admin/users`. Its route and navigation require server-returned `staff.manage`, including when that permission is explicitly delegated to another role. All administration reads require this permission; writes also recheck the actor's current account and permissions inside the database transaction. Staff creation returns `201`; updates return `200`. No delete endpoint exists.

| Method and path, under `/api/v1` | Permission | Purpose |
|---|---|---|
| `GET /staff-management` | `staff.manage` | Search/paginate full staff administration records |
| `GET /staff-management/options` | `staff.manage` | Departments and roles, with each role's `assignable` flag |
| `GET /staff-management/{user}` | `staff.manage` | Current profile, assigned roles, effective permission codes and version |
| `POST /staff-management` | `staff.manage` | Create a staff account and assignments atomically |
| `PATCH /staff-management/{user}` | `staff.manage` | Update a profile and replace department/role assignments atomically |
| `POST /staff-management/{user}/password-reset` | `staff.manage` and `staff.reset_password` | Issue a one-use code after identity verification |
| `POST /auth/password/reset` | Anonymous, CSRF protected and throttled | Redeem a code and choose a password |

The administration list uses the directory's validated query parameters. Administration records include structured names, email, staff code, department, roles, active status, effective permissions, last login, creation time and `version`. They exclude password hashes, remember tokens and reset codes. Administration access permits these full profile reads; ordinary directory access still returns only the minimal directory resource.

Account creation accepts `username`, `first_name`, `last_name`, optional `middle_name`, nullable `email`, nullable `staff_code`, nullable `department_id`, `role_codes` (an array, which may be empty), `is_active` and the acting administrator's `current_password`. The nullable email, staff code and department fields must be present. Names are limited to 64 characters, username to 3-64 lowercase letters/digits/dots/underscores/hyphens, email to 255, and staff code to 64. Username, email and staff code are unique; department and role references must exist. Inputs cannot supply permissions or an initial password. The backend generates an unknown random initial password; issue a reset code separately after verifying the new staff member's identity.

Updates accept the same profile/assignment fields plus the latest `version`, without a username or password field. Usernames remain stable. Conflicting versions return `409` without partial writes; cancel and reload before editing the current record. Database uniqueness conflicts return validation errors. Reenter the administrator's current password for every save or code issuance; it is never stored in browser storage. Management mutations allow twenty requests per administrator/IP per minute.

A manager cannot assign a role containing permissions they do not hold, or modify/reset an account whose assigned permissions exceed their own. This also applies to inactive targets. Administrators cannot change their own roles, department or active status, or issue their own code through the API. Another administrator must perform those access changes. Staff mutations acquire a shared database row lock and recheck the actor after waiting, preventing two active administrators from concurrently deactivating each other and leaving no active manager. Transactions roll back profile and assignment changes together. Access changes remove the target's database sessions; subsequent requests must sign in again. Every profile update invalidates pending reset codes; a changed email also clears its verification timestamp. Reactivation allows a fresh sign-in and does not restore old sessions.

To issue a code, submit the target's latest `version`, the administrator's `current_password` and `identity_verified: true`. Only active targets are eligible. The response returns the updated `data.user`, `data.reset_code` and UTC `data.expires_at`, with `201` and `Cache-Control: no-store, private`. The code is 48 hexadecimal characters from a cryptographically secure random source, expires after 15 minutes, and replaces any previous code. Its SHA-256 hash, issuer ID and timestamps are stored in `staff_password_resets`, one row per staff member; the plaintext is returned only at issuance. Issuance increments the staff version but does not itself change the password. Closing the frontend panel removes its in-memory code display. Codes do not appear in URLs, local storage or later profile responses.

The verified staff member opens `/forgot-password` and submits `username`, `token`, `password` and `password_confirmation`. Passwords require at least 12 characters, uppercase/lowercase letters and a number, within bcrypt's 72-byte limit. Unknown, disabled, expired and wrong-code cases return the same validation error. Attempts are limited to five per username/IP and ten per IP per minute, with `Retry-After` on `429`. A successful reset consumes the code inside the transaction, changes the hash, rotates the remember token, increments the version and removes existing database sessions and legacy email-reset tokens. It returns `204`; staff must sign in with the new password. It does not activate disabled accounts. Password validation failures do not consume the code.

If the sole administrator has forgotten their password, an authorized operator with access to the trusted hospital server console can run:

```powershell
.\dev.cmd artisan staff:recover their-username
```

The command requires an interactive identity-verification confirmation and an active existing account. It displays a one-use code for private handoff, with the same expiry/redeem behavior; a null issuer identifies console issuance. It neither sets a shared password nor activates an account. Non-interactive execution and cancelled confirmation issue no code. Console access is a privileged recovery boundary and must be restricted by the hospital's operating procedure.

Run migrations and `db:seed` before using these endpoints. No email provider, internet connection or new package is required for recovery; clients still need a connection to the hospital server. The hospital must approve the identity-verification and console-operator procedure before production use. These workflows are implemented for local review; staff acceptance and production hosting are later checklist tasks. The separate audit trail described below preserves issuance and redemption events after the current reset code is replaced or consumed.

Verification on 11 November 2026: all 113 backend tests passed on MySQL (647 assertions); SQLite passed 112 tests (646 assertions), with one MySQL-only check skipped. Tests cover permission denial, profile/relationship validation, stale edits, rollback, self-access protection, privilege escalation including inactive accounts, repeated seeds, code hashing/expiry/replacement/reuse, password byte limits, throttling, console recovery and real CSRF-protected sessions. Nine browser checks passed: admin entry, creation with assignments, editing after refresh, verified code issuance/clearing, mobile recovery and code reuse denial, recovered-staff access denial, stale edits after a password change, deactivation/session invalidation and reactivation. No page errors occurred; desktop/mobile views were inspected. The frontend builds. Its full TypeScript check still reports the same 126 errors outside the changed files. At that task 11 verification, the development database had seven permissions, eighteen grants, two baseline markers and no staff accounts or pending resets; task 13 adds the independent audit permission baseline described below.

## Audit history

MySQL installation prerequisite: the migration creates two protection triggers and needs schema-level `TRIGGER` privileges. When binary logging is enabled, this migration also requires `log_bin_trust_function_creators=1` during installation. It checks this before creating the table and fails with a setup instruction if the setting is missing. The project-local development server now sets `log-bin-trust-function-creators=ON` in its private MySQL configuration; no global privileges were added to application accounts. On a hospital server, the database administrator must review and enable the setting for the migration window and can restore the previous value after the triggers are installed. Do not give the web application's database user `SUPER`. See [MySQL's stored-program binary logging rules](https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html). For a database-administrator session, the setting is:

```sql
SET GLOBAL log_bin_trust_function_creators = 1;
```

Task 13 adds local, append-only audit history for the implemented staff workflows. Run migrations and `db:seed` before using the updated application. The independent `audit-history-v1` grant baseline adds `audit.view` to the initial admin role once; later seeding preserves revocations. `staff.manage` does not imply `audit.view`, and the admin role has no bypass. A reviewer can hold only `audit.view` and open `/admin/audit` without staff-administration access. The Modules screen and administration navigation show the permitted links.

`GET /api/v1/audit-events` requires an active session and `audit.view`. It returns the normal resource collection with `data`, `links`, `meta`, plus a top-level `through_id`. Records are ordered by UTC `occurred_at` and then ID, newest first. Pagination defaults to the API page size and accepts at most 100 records. Filters are exact `action`, `outcome` (`succeeded`, `denied`, `failed`), `channel` (`http`, `console`), `actor_id`, `subject_type` (`staff`, `role`, `audit`), `subject_id`, and inclusive UTC `from`/`to` dates in `YYYY-MM-DD` format. A subject ID requires its subject type. `to` cannot precede `from`. Carry the returned `through_id` into subsequent page requests to keep the same upper ID boundary while new activity is recorded. Refresh omits that boundary and returns current history. Permission denial, validation and private/no-store responses follow the existing API contract. There are no audit edit/delete endpoints or export functionality.

Events contain the action, outcome, source, UTC time, server-generated request UUID, actor ID, logical record type/ID and a strictly allowlisted `safe_summary`. Rejected HTTP requests also contain their final status code. Staff names, usernames, emails, request bodies/headers, IP addresses, user agents, passwords/hashes, CSRF values, session IDs and recovery codes/hashes are not copied into audit records. Profile summaries identify which fields changed without storing their personal values; access summaries retain previous/current role codes, department IDs, active flags, version and session-revocation outcome. Role-grant summaries contain the known permission code and whether the command changed an existing grant. A no-op command is still accountable.

| Action | When recorded |
| --- | --- |
| `staff.created` | HTTP or console account creation; rejected HTTP/console creation requests |
| `staff.updated` | Profile/access changes and rejected HTTP update attempts |
| `staff.recovery_issued` | Admin or trusted-console issuance; rejected requests and console cancellation |
| `staff.password_reset` | Successful redemption and rejected HTTP attempts |
| `role.permission_granted`, `role.permission_revoked` | Console grants/revocations, including no-ops and rejected codes |
| `auth.login`, `auth.logout` | Sign-in/out and rejected requests, including CSRF and throttling failures |
| `audit.viewed` | Successful history reads and rejected read attempts |

Successful staff mutations, recovery and console permission changes insert their audit event in the same database transaction. If the audit insert fails, the mutation rolls back, including password/reset-code/session-table changes. Console permission commands share the staff-management row lock. Login records its last-login timestamp and audit event together; an audit failure invalidates the attempted session. Logout ends the session before recording its event, so audit-storage failure cannot keep staff signed in. Rejected HTTP attempts are recorded after their mutation transaction has rolled back. If the database is unavailable, the request fails and no claim is made that an audit event could be persisted. HTTP auditing covers the listed routes; ordinary reference-data reads, session refreshes, seeding and direct database maintenance are not recorded as staff actions.

Actor/subject IDs are historical logical references, deliberately without cascading foreign keys. This differs from the proposal's actor foreign key and preserves the original reference if an account is removed during maintenance. A null actor with `channel: console` identifies the trusted server-console boundary, not an authenticated staff identity; the hospital must separately control and account for operating-system access. Password redemption uses a null actor and the target staff ID because possession of a recovery code does not establish a signed-in actor. Unknown/failed sign-ins do not look up or store the submitted identity.

MySQL and SQLite triggers reject row updates and deletes. The application exposes only authorized reads and trusted inserts. This is application/database protection, not cryptographic proof against a database administrator: privileged schema operations can bypass triggers. Retention, backup/restore, operating-system operator identity and hospital approval remain deployment decisions. The current audit scope contains no patient/clinical workflows; those stages must add their own approved event summaries and permission tests.

Verification on 11 November 2026: all 140 backend tests passed on MySQL (840 assertions); SQLite passed 139 tests (839 assertions), with one MySQL-only check skipped. The 27 new audit cases cover privacy, permission changes, pagination, console attribution, immutable rows, and rollback of staff/recovery/permission changes when audit storage fails. Twelve frontend regression tests and thirty-nine real browser checks passed, with no page errors. The production build passed; 125 pre-existing TypeScript errors remain outside changed files. Synthetic browser accounts were removed after desktop/mobile inspection. At task 13 verification, development had eight permissions, nineteen grants, three baseline markers and no staff accounts, pending resets or audit records; task 14 adds its separate file permissions baseline.

Repeat the browser acceptance checks with synthetic staff in the isolated test installation: create/edit a staff account and issue/redeem a recovery code, then verify the actor, target, outcome and safe details in `/admin/audit`; filter by action and UTC dates; page through history while another session creates activity; Refresh to include it. Test empty results and an interrupted connection/retry on desktop and mobile. An ordinary staff account must receive route/API denials. A custom role with only `audit.view` must land on the audit screen and remain unable to manage staff. Revoke that permission while the screen is open: the next request must clear its rows, and reload must not restore them. Sign-out/account changes must remove private audit content.

## Protected files

Task 14 provides the shared private-file foundation at `/admin/files`. The current scope is files owned by the signed-in staff member. No cross-staff file permission or patient/encounter attachment is exposed yet. Patient workflows must later attach records through their own approved patient policies; submitting `patient_id` or `encounter_id` now is rejected. The existing patient document demonstrations are not connected to this staff-owned library.

Run migrations and `db:seed` before using it. A separate `protected-files-v1` baseline adds `files.view_own` and `files.upload` to the initial admin role once. It preserves later revocations and does not grant other roles file access automatically. Grant the exact permissions through the trusted `access:role` command when approved. Upload requires both permissions; listing, metadata and downloads require `files.view_own`. File-only custom roles can enter their library without staff-management or audit permissions. Even administrators cannot read another staff member's file through these endpoints. Inaccessible and nonexistent IDs return the same `404` after the permission check.

| Endpoint | Result |
| --- | --- |
| `GET /api/v1/files` | Paginated list of the current staff member's files, newest ID first |
| `POST /api/v1/files` | CSRF-protected multipart upload in the `file` field; returns `201` only after persistence and integrity verification |
| `GET /api/v1/files/{file}` | Owner-checked metadata |
| `GET /api/v1/files/{file}/download` | Owner-checked, integrity-checked attachment download |

Files must be non-empty PDF, JPEG or PNG and at most 10 MiB (10,240 KiB). Content MIME is detected from actual bytes; the extension must match, and image headers must be readable. The browser's MIME/size hints are not trusted. Names are reduced to a safe bounded basename and canonical extension; they never select a storage location. The server chooses the owner, random storage key, MIME, size, SHA-256 digest, scan status and timestamps. Client-supplied versions of those metadata fields are rejected. Duplicate names and duplicate content are allowed, with distinct records/keys. There is no overwrite, rename or delete endpoint. Request-key confirmation is described under transaction and retry rules below.

`files` stores the private storage key, display filename, detected MIME, byte size, checksum, scan status, owner and UTC timestamps. Its owner foreign key restricts hard deletion of referenced staff; it does not cascade away files. API metadata excludes storage keys, absolute paths and checksums. It includes `id`, `original_name`, `mime_type`, `byte_size`, `scan_status`, `uploaded_by` and UTC `created_at`. Lists accept positive `page` and `per_page` (maximum 100). Upload attempts are limited to ten per staff/IP per minute, in addition to the existing API limit. Validation returns `422`; requests above PHP/web-server limits can return `413` before file validation. Configure PHP `upload_max_filesize` and `post_max_size`, plus the production web server, to allow a 10 MiB file with multipart overhead. This local PHP installation allows 20 MiB requests/files.

Bytes use a dedicated local `protected` disk, defaulting to `storage/app/protected`. `PROTECTED_FILES_ROOT` may select another private local directory; omit it to keep the default. Tests default to `storage/app/testing/protected` and feature tests fake the disk. The disk is not served by Laravel's signed-storage routes, is not linked by `storage:link`, and must stay outside the public web root. Runtime checks reject a public root, served disk, invalid storage key, and a resolved download path outside the protected directory. Deployments must not add a public alias/symlink to this directory. Unix permissions request private files/directories; on Windows, configure the directory's NTFS access permissions for the service and authorized backup operators.

An upload writes bytes, verifies their stored size/digest, and saves metadata plus its audit event in one database transaction. Failure rolls back the metadata and attempts to remove the new blob; unsafe storage/database exception details are replaced before reporting. A process/power interruption or failed cleanup can leave an unreferenced private blob. Such blobs have no downloadable API record. The verification command detects inconsistencies and makes no destructive cleanup decisions. Filesystem and MySQL writes are not a single crash-atomic resource. The transaction/retry rules below define safe confirmation and cleanup; operational backups and a demonstrated restore remain task 54.

Every download rechecks ownership and current permissions, opens the private stream, verifies size and SHA-256, and records authorization before returning bytes. Missing/corrupt bytes fail with a generic `503`; pending/blocked scan states return `409`. Downloads use attachment disposition, `application/octet-stream`, `nosniff`, sandbox CSP and private/no-store caching. No public URLs or reusable signed download links are returned. The frontend uses the shared authenticated client for multipart and binary responses; it rejects HTML masquerading as a download and late file bodies from an earlier staff session. Temporary download object URLs are revoked after use or on session changes.

No antivirus engine is installed. New files are explicitly `not_scanned`; the UI shows this and current policy permits their owners to download them as attachments. Format validation and checksums do not establish malware safety. A scanner's integration/quarantine/release policy must be agreed before production use; the application does not label unscanned files clean or release pending/blocked files. An audit success named `file.download_authorized` records the permission/integrity check, not proof that the client received or opened every byte.

Upload, own-list, metadata and download-authorization actions produce restricted audit events. HTTP denials/failures on those routes are recorded when they reach the route middleware. Audit summaries include size/type or verification counts only; they exclude filenames, storage paths, checksums and file contents. Application failures cannot bypass the normal JSON error contract.

To check storage consistency from the trusted server console:

```powershell
.\dev.cmd artisan files:verify
```

The command checks all metadata records and private blobs, reporting only aggregate checked/missing/corrupt/orphaned counts. It returns a failure exit code on inconsistencies or incomplete checks, audits the verification outcome, and does not remove bytes, alter metadata or change scan status. Run it while uploads are paused so a file being created does not appear temporarily orphaned. It verifies storage integrity, not malware safety.

For backup preparation, preserve MySQL's `files` rows and the entire protected root as one consistent recovery set. Record the configured root, PHP/web-server limits and service/backup filesystem permissions in the installation handover. Pause upload writes, take the database backup and file copy/snapshot together, then resume writes; keep the recovery set encrypted/restricted and separate from live storage. Restore both into an isolated installation, run `files:verify`, then repeat owner/permission-checked download tests. A database-only backup cannot restore file bytes. Actual scheduled backups, retention, recovery targets and a demonstrated full restore remain task 54; the command and this layout prepare that work without claiming it has been completed.

Task 14 verification on 11 November 2026: all 171 backend tests passed on MySQL (1,068 assertions). SQLite passed 170 tests (1,067 assertions), with one MySQL-only check skipped. The 31 file-specific cases cover actual MIME/extension validation, private ownership and pagination, byte-exact downloads, missing/corrupt/quarantined files, failed disk/audit writes, private exception reporting, filename safety, reference integrity, repeat seeding, upload throttling and consistency verification. Seventeen frontend regression tests and fifty-two browser checks passed, with no page errors. Browser checks include actual multipart upload/download, persistence after refresh, spoofed/oversized files, read-only roles, revoked access, cross-account denial even for administrators, account changes, desktop/mobile layouts and interruption/retry displays. The production build passes; full type checking still reports 125 existing errors outside changed files. The normal development storage check passed with no file records, missing/corrupt/orphaned bytes or staff accounts. Local configuration has ten permissions, twenty-one role grants and four baseline markers. An audit event records the development integrity check.

Repeat file acceptance with synthetic staff in an isolated installation: upload a permitted file, refresh, download and compare bytes; try a spoofed extension, an oversized/empty file and forbidden metadata fields. Two authorized staff must see only their own files; another uploader's IDs must fail for metadata and bytes even when the caller is an administrator. A view-only file role must have no upload form or upload access. Revoke view access while its list is open and confirm the next request/reload removes the content. Check network failure, a late old-account download, mobile table scrolling, and sign-out/account changes. Finally, simulate missing, corrupt and orphaned files and confirm `files:verify` reports them without deleting anything. Production acceptance must also address scanning and paired backup/restore.

## Local files and logs

- `.tools/mysql-data/`: actual local MySQL database files.
- `.tools/mysql-server.log`: MySQL startup/errors.
- `.tools/backend-stdout.log` and `.tools/backend-stderr.log`: background PHP server logs.
- `backend/storage/logs/laravel.log`: Laravel application log.
- `.tools/mysql-admin.cnf`, `.tools/mysql-app.cnf` and `.tools/local-dev.json`: private local connection details.

The backend Git repository contains application source. The project-local `.tools` directory sits outside it; other team members will need their own PHP/Composer/MySQL installation or a separately prepared development environment.

## Official references

- [Laravel installation](https://laravel.com/docs/13.x/installation)
- [PHP on Windows](https://www.php.net/manual/en/install.windows.php)
- [Composer installation](https://getcomposer.org/download/)
- [MySQL Windows archive setup](https://dev.mysql.com/doc/refman/8.4/en/windows-install-archive.html)


## Transaction and retry rules

Task 15 implements the shared safeguards for the currently delivered staff and private-file workflows. Apply the additive `create_idempotency_requests_table` migration with `dev.cmd artisan migrate --no-interaction`. No patient, stock, payment or admission services are introduced by this task; they must use these rules and add their own aggregate locks, constraints and concurrency tests when implemented.

### Submission contract

Authenticated staff creation (`POST /api/v1/staff-management`), updates (`PATCH /api/v1/staff-management/{user}`), recovery issuance (`POST /api/v1/staff-management/{user}/password-reset`) and uploads (`POST /api/v1/files`) require `Idempotency-Key`. Missing or invalid keys return `422` with `errors.idempotency_key`. Supply a cryptographically random key for one intended submission and keep that same key and payload for an explicit retry. Keys accept 16 to 128 ASCII letters, digits, dots, colons, underscores and hyphens. The frontend uses 128 random bits encoded as 32 hexadecimal characters. Cross-origin development allows this header and exposes `Idempotency-Replayed` and `Retry-After`.

The key namespace is the authenticated actor and named route. The request fingerprint also includes the target staff ID, preventing accidental reuse across target accounts. JSON object-property order is ignored; array order and value types are significant. Upload identity includes the original filename, actual byte length and SHA-256 of the uploaded bytes, rather than trusting a browser content-type hint. Other submitted fields participate in identity. Current-password reauthentication is checked freshly on every staff write, including a replay, but the password is excluded from the fingerprint. Other current permissions, active status, target access and file ownership are checked before confirming an earlier command.

New successful creates/uploads return `201`; updates return `200`. A confirmed repeated create, update or upload returns `200`, the same resource ID and `Idempotency-Replayed: true`, without repeating the domain mutation or its success audit event. A new execution returns `Idempotency-Replayed: false`. Replays reconstruct an authorized resource rather than caching a response body. Staff replays require the saved resource version still to match; they never return an outdated snapshot as a successful new edit. Confirmation adds a separate, payload-free `command.replayed` audit event.

| Result | HTTP/code | Client action |
|---|---|---|
| Same key, changed payload or target | `409 idempotency_conflict` | Inspect the current record; start a new submission only for deliberately new work. |
| Original staff save completed, then the account changed again | `409 idempotency_outdated` | Reload the current staff record. |
| Confirmation window elapsed or an invalid completion state was found | `409 idempotency_expired` | Inspect saved records before starting another submission. |
| Recovery code already issued | `409 idempotency_completed` | The code cannot be redisplayed. Reload the target, verify identity and deliberately issue a replacement if needed. |
| Stale edit or recovery version | `409 conflict` | Cancel and reload; never automatically replace the version with a newer one. |
| Database concurrency retries exhausted | `503 write_busy`, `Retry-After: 1` | Wait and explicitly retry the same submission key. |
| Throttled | `429`, `Retry-After` | Wait for the indicated period; retain the pending key. |
| Connection loss, client timeout, server error or unreadable response | Outcome unconfirmed | Retain the key and unchanged payload; explicitly confirm the same submission. |

Completed keys can confirm an outcome for 24 hours. Expired rows remain as tombstones and reject reuse; there is no automatic deletion or recycling of keys. Validation, authorization, audit or completion-record failure rolls back the claim together with the mutation, so a definitely failed submission does not reserve its key. The table stores a SHA-256 key digest, an application-keyed HMAC request fingerprint, status, original HTTP code, resource type/ID/version and UTC timestamps. It stores no raw key, request payload, password, recovery code or cached response. Retain this table with database backups. Application-key rotation changes fingerprints and makes old confirmations fail closed; key lookup remains stable so rotation cannot turn a replay into a fresh upload. Retention/pruning requires a later hospital-approved policy and must preserve the duplicate-prevention guarantee.

Recovery-code issuance returns its secret only on the first successful execution. A replay does not issue another code or retain the original plaintext for redisplay. Recovery redemption (`POST /api/v1/auth/password/reset`) instead has its existing locked, expiring, one-use token rule. After an uncertain redemption, try signing in with the chosen password before requesting another verified reset. Authentication/session endpoints are not enrolled in the command-key protocol. Only login/logout may refresh CSRF and retry once after a known pre-mutation `419`; business writes are never silently replayed.

### Transactions and lock order

`IdempotentCommands` completes the claim, related domain records, session/reset revocation, success audit and resource outcome in the same database transaction. Claim lookup precedes uniqueness and stale-version validation, allowing an exact retry to confirm an already committed operation. Form requests still validate every new execution. A unique database constraint on actor/route/key is the final identity guard.

Current staff/recovery/upload commands first lock the `staff.manage` permission row, then refresh and lock the actor, then the target when applicable, and finally the command record. The shared guard coordinates with existing console permission and recovery operations and serializes the current small set of access-sensitive writes. Missing guard configuration fails closed. This is deliberately conservative for the current foundation; future clinical, bed, billing and stock services need their own documented aggregate lock order rather than routing all hospital traffic through this guard. A locked staff version is compared before any edit or recovery issuance, incremented on success and never silently overwritten.

`WriteTransactions` allows at most three attempts for database-only deadlock, serialization or lock-wait conflicts detected by the installed Laravel version. Nested services propagate concurrency exceptions so the entire outer command is retried; they cannot retry one partially completed fragment. Validation, authorization, uniqueness, stale versions, unrelated failures and uncertain connection/commit errors are not automatically retried. Filesystem operations receive one attempt only. Database lock-wait duration still follows the configured database server setting; the frontend's 30-second request deadline does not cancel an operation that has reached the server.

A file upload writes a random private blob and verifies its bytes before committing metadata, audit and command completion. If the outer completion fails, cleanup queries the authoritative metadata before deleting an unreferenced blob. It preserves a referenced blob when a commit may have succeeded. If database/storage state cannot be checked, it leaves the bytes for read-only `files:verify` inspection instead of risking deletion of a committed document. Power loss and failed cleanup can still leave private orphaned bytes; database transactions do not make filesystem writes crash-atomic. Do not automate orphan deletion. Future external notifications, integrations and other irreversible effects belong after a confirmed commit or in a transactional outbox, never inside a database-only retry closure.

### Frontend behavior and verification

Staff and upload forms retain an in-memory attempt key until success or explicit abandonment. Unconfirmed writes keep the selected data fixed and offer a manual retry. Reentering the current password does not change the command identity. New form data, a new file, confirmation or a staff access boundary starts a different attempt. No key, file bytes, credentials or draft are persisted in browser storage. A full page reload clears the pending attempt: inspect the staff list or file library before starting another submission. Requests and CSRF bootstrap have a 30-second deadline, account changes still abort pending work, and late old-session results remain rejected. Error messages expose the server's `Retry-After` guidance without automatically sending another write.

Run `dev.cmd php vendor/phpunit/phpunit/phpunit tests/Feature/TransactionRulesTest.php --no-progress` for command/rollback cases. Run `dev.cmd php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml tests/Feature/TransactionConcurrencyTest.php --no-progress` for real parallel MySQL requests and retry boundaries. Parallel workers enforce the isolated `easycare_test` database/account and use synthetic data. SQLite skips the three actual parallel-transaction cases. Run complete suites sequentially with `dev.cmd composer verify`. Task 16 isolates private-file fakes by database driver, process and test; the MySQL suite still owns a fixed test schema and must not run alongside another MySQL suite or browser fixtures. Frontend checks are `npm test` and `npm run build`; the latter includes strict TypeScript verification.


Task 15 verification on 11 November 2026: all 200 backend tests passed on MySQL (1,238 assertions). SQLite passed 196 tests (1,215 assertions), with four MySQL-only checks skipped. The 29 new transaction/retry cases cover duplicate commands, changed data/targets, actor scope, current reauthentication and revoked permissions, version conflicts, expired tombstones, application-key rotation, command-completion rollback, upload byte/name identity and cleanup. The MySQL suite includes real parallel duplicate creates and conflicting edits; rollback/retry cases also verify complete outer retries and one-attempt filesystem behavior. Twenty-six frontend regression tests passed, with zero TypeScript errors and a successful production build. Thirty-two browser checks passed with no page errors, including actual committed responses deliberately lost before rendering, same-key confirmation of one staff account/file, one-time recovery-secret behavior, existing administration/recovery/file permissions, account switching and desktop/mobile retry layouts. The additive migration is applied locally; development has zero staff, file and command records. Synthetic browser accounts/files/command records were removed and only the isolated preview servers were stopped. These results cover current shared workflows; future clinical and financial operations still need their own domain constraints, authorization, aggregate locking and concurrency acceptance tests.

## Reusable feature verification

Task 16 establishes a repeatable test recipe for each new module. The executable example is [FeatureVerificationTest](../tests/Feature/FeatureVerificationTest.php); shared helpers live in [TestCase](../tests/TestCase.php). Staff administration, audit history, protected files and transaction tests now use those helpers. They exercise the existing APIs without introducing patient workflows or changing production behavior.

Run the combined backend gate from `backend`:

```powershell
.\dev.cmd composer verify
```

On another developer's installation, use `composer verify`. It runs `test:sqlite` followed by `test:mysql` and stops on failure. Install the locked development dependencies with `composer install`, configure the restricted local `easycare_test` account as described above, and keep configuration uncached for tests. The test bootstrap refuses development/production databases, remote MySQL, database URLs and non-memory SQLite. Never run tests against the hospital database. Do not run another suite or populate browser fixtures in the fixed MySQL test schema during this command. MySQL supplies actual row-lock/concurrency and database-specific constraint evidence; SQLite skips those cases.

To focus on the verification recipe while developing:

```powershell
.\dev.cmd php vendor/phpunit/phpunit/phpunit tests/Feature/FeatureVerificationTest.php --no-progress
.\dev.cmd php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml tests/Feature/FeatureVerificationTest.php --no-progress
```

Use `RefreshDatabase`, explicitly seed the reference catalog, then build synthetic fixtures. Do not silently seed inside a helper or give every actor administrator permissions. These helpers are available to feature tests:

| Helper | Contract |
| --- | --- |
| `createStaffWithRole($role, $attributes)` | Creates staff with an existing seeded role and a known test-only password; accepts explicit attribute overrides. |
| `grantRolePermissions($role, $codes)` | Adds only named existing permissions; rejects unknown codes rather than inventing grants. |
| `fakeProtectedStorage()` | Gives each database driver/process/test its own private fake disk and cleans its owned directory after the test. Use it for any file workflow. |
| `postCommand()` / `patchCommand()` | Sends a fresh request key by default. Supply an explicit identical header for duplicate/retry tests. |
| `assertApiFailure($response, $status, $code, $fields)` | Checks the JSON error contract, non-empty message, private/no-store header and optional field errors. |
| `assertTablesUnchanged($tables, $operation)` | Compares complete sorted row contents before/after, including unchanged row counts with altered values; returns the operation result and rejects an empty table list. Failure output uses hashes rather than private rows. |
| `assertAuditEventRecorded(...)` | Checks the exact actor, logical subject, outcome, matching event count and selected safe-summary values. |
| `assertNoSuccessfulAudit($action)` | Requires zero success events for that action in the isolated fixture. |
| `assertAuditExcludes($sentinels)` | Checks decoded audit fields for sensitive fixture values, including JSON-escaped strings. It supplements the audit allowlist tests. |
| `failNextWriteTo($table, $operation)` | Throws once after a matching actual `insert`, `update` or `delete`; returns a closure reporting whether the intended boundary was reached. |

For each new API, require these cases before connecting its screen:

1. Save valid input through the real route, assert its response, then GET the saved ID afresh. Check database values, every relationship, version and server-derived fields. Confirm exactly one correctly attributed success audit and the absence of sensitive sentinels in audit/API output.
2. Exercise missing/invalid fields, unknown references, invalid combinations and client-supplied protected fields. Assert field errors and unchanged contents of every affected domain, pivot, session/recovery and command table. A status code or row count alone is insufficient.
3. Exercise a guest, an inactive actor, a role without permission, an explicitly read-only role and a permitted actor outside the record's scope. Check direct record IDs, collections, search and attachments for leakage. Distinguish permission denial from hidden-record `404` according to the approved module policy. Current file ownership is an example, not a universal patient-access rule.
4. Force failure after a domain write, after the success audit insert and at command completion. Assert the hook was reached, all related records rolled back, no success audit survived, and staged file bytes were cleaned when unreferenced. Check denied/failed audit events separately: those intentionally survive rejection. Explicitly assert legitimate security side effects; for example, inactive-session rejection rotates the remember token while preserving the profile.
5. Add stale-version, exact-duplicate, changed-payload, changed-target and expired-key cases. Use the same explicit request key for confirmation; verify one resource and one success event. Test actual competing requests on MySQL for the module's documented locks and domain constraints. Database-only retry and filesystem behavior must follow the transaction rules above.

The existing staff example selects all affected tables and targets the completion **update**, so the test cannot pass merely because the initial command claim failed:

```php
$this->actingAs($this->admin);
$hit = $this->failNextWriteTo('idempotency_requests', 'update');
$response = $this->assertTablesUnchanged(
    ['users', 'user_roles', 'sessions', 'staff_password_resets', 'idempotency_requests'],
    fn () => $this->postCommand('/api/v1/staff-management', $this->staffData()),
);
$this->assertTrue($hit());
$this->assertApiFailure($response, 500, 'server_error');
$this->assertNoSuccessfulAudit('staff.created');
```

Here `$this->admin` and `staffData()` are fixtures defined in `FeatureVerificationTest`; adapt the table list and domain assertions to the new workflow. Inject failures only inside isolated tests. Do not add failure-injection endpoints or production debug controls. The new suite also tests the helpers themselves: a same-count value change, an empty snapshot list and an escaped private audit value cannot produce a false pass.

### Browser acceptance recipe for every module

Prepare an isolated installation with synthetic records and accounts for allowed, read-only, denied and inactive access. Record the approved role/scope rules, exact build, browser/version, date and desktop/mobile viewports (currently 1440 px and 390 px). Use two distinguishable records and two independent browser sessions. Future patient scenarios remain pending until their APIs and approved access rules exist; do not mark demonstrations or blocked cases as passed.

| Scenario | Repeatable browser check and expected evidence |
| --- | --- |
| Entry and authentication | Sign in through CSRF-protected sessions, reload and open a private URL directly. Guests/inactive staff cannot enter; allowed navigation matches server permissions. Check the actual API response as well as the screen. |
| Valid save and relationships | Save distinguishable values and related records. Success appears only after the response confirms persistence. Reload/reopen by ID and compare saved values and relationships; an authorized audit reviewer sees the correct actor, subject and one success event. |
| Invalid input | Try required fields, bad dates/references and protected fields. Errors identify the affected inputs, retain appropriate entered values and show no success state or partial related record. |
| Permission and record scope | Use read-only/denied accounts and another record's direct ID, search and attachment links. Deny writes and hide inaccessible data according to the approved policy; no name or bytes leak through errors. |
| Stale edits and competing actions | Open the same record in two sessions. Save one, then submit the stale version: show conflict and reload current data without overwrite. Repeat the module-specific competing booking/bed/stock/payment scenario once that module exists; reconcile persisted results. |
| Duplicate submission | Double-submit or manually retry the same unchanged pending request. Confirm the same key, one record and one success audit. Changing payload/target under that key must fail without a second mutation. For files, compare downloaded bytes as well as metadata. |
| Lost response after commit | In the isolated test browser, drop a successful response after the server commits. Show an uncertain result and allow manual same-key confirmation; verify one saved resource. Recovery-code confirmation must never redisplay a one-use secret. |
| Failure and recovery | Interrupt the network before a save/read, then reconnect and use the offered retry. No fabricated success, stale list or automatic repeated write is allowed. Server rollback is proven by the API failure-injection tests, without a product debug switch. |
| Revocation and account switch | Revoke permission while a screen is open, re-request, sign out/in as another actor, use another tab and Back/Forward. Private rows, forms, object URLs and late responses must not reappear under the wrong scope. |
| States and layout | Verify loading, empty collection, forbidden, hidden/not-found and unavailable-server states on desktop/mobile; inspect long errors, dialogs and retry guidance. Downloads require current permission and exact bytes. |

Capture one evidence row per scenario, with no real patient data or secrets:

| Build/date/module | Scenario and API test reference | Browser/version/viewport | Steps and expected result | Actual result/evidence | Status or unresolved approval |
| --- | --- | --- | --- | --- | --- |
| Commit or local revision/date/module | Test method or pending module case | Supported browser and dimensions | Synthetic account/record labels and actions | Sanitized observation or screenshot reference | Pass, fail, blocked or not run |

The automated examples map to `FeatureVerificationTest`, `StaffManagementTest`, `ProtectedFilesTest`, `AuditHistoryTest`, `TransactionRulesTest` and `TransactionConcurrencyTest`. When frontend behavior changes, also run `npm test` and `npm run build` in `easycare2` and repeat affected browser scenarios. Task 15's 32 browser checks are earlier evidence for current modules; Task 16 changes test infrastructure and documentation and does not claim a new browser run or hospital acceptance. Record later modules' actual results separately. Hospital identity-verification approval, patient-access decisions and final staff acceptance remain open checklist items.

Task 16 verification on 11 November 2026: `dev.cmd composer verify` completed successfully. MySQL passed all 213 tests with 1,460 assertions. SQLite passed 209 tests with 1,437 assertions and four MySQL-only checks skipped (213 cases total). The thirteen new cases cover persisted relationships, validation and authorization rejection, hidden file ownership, domain/audit/command rollback, file cleanup and the helper self-checks described above. The existing real parallel MySQL cases also passed. This task adds no production endpoints, schema changes or dependencies.

## Patient storage foundation

Task 17 implements demographics, categories, identifiers, emergency/next-of-kin contacts and links between distinct patients. The authoritative installation is the additive `2026_11_11_171342_create_patient_foundation_tables.php` migration. Apply it and the reference seeder from `backend`:

```powershell
.\dev.cmd artisan migrate --no-interaction
.\dev.cmd artisan db:seed --no-interaction
```

MySQL requires the schema-level `TRIGGER` privilege and the binary-logging setup already described for audit history. The patient migration installs database guards and number-reservation triggers. Existing staff, grants, audit history, private files and command records are preserved. MySQL DDL is not atomic; inspect an installation failure before retrying. Reversing this migration drops its five tables and their data, so rollback after patient use needs a reviewed backup/recovery plan.

| Table/model | Stored information and relationships |
| --- | --- |
| `patient_categories` / `PatientCategory` | Stable unique category code and editable name; has many patients. |
| `patients` / `Patient` | Stable internal ID, current `mrn`, structured names, sex, nullable birth date and estimate flag, phone/email, marital status, religion, occupation, state of origin and address. Links to category, registering staff and optional private-file metadata. Life status, death time, access status and version are separate fields. |
| `patient_identifiers` / `PatientIdentifier` | One registry of all current and historical hospital patient numbers. Every number belongs permanently to one patient. |
| `patient_contacts` / `PatientContact` | Name, relationship, phone and positive priority; priorities are unique within each patient and contacts are read in that order. These contacts need not themselves be registered patients. |
| `patient_relationships` / `PatientRelationship` | Directed, labeled links between two distinct patient IDs; rejects duplicate links and self-links. Relatives, including each baby, retain separate demographic records. Reverse or reciprocal links are not invented automatically. |

```mermaid
erDiagram
    users ||--o{ patients : created_by
    patient_categories ||--o{ patients : category_id
    files o|--o{ patients : photo_file_id
    patients ||--|{ patient_identifiers : patient_id
    patients ||--o{ patient_contacts : patient_id
    patients ||--o{ patient_relationships : patient_id
    patients ||--o{ patient_relationships : related_patient_id
```

Models expose category/registrar, current and historical identifiers, ordered contacts, outgoing relationships and incoming relationships. `User::registeredPatients()` and `PatientCategory::patients()` provide inverse relationships. Dates of birth use date-only immutable casts, not a stored age; age will be derived at presentation time. The estimate flag requires a known date. Death time is allowed only with deceased life status; a deceased status can have an unknown death time. The access states (`active`, `restricted`, `merged`) do not represent life status or insurance eligibility, and their storage does not implement the later restriction/merge workflows.

Foreign keys restrict deletion of referenced patients, categories, registering staff and photo metadata. The photo link provides referential integrity only: it grants no upload/download permission, checks no patient ownership and does not make the existing staff-owned library a patient attachment service. Patient APIs must add the approved policies and attachment workflow before using it.

### One number registry

On every patient insert, including raw SQL, a database trigger inserts the current `mrn` into `patient_identifiers` as `kind: mrn`. On a changed MRN, the same database statement retires its previous reservation with a UTC timestamp and reserves the new number. The unique registry rejects collisions against any current or historical number. A collision aborts the whole statement, including demographic changes and attempted retirement. Ordinary profile changes do not create extra identifiers. Explicit imported old numbers use `legacy_mrn` with a retirement time; retired rows originating from an earlier current MRN retain `kind: mrn`.

Identifier values, ownership and kind cannot be rewritten. Retired numbers cannot be reactivated or deleted, including reuse by their original patient. The current number cannot be retired separately or supplemented with a second current number. These rules close the proposal's gap between separate `patients.mrn` and `patient_identifiers.identifier` unique keys. A later approved merge must preserve source identity and resolve its canonical patient through merge history; moving or deleting number reservations is not a merge implementation.

The current storage convention is a maximum of 64 ASCII characters, beginning with a letter or digit and containing uppercase letters, digits, `/`, `.`, `_` or `-`. Eloquent normalizes MRNs and identifier assignments by trimming outer whitespace and uppercasing; direct SQL must already be canonical. The database rejects controls, embedded spaces and noncanonical casing consistently on MySQL and SQLite. This defines collision comparison, not a hospital-approved numbering scheme: no prefix, counter, category conversion or allocation algorithm is assumed. Confirm actual card formats and migration requirements with the hospital before Task 18 finalizes allocation or import. Insurance membership numbers belong to future coverage records, not this globally unique hospital-number namespace.

The category seeder starts with `private`, `company`, `hmo`, `nhis`, `family`, `staff` and `walk-in`, matching the present registration screen. It preserves existing names and custom categories and creates no patients, contacts, numbers, staff accounts or access grants. These are editable starting categories for review. Coverage/insurer/plan structures remain deferred until insurance work requires their approved fields and relationships.

### Verification and next API work

`PatientFoundationTest` exercises persistence, both directions of relationships, date-only/Unicode handling, direct-SQL reservations, current/historical collisions, number-change rollback, identifier permanence, contact priorities, self/duplicate family links, missing foreign keys, safe demographic mass assignment, repeatable seeding and rollback of a multi-record transaction. `PatientMigrationTest` rolls the new migration back and reapplies it while preserving existing foundation records, then proves number reservation still works. Run the focused files with either test configuration, then `composer verify` for the complete gate.

At completion of Task 17 there were no patient endpoints, patient-access grants, screen connections or number-generation service. Demographic mass assignment excludes MRN, attribution, category, photo, version and life/access controls. Services must set approved server-controlled values explicitly, enforce current permissions and patient scope, save related rows with success audit and command confirmation, and compare/increment versions. Triggers enforce storage integrity; they do not authorize a change or write its application audit event. Task 18 below adds registration/search/edit APIs and input/date/duplicate validation. Task 19 adds governed restrictions, category/card history, flags and merges; Task 20 connects the patient screens and verifies isolation.

Task 17 verification on 11 November 2026: `dev.cmd composer verify` passed all 272 MySQL tests (1,651 assertions). SQLite passed 268 tests (1,628 assertions), with four MySQL-only checks skipped. The focused patient suites passed 59 cases on each database, including a trailing-newline number rejected consistently by both engines and 64-character Unicode names. PHP syntax and whitespace checks passed. The migration and reference seeder are applied to local development: seven patient categories, zero patient records, identifiers, contacts or family links, and zero staff, file or command records. Existing grant counts remain unchanged. No frontend source or browser checks changed for this task.

## Patient registration, search and editing APIs

Task 18 uses the decisions confirmed for this implementation: staff enter hospital/card numbers, and patient access is opt-in. The provisional scope permits only active records; restricted and merged patients remain hidden. Final hospital access, emergency override and numbering/allocation policies remain Stage 1/dependent-work decisions. There is no automatic number generator, admin bypass, restriction override or merge endpoint in this API.

Run migrations and `db:seed` to install the birth-date lookup index and add the three known permission codes. No role receives them automatically. After approval for a role, an authorized operator can grant each permission through the existing trusted console command, for example:

```powershell
.\dev.cmd artisan access:role frontdesk patients.view --no-interaction
.\dev.cmd artisan access:role frontdesk patients.create --no-interaction
.\dev.cmd artisan access:role frontdesk patients.update --no-interaction
```

These commands change real role access; use the appropriate environment and approved role. `patients.view` alone permits lookup/search, duplicate checks and category options. Registration also requires `patients.create`; edits also require `patients.update`. Neither write permission implies read access. Repeat seeding preserves revocations. Current sessions recheck active-account and permission state; mutations/replays refresh the actor and lock/recheck the target after waiting. A missing/inaccessible patient ID returns the same generic `404`; absent route permissions return `403` before validation. Collection counts, pagination, historical-number searches and duplicate candidates share the active-record scope.

### Registration input

Send JSON with `Accept: application/json`, the current session CSRF token and a fresh `Idempotency-Key`. For example, using a category ID returned by `/patients/options`:

```json
{
  "mrn": "CARD-001",
  "first_name": "Example",
  "last_name": "Patient",
  "category_id": 1,
  "date_of_birth": "2000-03-14",
  "dob_estimated": false,
  "phone": "+2348012345678",
  "contacts": [
    {"name": "Example Contact", "relationship": "parent", "phone": "+2348000000000"}
  ]
}
```

Required fields are `mrn`, `first_name`, `last_name` and a known `category_id`. The MRN uses the canonical 64-character storage convention above; its current/historical uniqueness is checked and backed by the database registry. Optional demographics are `middle_name`, `sex`, `date_of_birth`, `dob_estimated`, `email`, `phone`, `marital_status`, `religion`, `occupation`, `state_of_origin` and `address`. Names/middle name/sex/marital status/religion have their 64-character limits; occupation/state of origin/email have 191; address has 2,000. Optional text may be null. Email is validated and lowercased. Names are trimmed without changing their display case. Sex and other hospital-specific catalogs remain bounded text until the approved clinical vocabulary is established.

Birth dates must be actual `YYYY-MM-DD` dates no later than today in the configured application timezone (currently UTC). Unknown birth dates are null; age is not stored or accepted. `dob_estimated` must be boolean and requires a date. Phones are international digits with an optional leading `+` on input; spaces, hyphens and parentheses are removed, a leading `+` is added when absent, and the result must have 7-15 digits starting with a nonzero digit. No local-country prefix is guessed. Phone/email are not globally unique, since relatives can share contact details.

`contacts` is an optional ordered list of at most ten complete name/relationship/phone objects. Names are limited to 191 characters and relationships to 64. Contact phone rules match the patient phone rules. The server assigns priority from the list order; IDs, patient ownership and priority cannot be supplied. Omitted contacts start empty. Attribution is the current staff ID, with initial version 1, active access and unknown life status set by the server. Unknown top-level and nested contact keys are rejected, including empty/null protected fields. This prevents insurance, photo, relationship, status or other unsupported fields from appearing to save successfully. Coverage, life-status management, photos and family-link writes follow their dependent workflows.

The detailed resource includes the saved demographics, category ID/code/name, registrar ID, version, ordered contacts, current/historical identifiers, life-status fields and UTC timestamps. It omits file storage pointers, staff credentials and unrelated records. Category options are reference data only. Patient-list/duplicate summaries contain ID, current MRN, structured names, sex, birth date/estimate, category, version and timestamps; they omit email, phone, address, contacts and registrar identity. All responses are private/no-store.

### Search and duplicate review

`GET /patients` accepts `q` (up to 100 characters), exact `mrn`, `category_id`, exact `date_of_birth`, `page` (1-1,000,000), `per_page` (1-100) and `sort` (`mrn`, `-mrn`, `last_name`, `-last_name`, `created_at`, `-created_at`). Default sort is `last_name`, page size 25. ID breaks ties. Scope is applied before filters/counts. Search tokens must each match a name field, phone or current/historical identifier; SQL wildcard characters are literal. Exact `mrn` also resolves a retired number to its original patient, without following unimplemented merge history. Empty matches return `data: []` with the normal `links`/`meta` pagination contract. Unknown query fields are ignored and excluded from pagination links.

`POST /patients/duplicate-check` accepts `first_name`, `last_name`, `date_of_birth` (required key, nullable) and optional `phone`. Optional `exclude_id` must identify an accessible patient being edited. It returns `data` summaries and `meta.has_more`; it performs no write and needs CSRF but no request key. Possible matches are the same normalized first/last name and birth date, or the same patient phone. Name comparison collapses whitespace and ignores case, including Unicode. Unknown dates alone do not identify a duplicate, and emergency-contact phones are not matched. Matching is advisory: it neither proves identity nor merges relatives.

When a registration or identity/phone edit has possible matches, saving returns `409 duplicate_review_required` without partial rows. Run the duplicate check, review those records and, when a separate patient is appropriate, supply `acknowledged_duplicate_ids` containing the exact visible candidate IDs. The server recomputes the matches under the registry lock before saving; stale, invented, incomplete or extra acknowledgements fail. At most twenty candidates can be acknowledged; `has_more: true` requires a narrower/manual review and cannot be bypassed by acknowledging only the first page. A hidden patient is never returned as a candidate. Existing hidden MRNs remain globally reserved and unavailable, without disclosing the owner's details. The API cannot eliminate all duplicate identities; hospital review remains necessary.

### Partial edits, transactions and confirmation

`PATCH /patients/{id}` requires the latest `version` and accepts a subset of the mutable demographics plus optional contacts and duplicate acknowledgements. Omitted fields are retained. A supplied empty `contacts: []` clears contacts; a supplied list replaces them and assigns new contact IDs/priorities. Contacts are not individually patched in this contract. MRN, category, actor, photo, life/access state and timestamps cannot change through demographic edits. An unrelated address/contact edit does not force repeated duplicate review of an unchanged identity. A changed name, birth date or patient phone is rechecked. Estimated-date validation considers the persisted date/flag when the other field is omitted. Successful saves increment version; stale versions return `409 version_conflict` without overwriting any field/contact.

The patient mutation, generated number reservation, contacts, success audit and idempotency completion commit in one transaction. A failed domain/audit/completion write restores the entire aggregate. Current operations use the existing staff-management guard first to coordinate with permission/account changes, then the patient-registration permission row, actor, patient (for edits) and command record. The registry guard serializes candidate checks with registrations/identity edits, so two simultaneous different-number submissions cannot both skip review of each other. This conservative first-registry lock also serializes unrelated current patient writes; measure/revisit it with approved duplicate rules and performance requirements before large-scale hospital use. Future clinical and financial operations must use their own aggregate locks rather than extend this registry lock to all work.

Patient commands follow the existing bounded database-only retry rules. Exact same-key/same-body confirmation returns `200`, the saved patient ID/version and `Idempotency-Replayed: true`, before MRN uniqueness or old-version validation can reject an already completed save. Changed data/target, expired keys and later patient versions fail closed with the existing idempotency codes. Confirmation checks current permission and active-record scope again; it cannot return a now-restricted patient. No patient body is cached in the command table; it stores resource pointers/version and keyed request digests. Retrying with another key is a new submission. Writes are limited to thirty per staff/IP/minute, in addition to the existing API limit.

Audit actions are `patient.created`, `patient.updated`, `patient.listed`, `patient.viewed` and `patient.duplicates_checked`; confirmed retries add `command.replayed`. Actor/subject IDs identify the logical records. Summaries contain only approved field names, version, category ID, contact count/change flag and whether duplicates were acknowledged. No patient names, birth dates, phones, emails, MRNs, addresses, contact values, search terms or duplicate-ID lists are copied into the audit summary. Rejected/failed requests record the outcome after rollback. Database exceptions on patient routes are reported with a fixed message rather than SQL/bindings; HTTP failures remain generic. The audit viewer recognizes patient records, labels the new actions/details and filters by patient subject. Audit access is an independent permission and does not grant patient API access.

The automated contract is in `PatientApiTest` and the real parallel MySQL cases in `PatientConcurrencyTest`. `composer verify` runs the complete SQLite/MySQL regression gate. The MySQL script disables Composer's generic five-minute subprocess timeout because schema rebuild/concurrency suites can exceed it; individual parallel workers retain a twenty-second timeout. Run `npm test` and `npm run build` after audit-viewer changes. Patient registration/profile screens still use their existing demonstrations until Task 20; do not treat them as the operational frontend for these APIs. Hospital acceptance, clinical access decisions, card changes, restrictions and merges remain open dependent work.

Task 18 verification on 11 November 2026: the combined gate passed all 331 MySQL tests (2,547 assertions). SQLite passed 324 tests (2,497 assertions), with seven MySQL-only skips. The 56 patient API cases cover scoped authorization, date/contact/input validation, hidden-record behavior, candidate acknowledgements, rollback at domain/audit/completion boundaries, version conflicts, replay scope/expiry/actor/target and sanitized database reporting. Three real parallel MySQL cases verify one-patient confirmation, duplicate review after a competing registration and stale-edit rejection. Twenty-six frontend tests passed; strict TypeScript and the production build passed. Fourteen browser checks passed with no page errors using real sessions: guest denial, origin/token protection, persistent registration/lookup, invalid saves, partial/stale edits, duplicate review, hidden records, one-patient lost-response confirmation, read-only denial, patient audit filtering/privacy, mobile layout and sign-out. Desktop/mobile audit captures were inspected. Laravel 13 permits verified same-origin browser metadata or a valid CSRF token; the rejection check explicitly used untrusted origin metadata without a token, while normal clients continue sending tokens. The new index and permission catalog are applied locally: thirteen known permissions, twenty-one existing grants, zero patient-access grants, seven categories and zero development patients/staff/files/commands. Browser records belong only to the disposable test schema; its cleanup does not delete number reservations from the development or hospital database.
