# Mzian.net — AI Web Solution Platform

Mzian turns a small business' plain-language request into a delivered web application.

A visitor describes their activity (questionnaire or AI chat). The platform analyzes
the need, recommends a solution, prices it (cost + guaranteed margin, never set by the
AI), and takes the order and the payment. **An administrator approves every project**
(the only mandatory human step). Then AI agents provision the hosting, register the
domain, create the repository, generate the application from a template, test it,
deploy it, audit it, and deliver it with its credentials.

```
Visitor → AI analysis → Recommendation → Quote → Order → Payment
       → PENDING_ADMIN_APPROVAL → APPROVED (human)
       → Hosting → Domain → Development → Tests → Deployment → QA → Delivery → COMPLETED
```

## Quick start

Requirements: Docker with Compose v2. Nothing else.

```bash
git clone https://github.com/alwancreation/mzian.git && cd mzian
docker compose up -d
# optional, the php container already does it on start-up:
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

The first start installs the dependencies, builds the assets, runs the migrations and
`mzian:setup --demo` (catalog, providers, agents, demo accounts). It takes a minute or two.

| URL | What |
| --- | --- |
| http://localhost | Platform (fr / en / ar) |
| http://localhost/admin | Administration |
| http://localhost:8081 | Applications deployed by the agents (demo hosting) |
| http://localhost:8025 | Mailpit: every e-mail sent by the platform |

Demo accounts (development only, refused by `mzian:setup` in production):

| Role | E-mail | Password |
| --- | --- | --- |
| Super administrator | `admin@mzian.test` | `admin-demo-1234` |
| Customer | `client@mzian.test` | `client-demo-1234` |

### Try the whole workflow (≈ 3 minutes)

1. Open http://localhost/fr/chat, describe a business, e.g. *"J'ai une agence de location
   de voitures à Marrakech avec 20 voitures, je veux des réservations en ligne et des contrats."*
2. Answer a few questions, click **Obtenir ma proposition**, complete the business name,
   city and contact details, then **Analyser**: recommended solution, features, price,
   hosting, domain, maintenance plan.
3. **Order**: create an account, accept the terms, pay with the **simulated gateway**
   (no money, no card).
4. Log in as the administrator → **Approvals** → open the project → **Approve**.
5. Watch **Pipeline** / the project page: the worker runs the 7 agents (hosting, domain,
   development, tests, deployment, QA, delivery). The project ends `COMPLETED`.
6. Open the delivered website (link on the project page, served on port 8081), its
   administration, and the e-mails in Mailpit. The customer area shows the delivery.

Everything external is **simulated by mock providers** in development and the platform
says so (projects are flagged *simulated*): no hosting, domain or payment is bought.
The generated application, its tests, its deployment on the local `apps` server and the
QA checks are real. See [PROVIDERS.md](PROVIDERS.md) to plug real providers.

## What is real, what is simulated

| Area | Development (default) | Production |
| --- | --- | --- |
| AI analysis & chat | `mock_ai` (deterministic) + rules engine | OpenAI or Claude (JSON schema validated, rules fallback) |
| Payment | `mock_payment` (signed webhooks), bank transfer | Stripe Checkout (signed webhooks, refunds) |
| Hosting / domain | `mock_hosting`, `mock_domain` (idempotent, persisted) | your provider driver ([how to add one](PROVIDERS.md#add-a-provider)) |
| Repository | local git repositories (`var/repositories`) | GitHub (one private repository per customer) |
| Deployment | `local_apps`: real deployment on the `apps` container | your deployment driver |
| Tests & QA | real: static checks, PHP lint, smoke tests over HTTP, QA of the deployed site | same |

## Documentation

| Document | Content |
| --- | --- |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Modules, request flow, state machine, Messenger, idempotency |
| [AI_AGENTS.md](AI_AGENTS.md) | AI layer, the 7 agents, orchestrator, permissions, budget rules |
| [PRICING.md](PRICING.md) | Pricing formula, margins, subscriptions, automation budget |
| [PROVIDERS.md](PROVIDERS.md) | Providers, credentials, adding providers, templates, solutions |
| [API.md](API.md) | REST API `/api/v1` with examples |
| [DATABASE.md](DATABASE.md) | Data model and migrations |
| [SECURITY.md](SECURITY.md) | Security model and controls |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Production, CI/CD (GitHub Actions), operations |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Branch strategy, conventions, checks |

## Repository layout

```
src/
  AI/            AI gateway, providers (mock, OpenAI, Claude), requirement analyzer, chat
  Requirement/   Requirements, conversations, analysis service
  Catalog/       Sectors, solutions, features, questionnaire (configurable, imported from YAML)
  Pricing/       Pricing engine, policy, proposals
  Order/         Quotes, orders
  Billing/       Payments (mock, Stripe, bank transfer), invoices, subscriptions
  Project/       Project, state machine, approval service, events, tasks, costs
  Agent/         Orchestrator, the 7 agents, Messenger jobs, budget guard, permissions
  Hosting/ Domain/ Development/ Deployment/ Testing/ Delivery/   one module per pipeline step
  Provider/      Provider registry, encrypted credentials, mock resource store
  Notification/  E-mail + in-app notifications (async)
  Customer/ Lead/ Analytics/ Security/ Admin/ Api/ Web/ Shared/
config/mzian/    catalog.yaml, questionnaire.yaml, providers.yaml, agents.yaml
resources/application-templates/   templates used to generate the customer applications
templates/ assets/ translations/   platform UI (Twig, Tailwind, fr/en/ar)
tests/           Unit, Integration (incl. the whole agent pipeline), Functional
docker/ Dockerfile docker-compose*.yml .github/workflows/
```

## Useful commands

```bash
docker compose exec php php bin/phpunit                    # tests (SQLite, mock providers)
docker compose exec php vendor/bin/phpstan analyse         # static analysis
docker compose exec php vendor/bin/php-cs-fixer fix        # code style
docker compose exec php php bin/console mzian:setup        # idempotent (re)import of catalog, providers, agents
docker compose exec php php bin/console mzian:user:create-admin admin@example.com "Jane Admin" --super
docker compose exec php php bin/console mzian:api-token:create client@mzian.test
docker compose exec php php bin/console mzian:security:check
docker compose logs -f worker                              # the agents at work
docker compose run --rm assets                             # rebuild CSS/JS
```

The `Makefile` wraps them: `make help`, `make qa` (lints, PHPStan, code style, tests — what
CI runs), `make up-dev` (assets watch mode, Adminer on http://localhost:8080, MySQL and Redis
ports exposed through `docker-compose.dev.yml`).

## Branch strategy

`main` (production, tagged `vX.Y.Z`) ← `develop` (integration) ← `feature/*` and `fix/*`.
Every push and pull request runs the test workflow; images are built for `main`,
`develop` and tags; tags deploy to production after approval. Details in
[CONTRIBUTING.md](CONTRIBUTING.md).

## License

Proprietary — © Mzian.net.
