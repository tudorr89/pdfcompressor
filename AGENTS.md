# AGENTS.md — pdfcompressor

Project conventions discovered while operating this app. Infra-wide notes live
in `~/Projects/infra/docs/applications.md` (published at
<https://docs.rthc.cloud/applications/>).

## Deploy

| | |
|---|---|
| Host | `compressor` = **x.x.x.x** (`ssh user@x.x.x.x`) |
| App dir | `/home/user/compressor` |
| Compose | `docker compose` — service `app`, container `app` |
| Image | `registry.example.com/pdfcompressor:production` (private registry on node4) |
| Port | `8000` (also proxied via Cloudflare) |
| Mounts | `data/storage` → `storage` (files + logs), `data/.env` → `.env`, `data/database.sqlite` |

The image bakes `.env.production`, **but** the compose mount overrides it:
change `/home/user/compressor/data/.env` (keep a backup) and
`docker restart app`. The container re-caches config on boot.

## Build

| | |
|---|---|
| Host | `node2` (tailnet `x.x.x.x`), `/home/user/build/pdfcompressor` |
| Command | `bash build-app.sh production` (pushes to the registry) |
| Builder | persistent `pdfcompressor-builder` (docker-container) |
| Remote | GitHub `git@github.com:tudorr89/pdfcompressor.git` (node2 has no GitLab access) |

`build-app.sh` and `.env.production` are **gitignored** — copy them to a new
build host before building. App-only changes rebuild in ~25s; no-op builds in
~3s. The build compiles FrankenPHP + all PHP extensions; if `apk upgrade`
creeps back in, every build recompiles the extension layer (~5 min).

## Runtime facts

- Laravel 13.38, Filament 5, PHP **8.4.27**, FrankenPHP 1.13.2, Bun 1.4.2, Vite 8.
- Healthcheck probes **`/up`** every 30s (served by an Octane worker). Never
  reintroduce `artisan octane:status` on a short interval.
- Supervisor socket/pid: `/tmp/supervisor.sock`, `/tmp/supervisord.pid`.
- `start-container` runs `optimize:clear` **before** `optimize` (route:cache
  throws when a route cache already exists → crash loop with `restart: always`).
- PHP 8.4 **JIT is disabled** (`opcache.jit=off`): it segfaults under Rosetta
  on Apple Silicon and gives this IO-bound app nothing.
- Queue timeouts must stay ordered: job 900s → Horizon 960s → Redis
  `retry_after` 1020s. AV timeout `AV_TIMEOUT=300`.
- Cloudflare's proxied request limit is 100MB on Free/Pro; the API itself
  accepts 500MB (`php.ini` + Caddy `request_body`).

## PDF compression

`app/Services/PdfService.php` runs Ghostscript with:

- `GS_PDF_SETTINGS=/ebook`, `GS_IMAGE_RESOLUTION=120`, `GS_RECOMPRESS_IMAGES=true`
- `-dPassThroughJPEGImages=false` — the big win on scanned PDFs
- bicubic downsampling, CCITT G4 for 1-bit images, duplicate-image detection,
  font subsetting, `/Info`/`/ID`/XMP metadata stripped, PDF 1.5.

Measured: 157MB image-only PDF → ~4MB (97%), 1.4MB scan → 213KB (85%).
`/screen` + 72dpi is the aggressive profile; `0` resolution keeps the preset.

## Virus scanning (ClamAV)

Separate stack on **x.x.x.x:5001** (`/scan`), repo `~/Projects/clamav-api`
(`/home/user/clamav-api`). Scanning happens **inside `CompressPdfJob`**
(streamed upload, async), not in the HTTP request. clamd limits are raised via
`CLAMD_CONF_*` in that compose file (550M stream/file, 1G scan).

## Static landing page (Cloudflare)

`pdfcompressor.io` is **not** this container: it is a dashboard-managed
Cloudflare deployment. `api.pdfcompressor.io` goes through the `cloudflared`
tunnel on `compressor` → `:8000`.

- Source page: `static_page.html` in the repo root.
- Upload it to the Cloudflare deployment as **`index.html`** (the filename is
  the URL path; uploading `static_page.html` publishes `/static_page`).
- The root `/` is Worker-intercepted: `/index.html` → 404, `/?any=query` → 404,
  cached with `s-maxage=604800`. Purge the zone cache after uploading and keep
  the Worker/route in mind — a static upload alone may not change `/`.

## Quality

```bash
php artisan test     # 31 tests
composer lint        # Larastan (level 5) + PHPMD 3
```

## Logs

App logs: `/home/user/compressor/data/storage/logs` (bind mount). To clear:
`docker compose stop app`, delete the log files, `docker compose up -d
--force-recreate app` (also resets the container's Docker logs).
