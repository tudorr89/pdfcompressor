# AGENTS.md — pdfcompressor

Laravel PDF-compression API: Laravel 13, Filament 5, PHP 8.4, FrankenPHP/Octane,
Bun + Vite, MySQL, Redis, Horizon, optional ClamAV scanning.

Deployment hosts, IPs, paths and the private registry are deliberately **not**
kept in this public repository. They live in:

- the private infra docs (application deployments runbook), and
- the local-only `AGENTS.local.md` (gitignored).

## Build & deploy (summary)

```bash
# on the build host
bash build-app.sh production        # builds linux/amd64 and pushes the image

# on the deploy host
docker compose pull app && docker compose up -d app
```

`build-app.sh` and `.env.production` are gitignored — copy them to a new build
host before building. Use a persistent `docker-container` BuildKit builder so
the PHP-extension/composer/Bun layers are reused (app-only change ≈ 25s).

The image bakes `.env.production`; the deployment's compose file may mount a
runtime `.env` over it. If it does, edit that file (keep a backup) and restart
the app container — the entrypoint re-caches config on boot.

## Runtime facts

- Healthcheck probes **`/up`** every 30s (served by an Octane worker). Never
  reintroduce `artisan octane:status` on a short interval.
- Supervisor socket/pid live in `/tmp` (`supervisor.sock`, `supervisord.pid`)
  because supervisord runs as the unprivileged app user.
- `start-container` runs `optimize:clear` **before** `optimize`; `route:cache`
  throws a `CompiledRouteCollection` error when a route cache already exists,
  which crash-loops `restart: always` containers.
- PHP 8.4 **JIT is disabled** (`opcache.jit=off`): it segfaults when the amd64
  image runs under Rosetta and brings nothing to this IO-bound app.
- Queue timeouts must stay ordered: job 900s → Horizon worker 960s → Redis
  `retry_after` 1020s. AV timeout `AV_TIMEOUT=300`.
- Cloudflare's proxied request-body limit is 100MB on Free/Pro; the API itself
  accepts up to 500MB (php.ini + Caddy `request_body`).

## PDF compression

`app/Services/PdfService.php` runs Ghostscript with:

- `GS_PDF_SETTINGS=/ebook`, `GS_IMAGE_RESOLUTION=120`, `GS_RECOMPRESS_IMAGES=true`
- `-dPassThroughJPEGImages=false` — the big win on scanned PDFs
- bicubic downsampling, CCITT G4 for 1-bit images, duplicate-image detection,
  font subsetting, optional `/Info`/`/ID`/XMP metadata stripped, PDF 1.5.

Measured: 157MB image-only PDF → ~4MB (97%), 1.4MB scan → 213KB (85%).
`/screen` + 72dpi is the aggressive profile; `0` resolution keeps the preset.

## Virus scanning (ClamAV)

Scanning happens **inside `CompressPdfJob`** (streamed upload, asynchronous),
never in the HTTP request. The scanner is an HTTP wrapper around clamd
(`AV_URL`, endpoints `POST /scan` + `GET /health`, see the `clamav-api`
project). clamd defaults reject files >100MB via INSTREAM — its compose file
raises `CLAMD_CONF_*` (550M stream/file, 1G scan). Scanner errors are retried;
"infected" is terminal and deletes the upload.

## Landing page (Cloudflare)

`static_page.html` is the source of the public landing page, which is a
dashboard-managed Cloudflare deployment (not this container).

- Upload it as **`index.html`** — the filename is the URL path; uploading
  `static_page.html` publishes `/static_page`.
- The root `/` may be Worker-intercepted: unknown query strings can 404,
  `/index.html` can 404, and the response is cached with `s-maxage=604800`.
  Purge the zone cache after uploading and check the Worker route if `/` does
  not change.

## Quality

```bash
php artisan test     # 31 tests
composer lint        # Larastan (level 5) + PHPMD 3
```

## Logs

App logs live under the bind-mounted storage directory. To clear them: stop the
app container, delete the log files, then `docker compose up -d
--force-recreate app` (this also resets the container's Docker logs).
