# Scrapper — SCDB report automation

Scrapper pulls CSV reports out of **SCDB / Smart Completions** (`https://chiyodanfe.ceccms.com`) for the NFE project. That system exposes no usable REST API, so retrieval is browser automation: Playwright driving headless Chromium, on the VPS, from a Laravel queue.

The `/scrapper` page and the "Scrapper" menu label use that spelling because it is the name the app ships with. Everything internal — classes, jobs, tables, config, the workspace — uses standard English `scraper`.

---

## Architecture

```
Browser (Vercel)                VPS
─────────────────               ────────────────────────────────────────
Scrapper page          POST     Laravel API  ──dispatch──▶  scraper queue
  configure / start   ───────▶  (202 + run id)                    │
  poll run status     ◀───────                                    ▼
                                              Laravel job: RunScraperRecipe
                                                              │ stdin (JSON)
                                                              ▼
                                              Node CLI: apps/scraper/bin/scraper.mjs
                                                              │
                                                              ▼
                                                    Chromium ──▶ SCDB
                                                              │
                                                     download event
                                                              ▼
                                        storage/app/private/scraper/runs/{uuid}/
                                                              │
                                              Laravel job: ProcessScraperCsv
                                                              ▼
                                        CSV parse → staging rows → adapter upsert
```

Boundaries are deliberate and should stay that way:

| Concern | Lives in |
|---|---|
| Browser automation | `apps/scraper` (Node + Playwright) |
| Run orchestration, locking, state | `app/Jobs/`, `app/Enums/ScraperRunStatus.php` |
| CSV parsing | `app/Services/Import/CsvReader.php` |
| Domain mapping + upsert | `ScdbImportAdapter` implementations |
| UI | `apps/web/app/(app)/scrapper/` |

The Node worker's only job is to drive the browser and capture a file. It does not parse CSVs — Laravel owns import logic.

---

## Security model

Four properties, each backed by tests:

**1. Pasted Codegen is never executed.** The Scrapper page accepts Playwright Codegen output as a convenience, but the paste is data. `app/Services/Scraper/CodegenParser.php` does anchored string matching and nothing else — no `eval`, no `Function`, no dynamic `include`, no VM, no shell, no reflection. A statement either matches one of the exact supported shapes or is reported as unsupported for manual setup. `page.evaluate`, `addScriptTag`, `addInitScript`, `route`, `exposeFunction` and `$eval` are rejected by name with an explanatory message.

**2. Only the configured SCDB host is reachable.** `ScdbUrlGuard` validates every URL before it is stored, and `src/urls.mjs` re-validates independently before Chromium follows it. Host matching is exact (no subdomain wildcards), the scheme must be `https`, and URLs carrying credentials or control characters are rejected outright.

**3. Authentication state never leaves the server.** The uploaded Playwright storage state is encrypted at rest with `APP_KEY` (`encrypted:array` cast), listed in the model's `$hidden`, and has no read endpoint. It reaches the Node worker over **stdin**, never argv — argv is world-readable through `ps`. Validation errors never echo the upload back, and `ScraperProcessRunner` redacts cookie/token-shaped strings before anything reaches a log.

**4. Recipes are a closed vocabulary.** Nine action types, six locator strategies. Unknown keys are stripped on save, so a stored recipe cannot carry a payload the runner might one day interpret.

---

## Recipe JSON schema

Stored in `scraper_recipes.actions`, validated on save by `RecipeValidator` and again at launch by `src/recipe.mjs`.

```jsonc
{
  "version": 1,
  "startUrl": "https://chiyodanfe.ceccms.com/Reports.aspx",
  "actions": [
    { "type": "click",
      "locator": { "strategy": "role", "role": "link", "name": "Reports" } },

    { "type": "click",
      "locator": { "strategy": "text", "text": "Loop Index" } },

    { "type": "selectOption",
      "locator": { "strategy": "label", "label": "Train" },
      "value": "Train-8" },

    { "type": "waitForVisible",
      "locator": { "strategy": "role", "role": "button", "name": "Export" } },

    { "type": "download",
      "locator": { "strategy": "role", "role": "button", "name": "Export" } }
  ]
}
```

### Actions

| Type | Requires | Notes |
|---|---|---|
| `goto` | `url` | Host-checked against the allowlist. |
| `click` | `locator` | |
| `fill` | `locator`, `value` | |
| `selectOption` | `locator`, `value` | |
| `press` | `locator`, `value` | e.g. `"Enter"` |
| `waitForURL` | `url` | Glob allowed; absolute patterns are host-checked. |
| `waitForLoadState` | `state` | `load` \| `domcontentloaded` \| `networkidle` |
| `waitForVisible` | `locator` | Prefer this over `networkidle` on SCDB. |
| `download` | `locator` | Arms the download listener, **then** clicks. |

Every action accepts an optional `timeoutMs` (100–120000).

### Locator strategies

Preference order, most robust first:

| Strategy | Shape |
|---|---|
| `role` | `{ "strategy": "role", "role": "button", "name": "Export", "exact": true }` |
| `label` | `{ "strategy": "label", "label": "Train" }` |
| `text` | `{ "strategy": "text", "text": "Loop Index" }` |
| `placeholder` | `{ "strategy": "placeholder", "placeholder": "Search" }` |
| `testId` | `{ "strategy": "testId", "testId": "export-btn" }` |
| `css` | `{ "strategy": "css", "css": "#ctl00_Main_btnExport" }` |

All accept `nth` to disambiguate. Prefer role/label/text: generated ASP.NET IDs like `ctl00_ContentPlaceHolder1_gvReports_ctl02_btnExport` change whenever the page structure is edited.

---

## First run: recording an SCDB session

### 1. Create the authentication state

On **your own trusted machine** — not the server, not CI:

```bash
npx playwright codegen --save-storage=scdb-auth.json "https://chiyodanfe.ceccms.com/Login.aspx?referrer"
```

Sign in to SCDB in the window that opens, then close it. Playwright writes `scdb-auth.json`.

> **`scdb-auth.json` contains authentication credentials and session cookies.** Never commit it, attach it to a ticket, or paste it into chat. Delete your local copy once uploaded.

### 2. Upload it

Gear menu → **Scrapper** → **Upload authentication state** → pick `scdb-auth.json` → **Validate session**.

A green *Connected* badge means SCDB accepted it. *Expired* means re-record from step 1.

### 3. Record a report

With the same Codegen session, navigate the report you want and export it:

```bash
npx playwright codegen --load-storage=scdb-auth.json "https://chiyodanfe.ceccms.com/"
```

Click through to the report, set any filters, and click Export. Codegen prints statements like:

```js
await page.getByRole('link', { name: 'Reports' }).click();
await page.getByText('Loop Index').click();
await page.getByLabel('Train').selectOption('Train-8');
await page.getByRole('button', { name: 'Export' }).click();
```

### 4. Turn them into a recipe

**Scrapper → New recipe**, fill in name, dataset key and start URL, then paste those statements into **Paste Codegen** and press **Convert to steps**. You get a side-by-side of the paste and the normalised actions, plus any statements that could not be converted.

Mark the export step as the download step — press **Make download** on it. Codegen records the export as an ordinary click; the runner needs it flagged so it arms `page.waitForEvent("download")` *before* clicking. Then save.

### 5. Run it

- **Test navigation** — walks the steps, no download. Fastest way to check selectors.
- **Run & download** — captures the CSV and stores it with a checksum.
- **Run, download & import** — the above, then parse, stage, and upsert.

Runs are queued, so the button returns immediately and the history table below polls for progress.

---

## Import pipeline

```
downloaded CSV → validate file → parse headers → stage rows
              → adapter? ─── no ──▶ status: ready_for_mapping
                        └── yes ──▶ normalize → validate → chunked transactional upsert
```

**No domain models exist yet** for Loop Index, SAT, Package or Milestone, so no adapters are registered and every dataset currently stops at `ready_for_mapping` with its rows staged and previewable. That is deliberate: inventing a schema to make the feature look finished would be worse than stopping honestly.

To add one when a real model lands:

1. Implement `App\Services\Import\ScdbImportAdapter`.
2. Register it in `AppServiceProvider::register()`, in the `ImportAdapterRegistry` singleton.

Nothing else changes — the pipeline picks it up by `datasetKey()`.

Guarantees the importer already provides, all covered by `tests/Feature/Scraper/CsvImportTest.php`:

- **Idempotent.** Re-importing identical data reports `unchanged`, not duplicates.
- **Transactional.** An adapter throwing mid-chunk rolls the chunk back; staged rows survive for diagnosis.
- **Fails loudly on drift.** A missing required header fails the batch instead of writing nulls.
- **Skips repeats.** The same checksum + dataset is skipped unless re-import is forced.
- **Auditable.** Source filename, checksum, headers, mapping version and every verbatim row are kept.

CSV parsing handles UTF-8 BOM, quoted commas, quoted newlines, escaped quotes, CRLF, duplicate headers (disambiguated), blank headers, empty values, and short/long rows (reported, not silently zipped).

---

## API

All under `/api/v1/scrapper`, all behind `auth:sanctum`, all scoped to the calling user.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/scrapper/session` | Session status. **Never returns the storage state.** |
| `POST` | `/scrapper/session` | Upload/replace authentication state. |
| `POST` | `/scrapper/session/validate` | Check SCDB still accepts it. |
| `DELETE` | `/scrapper/session` | Remove it. |
| `GET` | `/scrapper/recipes` | List your recipes. |
| `POST` | `/scrapper/recipes` | Create one. |
| `GET` | `/scrapper/recipes/{recipe}` | Show one. |
| `PATCH` | `/scrapper/recipes/{recipe}` | Update one. |
| `DELETE` | `/scrapper/recipes/{recipe}` | Delete one. |
| `POST` | `/scrapper/recipes/{recipe}/runs` | Queue a run → **202** + run id. |
| `GET` | `/scrapper/runs` | Run history (paginated). |
| `GET` | `/scrapper/runs/{run}` | Poll one run. |
| `GET` | `/scrapper/runs/{run}/preview` | Headers + first 50 rows. |
| `POST` | `/scrapper/codegen/parse` | Convert pasted Codegen to actions. |

Run modes: `test_navigation`, `download`, `import`.

Run statuses: `queued`, `starting_browser`, `validating_session`, `navigating`, `waiting_for_report`, `downloading`, `downloaded`, `parsing`, `validating`, `staged`, `importing`, `completed`, `failed`, `session_expired`, `cancelled`. Transitions are enforced centrally by `ScraperRunStatus::canTransitionTo()`; an illegal jump throws.

---

## Database

| Table | Holds |
|---|---|
| `scraper_sessions` | Encrypted storage state, one per user per host. |
| `scraper_recipes` | Name, start URL, dataset key, JSON actions, optional Codegen source. |
| `scraper_runs` | Status, timings, download metadata, failure diagnostics, artifact paths. |
| `import_batches` | Per-import counts, checksum, headers, mapping version. |
| `import_rows` | Verbatim source rows + normalised values + per-row errors. |

Indexed on user ownership, recipe/run relationships, status, `created_at`, dataset key, and `(user_id, dataset_key, checksum)` for the duplicate-file check.

Artifacts live at `storage/app/private/scraper/runs/{uuid}/`. Authentication state is **never** written there.

---

## Deployment (VPS)

Frontend deploys to Vercel and needs none of this. The API and scraper share a host.

### 1. Node 20+

```bash
node --version    # must be >= 20
```

### 2. Install workspace dependencies

```bash
cd /var/www/iqradb_nfe
npm ci
```

`apps/scraper` depends on `playwright-core`, which does **not** download a browser at install time. That is what keeps the Vercel build clean.

### 3. Install Chromium and its system libraries

```bash
cd /var/www/iqradb_nfe/apps/scraper
npm run install-browser
```

That runs `node node_modules/playwright-core/cli.js install --with-deps chromium`. `--with-deps` needs root, so on a locked-down host run the deps step separately as root:

```bash
sudo node node_modules/playwright-core/cli.js install-deps chromium
node node_modules/playwright-core/cli.js install chromium
```

Browsers land in `~/.cache/ms-playwright` for the user running the command. If the queue worker runs as a different user, either install as that user or set `PLAYWRIGHT_BROWSERS_PATH` to a shared readable path in both places.

### 4. Configure

In `apps/api/.env`:

```env
QUEUE_CONNECTION=database        # must NOT be sync
SCDB_BASE_URL=https://chiyodanfe.ceccms.com
SCDB_ALLOWED_HOSTS=chiyodanfe.ceccms.com
SCRAPER_NODE_BINARY=/usr/bin/node
SCRAPER_APP_PATH=/var/www/iqradb_nfe/apps/scraper
SCRAPER_QUEUE=scraper
SCRAPER_TIMEOUT_SECONDS=300
SCRAPER_ARTIFACT_RETENTION_DAYS=14
```

Use an absolute `SCRAPER_NODE_BINARY`: a systemd unit does not inherit your shell's PATH.

### 5. Migrate

```bash
cd /var/www/iqradb_nfe/apps/api
php artisan migrate --force
```

### 6. Storage permissions

```bash
sudo mkdir -p /var/www/iqradb_nfe/apps/api/storage/app/private/scraper
sudo chown -R www-data:www-data /var/www/iqradb_nfe/apps/api/storage
sudo chmod -R 750 /var/www/iqradb_nfe/apps/api/storage
```

`750`, not `755` — run artifacts are SCDB report data and must not be world-readable. Nothing under `storage/` is web-served.

### 7. Dedicated queue worker

`/etc/systemd/system/iqradb-scraper.service`:

```ini
[Unit]
Description=IqraDB scraper queue worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/iqradb_nfe/apps/api

# One process: a single SCDB session must not be driven by two browsers at
# once. The application also takes a per-user lock, but keeping the worker
# single means Chromium memory stays predictable.
ExecStart=/usr/bin/php artisan queue:work --queue=scraper --sleep=3 --tries=1 --timeout=600

# --timeout must exceed SCRAPER_TIMEOUT_SECONDS or the worker kills the job
# mid-run and the run is left stranded in a non-terminal state.

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now iqradb-scraper
sudo systemctl status iqradb-scraper
```

Supervisor equivalent, if you already run it:

```ini
[program:iqradb-scraper]
command=/usr/bin/php /var/www/iqradb_nfe/apps/api/artisan queue:work --queue=scraper --sleep=3 --tries=1 --timeout=600
user=www-data
autostart=true
autorestart=true
numprocs=1
stopwaitsecs=630
```

### 8. Artifact retention

```bash
# crontab -e, as www-data
0 3 * * * cd /var/www/iqradb_nfe/apps/api && php artisan scraper:prune >> /dev/null 2>&1
```

Or add `$schedule->command('scraper:prune')->daily()` if you already run Laravel's scheduler. `--dry-run` shows what would go.

### 9. Refresh caches and restart after every deploy

```bash
cd /var/www/iqradb_nfe/apps/api
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
sudo systemctl restart iqradb-scraper    # picks up new job code
sudo systemctl reload php8.2-fpm
```

**Restarting the worker is not optional.** `queue:work` holds the application in memory; without a restart it keeps running the previous deploy's job classes.

### Verifying the install

```bash
cd /var/www/iqradb_nfe/apps/scraper
echo '{"command":"unknown"}' | node bin/scraper.mjs
# → {"ok":false,"status":"error","errorCode":"unknown_command",...}
```

A JSON envelope back means Node, the CLI and the module graph are fine. Then use **Validate session** in the UI to confirm Chromium launches and SCDB is reachable.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| `worker_missing` | `SCRAPER_APP_PATH` is wrong, or `npm ci` was not run on the server. |
| Run stuck in `queued` | The `scraper` queue worker is not running, or `QUEUE_CONNECTION=sync`. |
| `Executable doesn't exist` | Chromium not installed for the worker's user. See step 3. |
| `session_expired` on every run | Stored state is stale. Re-record and re-upload. |
| `Step 5/8 failed: … timed out waiting for role=button named "Export"` | SCDB markup changed. Re-record that step. |
| `busy` | Another run holds the user's lock. Wait for it. |
| Import stops at `ready_for_mapping` | Expected — no adapter registered for that dataset yet. |

---

## Testing

```bash
cd apps/api    && php artisan test --filter=Scraper
cd apps/api    && php artisan test --filter=Csv
npm run -w apps/scraper test
```

The Node suite includes a real download-capture test against `apps/scraper/test/fixtures/download.html`, which exercises the `page.waitForEvent("download")` path without SCDB credentials. It skips itself when Chromium is not installed, so CI stays green without a browser. **Live SCDB authentication never goes into CI.**
