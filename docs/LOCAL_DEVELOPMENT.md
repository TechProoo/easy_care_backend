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

The repeatable seeder adds seven initial department/role catalog entries matching the current frontend modules and the `reference-data.view` permission. These are editable starting values, not an approved hospital permissions matrix. Seeding preserves existing catalog names and creates no staff accounts, role assignments or permission grants. Factories are for synthetic tests only.

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

Base path: `/api/v1`. Send `Accept: application/json`; send JSON request bodies with `Content-Type: application/json` when write endpoints are introduced. JSON keys use `snake_case`. Successful resources are wrapped in `data`; paginated collections additionally include Laravel's `links` and `meta` objects. Return an empty collection as `200`, with `data: []`.

Implemented routes:

| Method and path | Access | Response |
|---|---|---|
| `GET /api/v1/health` | Public | `data.application`, `data.api_version`, `data.status`; `503` if MySQL cannot be queried. No database connection details are returned. |
| `GET /api/v1/auth/csrf-token` | Public, session-backed | `data.csrf_token`; starts an anonymous browser session. |
| `POST /api/v1/auth/login` | CSRF protected | Accepts `email_or_username` and `password`; returns `data.user`, `data.csrf_token`, `data.expires_at`. |
| `GET /api/v1/auth/me` | Active signed-in staff | Current identity, CSRF token and expiry; `401` for invalid sessions. |
| `POST /api/v1/auth/logout` | Active signed-in staff, CSRF protected | `204`, invalidates the current server session. |
| `GET /api/v1/departments` | Active signed-in staff with `reference-data.view` | Paginated department resources: `id`, `code`, `name`, `created_at`, `updated_at`. |

The existing `/` response and framework `/up` route remain available. `/up` checks application liveness; the versioned health route also checks database readiness.

Department query parameters:

| Parameter | Rules | Default |
|---|---|---|
| `q` | Optional text, at most 100 characters; literal substring in code or name | No filter |
| `sort` | `code`, `-code`, `name`, `-name`; minus means descending | `code` |
| `page` | Integer, at least 1 | `1` |
| `per_page` | Integer from 1 to 100 | `25` |

Lists use an ID tie-breaker for stable ordering. SQL wildcard characters in `q` are searched literally. Unknown query fields are ignored and are not copied into pagination links. No department write endpoint is exposed at this stage.

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

Foundation verification on 10 November 2026: all 51 PHPUnit tests passed on MySQL (138 assertions). The fast SQLite suite passed 50 tests (137 assertions), with the MySQL-only version-constraint test skipped. Coverage includes clean migrations, preservation/rollback of existing starter users, guarded rollback for missing email, relationship integrity, repeatable seeding, access denial, validation, pagination, CORS, sanitized failures and test-database isolation. The live health endpoint returned HTTP 200 with the configured frontend CORS origin; the development database contains seven departments, seven roles, one permission and no seeded staff accounts or role assignments.

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

No public registration or OTP activation endpoint exists. Account requests and recovery requests are directed to the hospital administrator until those workflows are built. Patient and clinical screens still contain demonstration data; signing in does not make them operational or production-ready. The complete permissions matrix, administration/recovery UI and clinical cache isolation remain on the checklist.

Create the first staff account from the server console:

```powershell
.\dev.cmd artisan db:seed
.\dev.cmd artisan staff:create --role=admin
```

The command privately prompts for username, names, optional email and a confirmed password. Usernames use lowercase letters, digits, dots, underscores or hyphens. Passwords require at least 12 characters, mixed case and a number, within bcrypt's 72-byte limit. Duplicate identifiers and unknown roles are rejected; creation and role assignment use a transaction. The role flag is optional and never defaults to administrator. Selecting a role does not add permission grants. Non-interactive execution is refused, keeping passwords out of command history and arguments. No staff account is automatically provisioned.

Verification on 11 November 2026: all 67 backend tests passed on MySQL (244 assertions); SQLite passed 66 tests (243 assertions), with one MySQL-only check skipped. Session tests use database-backed cookies and enable real CSRF verification instead of its usual test bypass. Eight browser checks passed: forged local identity, invalid credentials, login, refresh, role denial, connection failure/retry, logout and unassigned-role handling. No JavaScript page errors occurred. The frontend builds; its full TypeScript check still reports 126 errors outside the changed authentication files, so it is not a clean release check yet.

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
