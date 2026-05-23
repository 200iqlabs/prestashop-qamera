# Local development with Docker

Two PrestaShop versions are supported in parallel via separate compose files.
Pick the one matching the PrestaShop release you're targeting.

## PS 9.x

```bash
docker compose -f docker/docker-compose.ps9.yml up -d
```

- Storefront: <http://localhost:8080/>
- Back office: <http://localhost:8080/admin-dev>
  (admin password is set on first install — see compose `ADMIN_PASSWD`)
- The module source is mounted at `/var/www/html/modules/qameraai`, so edits
  on the host show up after refreshing the page.

## PS 8.x

```bash
docker compose -f docker/docker-compose.ps8.yml up -d
```

Same shape, port `8081`.

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
