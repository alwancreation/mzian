# Providers, templates and catalog

Everything external goes through an interface with at least two implementations: a
**mock** (offline, deterministic, never spends money) and a real one or a documented place
to add it. A provider is configured data (table `provider`, Admin > Providers); a
**driver** is the PHP class implementing the interface.

## Provider types

| Type | Interface | Drivers in the repository | Configured providers (`config/mzian/providers.yaml`) |
| --- | --- | --- | --- |
| `ai` | `App\AI\AIProviderInterface` | `mock`, `openai`, `claude` | `mock_ai` (default), `openai`, `claude` |
| `payment` | `App\Billing\Payment\PaymentProviderInterface` | `mock`, `stripe`, `manual` | `mock_payment`, `stripe`, `bank_transfer` |
| `hosting` | `App\Hosting\Provider\HostingProviderInterface` | `mock` | `mock_hosting` (+ 3 hosting plans) |
| `domain` | `App\Domain\Provider\DomainProviderInterface` | `mock` | `mock_domain` (+ TLD prices) |
| `repository` | `App\Development\Repository\RepositoryProviderInterface` | `local_git`, `github` | `local_git` (default), `github` |
| `deployment` | `App\Deployment\Provider\DeploymentProviderInterface` | `local` | `local_apps` (deploys to the `apps` container) |
| notification channel | `App\Notification\Provider\NotificationProviderInterface` | e-mail (Symfony Mailer, `MAILER_DSN`) | code-level, no admin entry |

Real hosting, registrar and deployment drivers are deliberately not shipped: plug the
providers you contract with (Hostinger, OVH, Namecheap, Gandi, Hetzner, Forge…) following
[Add a provider](#add-a-provider). Until then the mocks are used and the projects are
flagged **simulated** (customer and admin can see it; QA skips HTTPS/DNS checks and says why).

## Which provider is used

`ProviderRegistry::resolve(type, project)`:

1. the project's override (Admin > Projects > Providers — only for a step that has not run yet);
2. otherwise the **default** enabled provider of the type;
3. otherwise the enabled provider with the highest priority.

No enabled provider → the operation stops and the project waits for an administrator.

Environment shortcuts used by `mzian:setup` when importing: `MZIAN_AI_PROVIDER`
(`mock|openai|claude`), `MZIAN_PAYMENT_PROVIDER` (`mock|stripe`), `MZIAN_REPOSITORY_PROVIDER`
(`local_git|github`).

## Credentials

Secrets are never stored in `providers.yaml`, in provider settings or in the repository.
`CredentialVault::get(provider, name)` resolves, in order:

1. an **encrypted credential** saved in Admin > Providers (write-only form: encrypted with
   libsodium as soon as it is submitted, never displayed again, rotation audited with a
   fingerprint only);
2. the environment variable named in the provider settings, e.g.
   `"env": {"api_key": "OPENAI_API_KEY"}`.

Provider settings (non-secret JSON: base URL, region, model, prices…) are validated on save:
anything that looks like a secret is refused.

| Provider | Credentials | Environment fallback |
| --- | --- | --- |
| `openai` | `api_key` | `OPENAI_API_KEY` (+ `OPENAI_MODEL`) |
| `claude` | `api_key` | `ANTHROPIC_API_KEY` (+ `ANTHROPIC_MODEL`) |
| `stripe` | `api_key`, `webhook_secret` | `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` |
| `mock_payment` | `webhook_secret` | `MZIAN_WEBHOOK_SECRET` |
| `github` | `token`, `organization` | `GITHUB_TOKEN` (fine-grained: repository administration + contents), `GITHUB_ORGANIZATION` |

### Going live checklist

- **AI**: set `OPENAI_API_KEY` or `ANTHROPIC_API_KEY` (or store it in Admin > Providers),
  enable the provider and make it default. Check `price_per_million_tokens` in its settings.
- **Payments**: Stripe secret key + webhook secret; webhook endpoint
  `https://<your-domain>/webhooks/payment/stripe` (events `checkout.session.completed`,
  `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`,
  `checkout.session.expired`, `charge.refunded`). Disable `mock_payment` (it refuses to run
  in production unless `MZIAN_ALLOW_MOCK_PAYMENTS=1`).
- **Repositories**: `MZIAN_REPOSITORY_PROVIDER=github` + token; one private repository
  `mzian-client-<id>` per project.
- **Hosting / domain / deployment**: add drivers for your providers (below), configure them,
  disable the mocks.

## Mock providers

- Deterministic, persisted in `mock_provider_resource`: the same idempotency key always
  returns the same resource (retries never create a second hosting or domain).
- `MZIAN_MOCK_LATENCY_MS` adds a delay per call (1200 ms in the Docker dev stack) so the
  pipeline can be watched.
- Failure simulation from the provider settings (Admin > Providers > Settings):
  `{"simulate_failures": {"create_account": 2}}` fails the first two attempts (transient),
  `{"simulate_failures": {"register": "permanent"}}` always fails. Operations:
  hosting `create_account`, `create_site`; domain `register`, `configure_dns`;
  payment `checkout`, `refund`.

## Add a provider

Example: a real hosting provider "Acme Hosting".

1. **Driver** — implement the interface; it is registered automatically (autoconfigured tag):

   ```php
   namespace App\Hosting\Provider;

   final class AcmeHostingProvider implements HostingProviderInterface
   {
       public function __construct(private HttpClientInterface $http, private CredentialVault $vault) {}

       public static function getDriver(): string { return 'acme'; }

       public function provisionAccount(Provider $provider, HostingPlan $plan, Project $project, string $idempotencyKey): HostingProvisioning
       {
           $token = $this->vault->get($provider, 'api_token')
               ?? throw new ProviderNotConfiguredException('Acme API token missing (Admin > Providers).', $provider->getCode());
           // 1. look the account up by $idempotencyKey first (or send it as the API's
           //    Idempotency-Key header): a retry must return the existing account;
           // 2. create it otherwise; map 5xx/timeouts to ProviderException::transient(),
           //    4xx/refusals to ProviderException::permanent();
           // 3. return the account with its real cost (minor units) and simulated: false.
       }

       public function createSite(/* ... */): HostingSite { /* same rules */ }
   }
   ```

2. **Configuration** — declare it in `config/mzian/providers.yaml` (non-secret settings only,
   `env` for the fallback variable names) with its plans and prices, then run
   `php bin/console mzian:setup` (it creates the providers that do not exist yet and never
   overwrites the ones edited in the admin). Prices are major units of the platform currency.

   ```yaml
   - code: acme
     type: hosting
     driver: acme
     name: "Acme Hosting"
     provisioning_method: api
     enabled: false
     settings: { base_url: "https://api.acme.example", region: "eu", env: { api_token: ACME_API_TOKEN } }
     capabilities: [create_account, ssl, email]
     hosting_plans:
       - { code: acme-s, name: "Acme S", price: 48, billing_period: year, specs: { storage_gb: 20, databases: 2, email_accounts: 10 }, capabilities: [ssl, email] }
   ```

3. **Credentials** — Admin > Providers > Acme Hosting: store `api_token` (or set
   `ACME_API_TOKEN` on the server), enable it, make it default.
4. **Tests** — a unit/integration test with `MockHttpClient` covering success, idempotent
   retry, transient and permanent errors (see `tests/Integration/Billing/StripePaymentProviderTest.php`).

Rules for every driver: never log secrets or full API payloads containing them, pass the
idempotency key to the remote API, return real costs so that the budget rules apply, keep
credentials returned by the provider (passwords) inside DTOs with masked `__debugInfo()`
and store them only through `CredentialService` (encrypted).

## Add an application template

Templates live in `resources/application-templates/<name>/` and share:

- `_engine/`: the PHP/SQLite administration engine of every generated application;
- `_site/`: Twig layout, macros, pages (home, about, contact, legal, collection, form, shop,
  checkout, gallery, reviews, 404) and assets;
- `modules.yaml`: entities (fields `name:type`, `name:type!` required, `select(a,b)`), public
  lists/forms, and the mapping catalog feature → implementation status
  (`implemented`, `configuration`, `custom`);
- `i18n.yaml`: fr/en/ar labels.

To add one:

1. Create `resources/application-templates/<name>/template.yaml` (copy `restaurant/`):
   name, version, description, theme colors, `base_features`, hero texts, highlights, opening
   hours and `seed` data per entity, all in fr/en/ar.
2. Add any new entity or feature to `modules.yaml` and its labels to `i18n.yaml`. A feature
   not implemented must be declared `custom` (it is then priced as custom development and
   never presented as delivered).
3. Reference the template from a solution (`application_template: <name>` in `catalog.yaml`).
4. Run `php bin/phpunit tests/Integration/Development/ApplicationGeneratorTest.php`: every
   template is generated and must pass the static checks and the HTTP smoke tests.

## Add a solution, sector or question

The catalog is data: `config/mzian/catalog.yaml` (sectors, solutions with base price,
features with option prices, rules `solution_rules` used by the rule-based analyzer,
subscription plans) and `config/mzian/questionnaire.yaml` (dynamic questions, conditions,
mapping answers → features).

1. Edit the YAML (all texts in fr/en/ar), or use Admin > Solution Catalog (solutions,
   features, sectors, questions).
2. `php bin/console mzian:setup` imports what does not exist yet (idempotent, never
   overwrites admin changes); `php bin/console mzian:catalog:import --update` re-applies the
   YAML to existing records as well.
3. Check the "starting at" price in Admin > Pricing and the public pages
   (`/fr/solutions/...`, sitemap). Run the test suite.
