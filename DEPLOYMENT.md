# Deployment and operations

## Environments

| Environment | How | Providers |
| --- | --- | --- |
| Development | `docker compose up -d` (bind-mounted sources, `APP_ENV=dev`) | mocks + local git + `apps` server; e-mails in Mailpit |
| Test / CI | `php bin/phpunit` (SQLite, in-memory transports), GitHub Actions | mocks, scripted AI |
| Staging / production | immutable images from GHCR + `docker-compose.prod.yml` | real providers configured in Admin > Providers |

## Local development

```bash
docker compose up -d            # php, worker, nginx, database, redis, apps, mailpit (+ one-shot assets build)
docker compose logs -f php worker
docker compose run --rm assets  # rebuild CSS/JS after changing templates/assets
docker compose exec php php bin/phpunit
docker compose down -v          # stop and DELETE the data (fresh start)
make up-dev                     # + assets watch, Adminer (:8080), MySQL/Redis ports (docker-compose.dev.yml)
```

Local overrides go in `.env.local` (git-ignored), e.g. `HTTP_PORT=8080`,
`MZIAN_MOCK_LATENCY_MS=0`, or real provider keys to try them (`MZIAN_AI_PROVIDER=openai`,
`OPENAI_API_KEY=…`). The `php` container installs Composer dependencies when `vendor/` is
missing, waits for MySQL, runs the migrations and `mzian:setup --demo` on every start
(idempotent). Set `MZIAN_AUTO_SETUP=0` to skip it.

## Production

### Server

- Linux with Docker Engine and Docker Compose 2.24 or later (v2 or v5), 2 vCPU / 4 GB RAM to start;
- a DNS name for the platform and TLS termination in front of the `nginx` container
  (Caddy, Traefik, a cloud load balancer…) that sets `X-Forwarded-Proto/For/Host`;
- outbound HTTPS to your providers (AI, payments, GitHub, hosting/registrar APIs), SMTP.

### Configuration files (on the server, never in git)

`/srv/mzian/.env` — read by Compose (variable interpolation), `chmod 600`:

```dotenv
APP_SECRET=<openssl rand -hex 32>
MZIAN_ENCRYPTION_KEY=<php -r "echo base64_encode(random_bytes(32));">
MZIAN_WEBHOOK_SECRET=<openssl rand -hex 24>
MYSQL_PASSWORD=<openssl rand -hex 24>
MYSQL_ROOT_PASSWORD=<openssl rand -hex 24>
MZIAN_PUBLIC_BASE_URL=https://mzian.net
MAILER_DSN=smtp://user:password@smtp.example.com:587
HTTP_PORT=8080                       # the TLS proxy forwards to it
```

`/srv/mzian/app.env` — optional, passed to the `php` and `worker` containers only (the
database root password is not), `chmod 600`:

```dotenv
TRUSTED_PROXIES=private_ranges       # trust X-Forwarded-* from the TLS proxy / Docker network
MZIAN_MAIL_FROM=no-reply@mzian.net
MZIAN_ADMIN_EMAIL=ops@mzian.net
MZIAN_AI_PROVIDER=openai             # or claude
OPENAI_API_KEY=…                     # or store it encrypted in Admin > Providers (preferred)
MZIAN_PAYMENT_PROVIDER=stripe
STRIPE_SECRET_KEY=…
STRIPE_WEBHOOK_SECRET=…
MZIAN_REPOSITORY_PROVIDER=github
GITHUB_TOKEN=…
GITHUB_ORGANIZATION=…
```

Keep `MZIAN_ENCRYPTION_KEY` in a secret manager as well: losing it makes stored
credentials unreadable. The platform refuses to start with missing or development secrets
(`mzian:security:check`).

### First deployment (manual)

```bash
mkdir -p /srv/mzian && cd /srv/mzian
# copy docker-compose.yml, docker-compose.prod.yml and docker/{apps,mysql} from the release
# create .env (and app.env) as above
export MZIAN_IMAGE=ghcr.io/alwancreation/mzian/php MZIAN_NGINX_IMAGE=ghcr.io/alwancreation/mzian/nginx MZIAN_IMAGE_TAG=1.0.0
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull php worker nginx
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --no-build --wait
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/console mzian:user:create-admin you@mzian.net "Your Name" --super
```

The `php` container runs the migrations and `mzian:setup` (catalog, providers, agents — no
demo accounts in production). Then, in the admin: configure and enable the real providers,
disable the mocks, review Pricing and Settings, create the other administrators.

`apps` (the demo hosting of `local_apps`) is not started in production; enable it with
`--profile demo` only for a demonstration environment.

### Scaling and operations

- **Workers**: `docker compose … up -d --scale worker=3`. The per-project lock guarantees one
  job at a time per project; different projects run in parallel. Workers restart every hour
  (`--time-limit=3600`) and are restarted by Compose.
- **Failed messages**: `php bin/console messenger:failed:show` / `messenger:failed:retry`.
  Agent failures are handled by the orchestrator (retries, then the project waits in
  Admin > Approvals) and do not end up there.
- **Logs**: JSON on stderr (`docker compose logs`), AI usage on the `ai` channel; ship them to
  your log platform. The audit log is in the database (Admin > Logs).
- **Health**: php-fpm ping (`docker-healthcheck`), `GET /fr/` through nginx.
- **Backups**: daily MySQL dump + `var/` volumes if you use the local repository/deployment
  providers; see [DATABASE.md](DATABASE.md#backups). Test restores.
- **Upgrades**: deploy a new image tag; migrations run on start; keep them backward compatible
  so that a rollback to the previous tag keeps working.

## CI/CD (GitHub Actions)

| Workflow | Trigger | What it does |
| --- | --- | --- |
| `tests.yml` | every push to `main`, `develop`, `feature/**`, `fix/**`, pull requests | Composer validate/audit, PHP CS Fixer, PHPStan, Symfony lints, PHPUnit (incl. the whole agent pipeline), migrations on MySQL 8.4 + schema check, idempotent setup, assets build, secret scan (env files + gitleaks on the full history) |
| `build.yml` | push to `main`/`develop`, `v*` tags, pull requests | Builds the production PHP and nginx images, checks they refuse development secrets, starts the full production stack (MySQL, Redis, worker, nginx) and smoke-tests it, then publishes to GHCR (`sha-<commit>`, branch name, version and `latest` for tags) — PRs build and test only |
| `deploy.yml` | after a successful `Build` of a `v*` tag (→ production), or manually (staging/production, any tag) | SSH to the server, upload the stack definition, pull the images, pre-flight `mzian:security:check`, `up --wait`, HTTP health check, automatic rollback to the previous tag on failure |

### Setting up deployments

1. Create GitHub environments `staging` and `production` (Settings > Environments). Add
   **required reviewers** to `production`: every production deployment then waits for a
   human approval.
2. In each environment add the secrets `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`
   (a dedicated key pair; the user only needs Docker access), `DEPLOY_KNOWN_HOSTS`
   (`ssh-keyscan -t ed25519 <host>`), `DEPLOY_PATH` (e.g. `/srv/mzian`) and the variable
   `DEPLOY_URL` (e.g. `https://mzian.net`).
3. Prepare the server: Docker + Compose, `DEPLOY_USER` in the `docker` group
   (`sudo usermod -aG docker <user>`, effective on the next SSH connection), the
   `DEPLOY_PATH` directory writable by `DEPLOY_USER`, a TLS proxy, optionally `app.env`. If `DEPLOY_PATH/.env` does not exist,
   the **first deployment generates it on the server** with random secrets (never sent to
   GitHub, never printed; `MZIAN_PUBLIC_BASE_URL` = `DEPLOY_URL`, `MAILER_DSN=null://null`):
   back it up and set `MAILER_DSN` afterwards. A missing `.env` next to an existing
   database volume is refused (its passwords cannot be regenerated).
4. Images must exist: the `Build` workflow publishes them for `main` (`main`,
   `sha-<commit>`), `develop` and `v*` tags. Deploy manually with one of these tags, or
   release: merge `develop` into `main`, tag `vX.Y.Z`, push the tag → Build → Deploy (waits
   for approval) → health check.

Application secrets never transit through GitHub; the workflows only hold deployment
access. The server logs in to GHCR with the job's short-lived token in a temporary, private
Docker configuration that is deleted at the end of the job (nothing is written to
`~/.docker/config.json`).

## Troubleshooting

| Symptom | Check |
| --- | --- |
| `php` container restarts | `docker compose logs php`: database unreachable, failed migration, or "Insecure configuration" from `mzian:security:check` |
| Pipeline does not move | worker running? `docker compose logs worker`; project paused? held (`WAITING_ADMIN_APPROVAL`, reason on the project page)? |
| Agent waiting for an administrator | Admin > Approvals: reason, pending spending, retry/resume/skip controls |
| Payment stays pending | webhook URL reachable from the provider, webhook secret matches, `webhook_event` table, logs `invalid_signature` |
| E-mails not sent | `MAILER_DSN`, `messenger:failed:show` (notifications transport) |
| Wrong scheme/host in links | `MZIAN_PUBLIC_BASE_URL`, `TRUSTED_PROXIES`, proxy headers |
