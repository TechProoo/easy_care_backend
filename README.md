# EasyCare backend

Laravel PHP backend for the EasyCare React application. The database/API foundation and staff session login are implemented. Patient workflows and the full hospital permissions matrix are later stages.

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
.\dev.cmd mysql
```

For a foreground server, run `stop` and then `.\dev.cmd serve`. Press Ctrl+C to stop that server. Type `exit` to leave the MySQL console.

## Installed foundation

- PHP 8.4, Composer 2, Laravel 13 and MySQL 8.4 LTS.
- Local MySQL database: `easycare_dev`, accessible to a dedicated database-scoped application account.
- Laravel starter migrations: users/password resets/sessions, cache/locks and jobs/failed jobs.
- Staff foundation migrations: departments, staff identity fields, roles, permissions and their assignment tables.
- Versioned API readiness endpoint: `GET /api/v1/health`.
- Staff session endpoints: CSRF bootstrap, login, current staff identity and logout under `/api/v1/auth`.
- Protected department list: `GET /api/v1/departments`, with validation, filtering, sorting and pagination.
- Private `backend/.env` is configured with the generated local database password and application key.

`vendor/` contains installed PHP packages. `composer.lock` records their exact versions. `.env` and the project-local tools/database files are excluded from Git.

## First staff account and sign-in

From `backend`, create an account through private interactive prompts:

```powershell
.\dev.cmd artisan staff:create --role=admin
```

Use your own username and password; no demonstration accounts or default passwords are created. The password is entered privately and is not a command-line argument. The chosen role must already exist in the seeded catalog. Omitting `--role` creates an account with no module access; additional permissions are assigned in the later staff-administration stage.

Start the backend with `dev.cmd start`, then run `npm run dev` in the existing frontend directory and open its printed URL. The frontend uses `/api/v1` through Vite's local proxy to port 8000. It no longer sends sign-in to the previous cloud backend. Staff without assigned roles see an access-pending page. Staff administration and password recovery are not implemented yet.

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


## Architecture and database proposal

- [Ordered backend build checklist](docs/BACKEND_BUILD_CHECKLIST.md)
- [Backend architecture PDF](docs/architecture/EasyCare_Architecture_Reviewed_v1.1.pdf)
- [Complete database dictionary](docs/architecture/Database_Dictionary_v1.1.md)
- [Proposed MySQL schema](docs/architecture/schema.mysql.sql)
- [Frontend/database review](docs/architecture/Frontend_Database_Review.md)

The architecture and frontend review are design documents dated 4 November 2026. The Laravel development foundation was installed on 6 November 2026. The full 85-table schema remains a proposal; application migrations currently implement the staff/role foundation alongside Laravel's runtime tables. Patient, clinical, inventory and financial tables will be added with their respective workflows.
