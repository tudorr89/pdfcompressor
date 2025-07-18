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
```
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

Run image (default port 8000)
```
docker run -d -e WITH_HORIZON=true -e WITH_SCHEDULER=true -p <port>:8000 --rm <image-name>:<tag>
```
### Note
- Scheduler command to delete pdfs older than 24H + data set via kernel
- Tweak additional ghostscript options in the PDFService class line 61
- Horizon / pulse / log-viewer available via /admin/login
- 6 req/min via AppServiceProvider
- Can make use of Clam AV if set via .env
