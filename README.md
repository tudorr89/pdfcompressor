## PDF Compressor API
Simple Laravel API + ghostscript extension. Nothing fancy, just plug and play.
## Why?
Because we are full of online apps with limits or bogus privacy
## Stack
- Laravel
- Pulse
- Ghostscript (baked in docker)
- Redis
- Sqlite3 (mariadb/mysql can be used)
- Horizon
- Filament
- Docker (optional)
- Bun (frontend build)
- ClamAV (optional) see https://github.com/tudorr89/clamav-api

### Test it!
```
https://api.pdfcompressor.io
```

## Installation
Ghostscript needs to be installed via docs [here.](https://ghostscript.readthedocs.io/en/latest/Install.html) Can be skipped if using docker build.

Clone Repo
```
git clone git@github.com:tudorr89/pdfcompressor.git
```
Deps
```
composer install
bun install
```

Frontend assets are built with Bun (`bun run build`, lockfile `bun.lock`).
Env creation
```
cd pdfcompressor/ && cp .env.example .env
```
Generate encryption key
```
php artisan key:generate
```
Edit .env and adjust settings
```
php artisan migrate
```
Seed with default user/pass (optional)
```
php artisan db:seed
```

Running Horizon
```
php artisan horizon
```

### Using the API
```
curl -X POST \
  https://api.pdfcompressor.io/api/v1/pdfs/upload \
  -H 'Content-Type: multipart/form-data' \
  -F 'pdf=@/path/to/your/file.pdf'
```

Uploads are queued: the endpoint answers `202` immediately and virus scanning
(if enabled) plus Ghostscript compression run in Horizon. A virus is reported
through the status endpoint as `{"status":"failed","error":"Virus detected"}`.
The original file is deleted in that case.

### Limits and retention
- Upload: 500 MiB (PHP `upload_max_filesize=500M` / `post_max_size=512M`).
- Virus scanning is enabled with `AV_ENABLED=true`; the ClamAV API must accept
  files as large as the upload limit (see the `clamav-api` project, which
  raises clamd's `StreamMaxLength`/`MaxFileSize`).
- Compression timeout: `GS_TIMEOUT=900s` (Horizon worker timeout 960s, Redis
  `retry_after` 1020s).
- Compression profile: `GS_PDF_SETTINGS` (`/screen`, `/ebook`, `/printer`,
  `/prepress`), `GS_IMAGE_RESOLUTION` (dpi, `0` keeps the preset) and
  `GS_RECOMPRESS_IMAGES` (re-encode embedded JPEGs — the biggest win on
  scanned PDFs). Text stays vector; only images are downsampled/re-encoded.
- Documents are deleted after 24 hours by the scheduler
  (`php artisan pdfs:delete --hours=24`), only once they are `completed` or
  `failed`.

### Result
```
{"message":"PDF uploaded successfully and queued for compression","document_id":"01972c96-e946-700b-bfe4-0e1db07c15f5","status":"pending"}
```
### Get status
```
curl -X GET https://api.pdfcompressor.io/api/v1/pdfs/01972c01-df8d-7022-98db-56a3c6ff4f54/status
```
### Result
```
{"id":"01972c96-e946-700b-bfe4-0e1db07c15f5","original_filename":"skyline.pdf","original_size":"2.48 MB","status":"completed","compressed_size":"2.25 MB","compression_ratio":"9.41%","download_url":"https:\/\/api.pdfcompressor.io\/api\/\v1/pdfs\/download\/01972c96-e946-700b-bfe4-0e1db07c15f5\/compressed"}
```

### Admin filament
```
https://api.pdfcompressor.io/admin/login
```

## Docker (Optional)
Using https://github.com/exaco/laravel-octane-dockerfile for docker packaging

Build via:
```
docker build -t <image-name>:<tag> -f FrankenPHP.Alpine.Dockerfile .
```

Or build **and push** an environment image (uses a persistent BuildKit builder,
so rebuilds reuse the PHP-extension/composer/frontend layers):
```
./build-app.sh production   # bakes .env.production, pushes registry.example.com/pdfcompressor:production
```

Run image (default port 8000)
```
docker run -d -e WITH_HORIZON=true -e WITH_SCHEDULER=true -p <port>:8000 --rm <image-name>:<tag>
```

### Healthcheck
`CONTAINER_MODE=http` (default) probes Laravel's `/up` route through an
existing Octane worker every 30s. Set `HEALTHCHECK_PATH` / `OCTANE_PORT` to
override. `horizon`, `scheduler`, `worker`, `reverb` and `ssr` modes check the
matching supervisor program instead of booting Artisan.

### Static analysis & code quality
```
composer analyse   # Larastan / PHPStan (phpstan.neon)
composer phpmd     # PHP Mess Detector (rulesets.xml)
composer lint      # both of the above
```

### Note
- Scheduler command to delete pdfs older than 24H + data set via kernel
- Tweak additional ghostscript options in the PDFService class line 61
- Horizon / pulse / log-viewer available via /admin/login
- 6 req/min via AppServiceProvider
- Can make use of Clam AV if set via .env
