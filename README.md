# StorePaypal

A containerized Laravel 11 + React (Inertia/Vite) e-commerce store with PayPal Sandbox checkout and a Node.js/Socket.io realtime service, orchestrated with Docker Compose.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/)
- [Docker Compose](https://docs.docker.com/compose/) v2 (the `docker compose` command)
- Host ports **8002**, **8882**, **3005**, **3302**, and **5172** free
- (Optional) PayPal Sandbox REST credentials for checkout

## Run

```bash
docker compose up -d --build
docker compose ps          # 5 services Up, mysql healthy
```

First-time setup after the containers are up (no entrypoint script runs these automatically):

```bash
docker compose exec php composer install
docker compose exec php npm install
docker compose exec php npm run build            # builds Vite assets to public/build
docker compose exec php php artisan migrate --seed --force
```

To test a payment: click **Buy Now** on a product, fill in name and email, then pay with a PayPal sandbox *Personal* (buyer) account (Developer Dashboard > Sandbox > Accounts). The admin payments page (`/admin/payments`) updates live.

## Services

| Service | Image / Build | Purpose | Port (host:container) | URL |
|---------|---------------|---------|-----------------------|-----|
| `nginx` | `nginx:1.25-alpine` | Web server / reverse proxy to PHP-FPM | 8002:80 | http://localhost:8002 |
| `php` | build `docker/php/Dockerfile` (`php:8.2-fpm` + Node 20) | Laravel 11 app (PHP-FPM) & Vite dev server | 5172:5173 | internal (Vite on :5172) |
| `node` | build `docker/node/Dockerfile` (`node:20-alpine`) | Socket.io realtime server | 3005:3000 | http://localhost:3005 |
| `mysql` | `mysql:8.0` | Database (healthchecked) | 3302:3306 | `127.0.0.1:3302` |
| `phpmyadmin` | `phpmyadmin:latest` | Database admin UI | 8882:80 | http://localhost:8882 |

Admin panel: http://localhost:8002/admin/login

## Environment

Compose sets these values directly; override in the corresponding `.env` files if needed.

**Database** (`mysql` service env in `docker-compose.yml`, mirrored in `app/.env`):

| Variable | Default |
|----------|---------|
| `MYSQL_ROOT_PASSWORD` | `rootpassword` |
| `MYSQL_DATABASE` / `DB_DATABASE` | `store` |
| `MYSQL_USER` / `DB_USERNAME` | `store_user` |
| `MYSQL_PASSWORD` / `DB_PASSWORD` | `secret` |
| `DB_HOST` | `mysql` |
| `DB_PORT` | `3306` |

**Realtime** (`node` service env + `realtime/.env`):

| Variable | Default |
|----------|---------|
| `PORT` | `3000` |
| `API_SECRET` | `realtime-secret-key` |
| `REALTIME_SERVER_URL` (Laravel side) | `http://node:3000` |
| `REALTIME_API_SECRET` (Laravel side) | `realtime-secret-key` |

**PayPal** (`app/.env`) — the store boots and renders without keys; only checkout requires them:

| Variable | Default |
|----------|---------|
| `PAYPAL_CLIENT_ID` | *(empty — set your Sandbox client id)* |
| `PAYPAL_SECRET` | *(empty — set your Sandbox secret)* |
| `PAYPAL_BASE_URL` | `https://api-m.sandbox.paypal.com` |

Get Sandbox credentials from https://developer.paypal.com/dashboard/. After editing `app/.env`, run:

```bash
docker compose exec php php artisan config:clear
```

## Volumes & persistence

Named volumes (declared in `docker-compose.yml`):

- `mysql-data` -> `/var/lib/mysql` (MySQL database)
- `vendor-data` -> `/var/www/html/vendor` (Composer dependencies)
- `node-modules-data` -> `/var/www/html/node_modules` (app Node modules)

Bind mounts:

- `./app` -> `/var/www/html` (Laravel source, on `nginx` and `php`)
- `./docker/nginx/default.conf` -> `/etc/nginx/conf.d/default.conf`
- `./realtime` -> `/usr/src/app` (realtime source, on `node`)
- anonymous volume `/usr/src/app/node_modules` (realtime Node modules)

## Common commands

```bash
docker compose logs -f                 # tail all logs
docker compose logs -f php             # tail one service
docker compose down                    # stop & remove containers (keeps volumes)
docker compose down -v                 # also delete mysql/vendor/node volumes
docker compose up -d --build           # rebuild images & restart
docker compose exec php bash           # shell into the Laravel container

# Migrate / seed (not run by any entrypoint — invoke manually)
docker compose exec php php artisan migrate --seed --force

# Frontend assets
docker compose exec php npm run build  # production build
docker compose exec php npm run dev    # Vite dev server on :5172
```

## Verify

```bash
curl -I http://localhost:8002              # store        -> 200
curl -I http://localhost:8002/admin/login  # admin login  -> 200
curl -I http://localhost:8882              # phpMyAdmin   -> 200
```

- Storefront: http://localhost:8002 — lists the seeded products (Wireless Headphones, Mechanical Keyboard, USB-C Hub, Laptop Stand, 4K Monitor, Webcam HD).
- Admin: http://localhost:8002/admin/login — log in with `admin` / `admin` (hardcoded credentials; no admin user is seeded).
- If pages return HTTP 500 or render blank, make sure `npm run build` has run. If HTML references `http://localhost:517x` (Vite dev), remove the stale hot file: `docker compose exec php rm -f public/hot && docker compose exec php php artisan optimize:clear`.

## License

MIT — see [LICENSE](LICENSE).
