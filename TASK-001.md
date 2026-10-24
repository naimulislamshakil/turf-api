# TASK-001 — Super Admin Backend Foundation

## Endpoints

All endpoints are under `/api/v1`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/admin/auth/login` | Public, CSRF protected, rate limited | Establish a Sanctum stateful administrator session |
| GET | `/admin/auth/me` | Authenticated administrator session | Return the current active platform admin |
| POST | `/admin/auth/logout` | Authenticated administrator session, CSRF protected | Invalidate the administrator session |

Login accepts `email` and `password`. Successful login establishes a Sanctum stateful session cookie; no bearer token is returned. Password hashes and session/token values are never returned by API responses or audit records.

## Provisioning and local setup

1. Copy `.env.example` to `.env`, set `APP_KEY`, and provide PostgreSQL and Redis credentials.
2. Run `php artisan migrate --force`.
3. Provision the first administrator with `php artisan admin:create`. The command prompts for the password with hidden input and requires at least 12 characters.
4. Start Laravel and Next.js (`php artisan serve` and `npm run dev` from `frontend/`).
5. Run `php artisan test` and `npm run build` from their respective directories.

The frontend uses `credentials: include`, first requests `/sanctum/csrf-cookie`, then sends the `X-XSRF-TOKEN` header. Production/local runtime configuration is PostgreSQL + Redis in `.env.example`.

## Structure

- `app/Modules/Identity` — identity boundary placeholder.
- `app/Modules/PlatformAdmin` — platform administration boundary.
- `app/Modules/Subscription` — reserved placeholder; no subscription behavior is implemented.
- `app/Services/AuditLogger.php` and `app/Exceptions` — shared infrastructure.
- `frontend/app` — one shared Next.js application with login, protected admin layout, unauthorized, loading, and error routes.
- `frontend/lib/api.ts` — RTK Query API integration using Sanctum cookies.

## Limitations and risks

- PostgreSQL service is running, but PHP CLI has no `pdo_pgsql` driver, so actual PostgreSQL migrations and integration tests remain unverified.
- Redis is configured but no service is listening on `127.0.0.1:6379`; Redis-backed rate-limit/cache verification remains unverified.
- Login rate limits are cache-store dependent; use the configured shared Redis cache in a multi-instance deployment.
- The frontend’s protected layout is a UX guard only; Laravel remains the authorization boundary.
- Token expiry, password reset, MFA, and admin management are intentionally out of scope for TASK-001.
