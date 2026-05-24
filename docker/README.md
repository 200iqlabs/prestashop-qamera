# Local development with Docker

Two PrestaShop versions are supported in parallel via separate compose files.
Pick the one matching the PrestaShop release you're targeting.

## First-time setup (once per clone)

The PrestaShop installer runs the module's `install()` immediately on
first boot. That code lives under `QameraAi\Module\…` (PSR-4), so Composer
dependencies must be present before `docker compose up`. From the repo
root:

```bash
docker run --rm -v "$(pwd):/app" -w /app composer:2 \
  install --no-interaction --prefer-dist --no-progress
```

Run this whenever `composer.json` changes (or run `composer install`
natively if you have PHP 8.1+ on your host).

## PS 9.x

```bash
docker compose -f docker/docker-compose.ps9.yml up -d
```

- Storefront: <http://localhost:8090/>
- Back office: <http://localhost:8090/admin-dev>
  (admin password is set on first install — see compose `ADMIN_PASSWD`)
- The module source is mounted at `/var/www/html/modules/qameraai`, so edits
  on the host show up after refreshing the page.

## PS 8.x

```bash
docker compose -f docker/docker-compose.ps8.yml up -d
```

Same shape, port `8081`.

## Manually installing the module after PS is up

The PS docker image runs `PS_INSTALL_AUTO=1` only on the first boot and
does not register user modules automatically. After the storefront is
reachable, install the module via the bundled PrestaShop console:

```bash
docker exec docker-prestashop-1 php bin/console prestashop:module install qameraai
```

You should see `Install action on module qameraai succeeded.` The
`ps_qamera_*_link` tables, the four hooks, the five `QAMERAAI_*`
configuration keys, and the hidden `AdminQameraAiConfiguration` tab are
all created on success.

## Pointing at a local Qamera AI

If you also run the `qamera-ai/saas-platform` repo locally (`pnpm dev` on
port 3000), set the API base URL on the module's configuration page to:

```
http://host.docker.internal:3000/api/v1/plugin
```

`host.docker.internal` resolves the host machine from inside the container
on Docker Desktop (Mac/Windows) and on Linux with the
`host-gateway` extra-host workaround.

## Tear down

```bash
docker compose -f docker/docker-compose.ps9.yml down -v
docker compose -f docker/docker-compose.ps8.yml down -v
```

The `-v` flag drops the named volumes so the next `up` runs a fresh install.
