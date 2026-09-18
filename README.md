# IqraDB NFE

Commissioning progress tracking for the NFE project — loop index, SAT, packages and milestones across Train-8 through Train-11.

Laravel 12 REST API + Next.js 15 frontend, in a single npm-workspaces repository.

```
apps/
├── api/      Laravel 12 REST API (Sanctum auth, SQLite by default)
├── web/      Next.js 15 App Router (TypeScript, Tailwind 4, shadcn/ui)
└── scraper/  Playwright worker that drives SCDB (runs on the VPS only)
```

See [`STRUCTURE.md`](STRUCTURE.md) for the directory map and where new code goes.

---

## Getting started

Requires **Node ≥ 20**, **PHP ≥ 8.2**, and **Composer**.

```bash
git clone git@github.com:thoriqmacto/iqradb_nfe.git
cd iqradb_nfe

npm install
npm run setup     # interactive — writes env files, bootstraps Laravel
npm run dev       # web + api in parallel
```

Then open **http://localhost:3000**.

What `npm run setup` asks:

| Prompt | Notes |
|---|---|
| **Project name** | Sets `APP_NAME` (Laravel) and `NEXT_PUBLIC_APP_NAME` (browser title). |
| **Where will the API run?** | "Local machine" for the standard dev loop. |
| **Laravel Herd?** (macOS only) | If yes: symlinks `apps/api` into your Herd parked root; the API URL becomes `http://<slug>.test`. |
| **API port** (no Herd) | Default `8000`. |
| **Auth mode** | `bearer` (default), `cookie`, or `mock`. |
| **Seed demo user?** | Creates `demo@example.com` / `password`. |

In local mode setup also runs `composer install`, creates `apps/api/database/database.sqlite`, then `php artisan key:generate` and `php artisan migrate`.

### Frontend only

If the API is hosted elsewhere and you only want to run the UI:

```bash
npm run setup     # pick "Remote backend", give the API origin
npm run dev:web
```

Laravel bootstrap (migrate, key:generate) is skipped — run those on the host serving the API.

### Non-interactive

```bash
node scripts/setup.mjs \
  --non-interactive \
  --mode=local \
  --auth-mode=bearer \
  --port=8000 \
  --seed

node scripts/setup.mjs \
  --non-interactive \
  --mode=remote \
  --api-url=https://api.example.com \
  --frontend-origin=https://app.example.com
```

Other flags: `--project-name=`, `--skip-deps`, `--skip-migrate`, `--no-seed`, `--herd=true` with `--herd-root=` / `--project-slug=`.

### Re-running setup

`npm run setup` is idempotent. Existing `.env` values are preserved — only keys you're actively changing get rewritten, and a `.bak` copy is saved next to each env file first. To start clean:

```bash
rm apps/api/.env apps/web/.env.local
npm run setup
```

To only rewrite env files (e.g. after renaming the project), `npm run setup:env`.

---

## The app

### Main navigation

The authenticated shell has five top-level sections — **Dashboard, Loop Index, SAT, Package, Milestone** — driven by `MAIN_NAV` in `apps/web/lib/nav.ts`. Settings and Sign out live behind the gear button at the right of the sticky top bar.

Page shells use the `SHELL_X` gutter from the same file: full-bleed width with no `max-w-*`, so tabular data gets the whole desktop viewport.

Adding a section: see "Adding a main-nav section" in [`STRUCTURE.md`](STRUCTURE.md).

### Dashboard

Four progress cards, Train-8 through Train-11, each showing an overall percentage plus loop / SAT / package / milestone counts. **The figures are currently placeholders** defined in `apps/web/app/(app)/dashboard/page.tsx` — they will be wired to a real endpoint once the data model lands.

### Scrapper

`/scrapper`, reached from the gear menu, automates pulling CSV reports out of SCDB (Smart Completions). There is no REST API for that system, so retrieval is browser automation: Playwright driving headless Chromium.

**Where it runs matters.** The page only configures, starts and monitors runs. The browser itself runs on the VPS, inside a Laravel queue worker. Nothing Playwright-related executes on Vercel or in the visitor's browser.

```
Laravel job → Node CLI (apps/scraper) → Chromium → SCDB
           → CSV download → private storage → CSV parse
           → staging rows → target adapter → transactional upsert
```

Two properties are worth knowing before you touch this code:

- **Pasted Codegen is never executed.** The Scrapper page accepts Playwright Codegen output, but it is input data. A conservative parser converts recognised statements into a structured recipe; anything ambiguous is reported as unsupported rather than guessed at. There is no `eval`, `new Function`, VM, or shell path anywhere in the conversion. See `app/Services/Scraper/CodegenParser.php`.
- **Only the configured SCDB host is reachable.** Every URL is checked against `SCDB_ALLOWED_HOSTS` before Playwright follows it — on save, and again at run time in the worker. Matching is exact, so `evil-chiyodanfe.ceccms.com` and `chiyodanfe.ceccms.com.attacker.test` both fail.

See [SCRAPPER.md](SCRAPPER.md) for the recipe schema, the first-run workflow, and deployment.

---

## Authentication

**Default: Sanctum bearer token.**

- `POST /api/v1/login` returns `{ user, token, expires_at }`.
- The web app stores `{ token, user, expiresAt }` in `localStorage` and sends `Authorization: Bearer <token>` on every request.
- `POST /api/v1/logout` revokes the token.
- On `401` the client dispatches `auth:expired`, clears storage, and sends the user to `/login`.

**Alternative: Sanctum SPA cookie.** Set `NEXT_PUBLIC_AUTH_MODE=cookie`, plus `CORS_SUPPORTS_CREDENTIALS=true` and your web origin in `SANCTUM_STATEFUL_DOMAINS` on the API. The cookie adapter primes `/sanctum/csrf-cookie` before each mutating call.

**Frontend-only dev: `mock`.** Set `NEXT_PUBLIC_AUTH_MODE=mock`. No HTTP calls are made; login and register instantly succeed as a fixture user. Useful when the API is intentionally offline and you only want to iterate on UI.

Adapters live in `apps/web/lib/auth/adapters/`. A new auth method is one more adapter plus an entry in `lib/auth/index.ts`.

### Password reset

- `POST /api/v1/forgot-password` emails a reset link; `POST /api/v1/reset-password` consumes the token.
- The link points at `${FRONTEND_URL}/reset-password?token=…&email=…` (configured in `App\Providers\AppServiceProvider::boot`).
- In local dev the mail driver is `log`, so the link lands in `apps/api/storage/logs/laravel.log`.
- Pages: `/forgot-password`, `/reset-password`.

### Email verification

`User` implements `MustVerifyEmail`. After register, Laravel sends a signed verification link (TTL from `VERIFICATION_LINK_TTL_MINUTES`, default 60).

- The link targets the **backend** route `/api/v1/email/verify/{id}/{hash}`. The `signed` middleware validates it — no auth header needed.
- On success the backend redirects to `${FRONTEND_URL}/verify-email?status=verified`; a wrong hash gives `?status=invalid`, a tampered signature gives 403.
- `POST /api/v1/email/verification-notification` (auth, throttled) resends it.
- Changing your email via `PATCH /api/v1/me` clears `email_verified_at` and triggers a new verification email.
- No route currently uses the `verified` middleware — verification status is available but not enforced. Add `->middleware('verified')` to gate a route.
- Frontend: the `/verify-email` page handles the redirect back, and `/settings` shows a resend card while the user is unverified.

---

## API

- `NEXT_PUBLIC_API_BASE_URL` is the **fully-prefixed** base (e.g. `http://localhost:8000/api/v1`). Client code calls `/login`, `/me`, `/logout` — the shared axios instance in `apps/web/lib/api.ts` prepends the base.
- `apps/web/app/api/[...path]/route.ts` is a same-origin proxy for SSR or cross-origin-sensitive setups. It reads `API_PROXY_TARGET`, or derives it from `NEXT_PUBLIC_API_BASE_URL`.

All endpoints return JSON:

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET  | `/api/ping` | public | Health. |
| POST | `/api/v1/register` | public | Throttled. |
| POST | `/api/v1/login` | public | Throttled. |
| POST | `/api/v1/forgot-password` | public | Throttled. |
| POST | `/api/v1/reset-password` | public | Throttled. |
| GET  | `/api/v1/me` | bearer | Current user. |
| PATCH | `/api/v1/me` | bearer | Update name/email. |
| PATCH | `/api/v1/me/password` | bearer | Change password (requires current). Revokes other tokens. |
| POST | `/api/v1/email/verification-notification` | bearer | Re-send the verify-your-email link. Throttled. |
| GET  | `/api/v1/email/verify/{id}/{hash}` | signed URL | Verification target. Marks the user verified, redirects to the frontend. |
| POST | `/api/v1/logout` | bearer | Revokes current token. |

Public auth endpoints are rate-limited to `AUTH_THROTTLE_PER_MINUTE` requests per minute (default `10`), keyed by user or IP. Over the limit returns `429`.

New endpoints go under `/api/v1` in `apps/api/routes/api.php`, with a feature test in `apps/api/tests/Feature/`.

---

## Scripts

From the repo root:

```bash
npm run dev         # Turbo: web + api in parallel
npm run dev:web     # just web
npm run dev:api     # just api (php artisan serve)
npm run build       # Turbo build
npm run lint        # Turbo lint
npm run typecheck   # Turbo typecheck (web only)
npm run test        # Turbo test (runs api tests)
npm run test:api    # apps/api php artisan test
npm run setup       # interactive setup
npm run setup:env   # rewrite env files only
npm run setup:check # preflight + ping smoke test
```

Before opening a PR, run what CI runs:

```bash
npm run -w apps/web lint && npm run -w apps/web typecheck && npm run -w apps/web build
cd apps/api && php artisan test && ./vendor/bin/pint --test
```

---

## Environment reference

### `apps/api/.env`

Template: [`apps/api/.env.example`](apps/api/.env.example).

- `APP_NAME` — used in the mail sender, log prefix, and session cookie name.
- `APP_URL` — full URL the API is served at.
- `FRONTEND_URL` — where password-reset and verification links send the user.
- `CORS_ALLOWED_ORIGINS` — comma-separated origins the browser may call from.
- `CORS_SUPPORTS_CREDENTIALS` — `true` only in SPA-cookie mode.
- `SANCTUM_STATEFUL_DOMAINS` — only matters in SPA-cookie mode.
- `SANCTUM_TOKEN_EXPIRATION_HOURS` — bearer token lifetime (default 8).
- `AUTH_THROTTLE_PER_MINUTE` — rate limit on public auth endpoints (default 10).

### `apps/web/.env.local`

Template: [`apps/web/.env.local.example`](apps/web/.env.local.example).

- `NEXT_PUBLIC_APP_NAME` — browser tab title and top-bar branding.
- `NEXT_PUBLIC_API_BASE_URL` — includes `/api/v1`.
- `NEXT_PUBLIC_AUTH_MODE` — `bearer` (default), `cookie`, or `mock`.
- `API_PROXY_TARGET` — server-side proxy target (origin only, no path).

Real `.env` files are gitignored. Every new env key goes into the matching `.example` file first, with a comment.

---

## Deployment

The frontend deploys to Vercel; the API runs on its own server. One Git repository, two deploy targets.

```
iqradb_nfe
   ├── apps/api  → VPS / Laravel
   └── apps/web  → Vercel / Next.js
```

### Frontend (Vercel)

Import the whole repository and point it at `apps/web`:

| Setting | Value |
|---|---|
| **Framework Preset** | `Next.js` |
| **Root Directory** | `apps/web` |
| **Node.js Version** | `20.x` or newer |
| **Install Command** | *(automatic)* |
| **Build Command** | `cd ../.. && npx turbo run build --filter=web` |
| **Output Directory** | *(framework default)* |

The root directory must be exactly `apps/web` — not `web`, not `/apps/web`.

The custom build command exists because the npm workspace and Turborepo config live at the repository root, so the build has to run from there. `apps/web/package.json` still defines `"build": "next build"`; Turbo calls it through the workspace.

Environment variables (**Settings → Environment Variables**):

```env
NEXT_PUBLIC_APP_NAME=IqraDB
NEXT_PUBLIC_API_BASE_URL=https://api.example.com/api/v1
NEXT_PUBLIC_AUTH_MODE=bearer
API_PROXY_TARGET=https://api.example.com
```

> **Do not leave `NEXT_PUBLIC_API_BASE_URL` pointing at `localhost` in production.** Inside a Vercel deployment `localhost` is the Vercel runtime, not your API host — every authenticated request will fail.

Verify a deploy will succeed by building the same way locally:

```bash
npm install
npx turbo run build --filter=web
```

### API + scraper (VPS)

An nginx server block is provided at [`deploy/nginx/api.conf`](deploy/nginx/api.conf).

The scraper needs Chromium and a dedicated queue worker on the same host — see the deployment section of [SCRAPPER.md](SCRAPPER.md) for the exact commands, the systemd unit, and the directory permissions.

> The frontend does **not** need Chromium. `apps/scraper` depends on `playwright-core`, which never downloads a browser at install time, so a Vercel build stays clean without any extra configuration.

Point Laravel at the deployed frontend in `apps/api/.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
FRONTEND_URL=https://app.example.com
CORS_ALLOWED_ORIGINS=https://app.example.com
CORS_SUPPORTS_CREDENTIALS=false
```

Keeping a `*.vercel.app` origin in `CORS_ALLOWED_ORIGINS` as well is optional — useful if preview deployments should work against the production API.

Refresh the config cache after every `.env` change:

```bash
cd apps/api
php artisan optimize:clear
php artisan config:cache
```

### Checklist

```
[ ] Vercel root directory = apps/web
[ ] Build command uses Turbo (cd ../.. && npx turbo run build --filter=web)
[ ] NEXT_PUBLIC_API_BASE_URL points at the production API and includes /api/v1
[ ] NEXT_PUBLIC_API_BASE_URL does NOT contain localhost
[ ] API_PROXY_TARGET is the API origin, no path
[ ] Laravel CORS_ALLOWED_ORIGINS includes the frontend domain
[ ] Laravel FRONTEND_URL points at the frontend (reset + verification links)
[ ] Laravel config cache refreshed after .env changes
[ ] Local build passes (npx turbo run build --filter=web)
```

---

## Troubleshooting

- **CORS errors in the browser.** Your web origin needs to be in `CORS_ALLOWED_ORIGINS` on the API. Re-run `npm run setup:env` and restart `php artisan serve`.
- **`401` on `/me` right after login.** In SPA-cookie mode, usually a missing `CORS_SUPPORTS_CREDENTIALS=true` or `SANCTUM_STATEFUL_DOMAINS` entry. In bearer mode, localStorage was probably cleared.
- **A protected route redirects to `/login`.** Middleware relies on the `auth_hint` cookie set at login. If you cleared cookies, sign in again.
- **`MissingAppKeyException` when running tests.** `apps/api/.env` is missing or has no `APP_KEY`: `cp .env.example .env && php artisan key:generate`.
- **Herd link fails.** Herd integration is macOS only. On Linux/Windows answer "no" to that prompt and use `php artisan serve`.

---

## Contributing

- [`STRUCTURE.md`](STRUCTURE.md) — directory map and where to put new code.
- [`CLAUDE.md`](CLAUDE.md) — conventions and ground rules for this repo.

CI (`.github/workflows/ci.yml`) runs web lint/typecheck/build, Laravel tests, and Pint. PHP 8.2 is the floor.
