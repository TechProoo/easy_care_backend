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

The application migrations now add the staff/role foundation to Laravel's runtime tables. Staff login uses the existing session table; patient and clinical workflows are later stages. The complete architecture SQL remains a proposal; it is not imported into the development database.

## Database and API foundation

Apply the application migrations and seed the reference catalogs:

```powershell
.\dev.cmd artisan migrate
.\dev.cmd artisan db:seed
```

The schema adds `departments`, `roles`, `permissions`, `user_roles` and `role_permissions`, plus staff identity fields on `users`. A department has many staff; staff have many roles through `user_roles`; roles have many permissions through `role_permissions`. Foreign keys prevent orphan relationships, unique keys prevent duplicate codes/assignments and MySQL checks require a positive staff version. Deleting a referenced department is restricted; deleting an authorization catalog record removes its pivot links, without deleting staff or the other catalog records.

The repeatable seeder adds seven initial department/role entries matching the current frontend modules and the seven known permissions defined in `config/authorization.php`. It applies each initial grant set once, tracked by `permission_baselines`. These are editable starting values; patient access rules still require hospital agreement. Seeding preserves existing catalog names and subsequent grant/revocation decisions. It creates no staff accounts or staff-role assignments. Factories are for synthetic tests only. The initial counts are seven departments, seven roles, seven permissions, eighteen role-permission links and two baseline markers.

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

`data.user` includes staff ID, username, names, email, active status, department, assigned roles and effective permission codes. Passwords and remember tokens are omitted. `data.expires_at` is the current estimated session expiry in UTC. Protected routes check the active account and session password fingerprint. Disabling an account or changing its password prevents subsequent authenticated access. Logout invalidates the current session and regenerates CSRF state. Auth responses and API errors use `Cache-Control: no-store, private`.

The frontend uses one in-memory identity store shared by the global auth context and clinician components. It restores identity from `/auth/me` after reload, and blocks private routes while identity is unknown or the server is unreachable. Module guards use server-returned roles; UI checks do not replace backend policies. Legacy browser-stored auth/profile placeholders are removed. Logout waits for a server response before clearing identity and reloading sign-in, discarding in-memory component state. Failed logout can be retried. Active users are rechecked periodically; idle pages do not send an endless heartbeat that keeps sessions alive. Expiry is checked against the server. Only login/logout may refresh CSRF and retry once after `419`; clinical mutations are never automatically replayed.

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

Run migrations and `db:seed` before using these endpoints. No email provider, internet connection or new package is required for recovery; clients still need a connection to the hospital server. The hospital must approve the identity-verification and console-operator procedure before production use. These workflows are implemented for local review; staff acceptance, production hosting and comprehensive audit history are later checklist tasks. The reset table records the current issuer, not a complete recovery audit trail.

Verification on 11 November 2026: all 113 backend tests passed on MySQL (647 assertions); SQLite passed 112 tests (646 assertions), with one MySQL-only check skipped. Tests cover permission denial, profile/relationship validation, stale edits, rollback, self-access protection, privilege escalation including inactive accounts, repeated seeds, code hashing/expiry/replacement/reuse, password byte limits, throttling, console recovery and real CSRF-protected sessions. Nine browser checks passed: admin entry, creation with assignments, editing after refresh, verified code issuance/clearing, mobile recovery and code reuse denial, recovered-staff access denial, stale edits after a password change, deactivation/session invalidation and reactivation. No page errors occurred; desktop/mobile views were inspected. The frontend builds. Its full TypeScript check still reports the same 126 errors outside the changed files. The development database has seven permissions, eighteen grants, two baseline markers and no staff accounts or pending resets.

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
