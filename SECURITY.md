# Security

Mzian takes money, stores customer data and lets automated agents act on external
services. The design goal: **no automated component can do more than its job, nothing
irreversible happens without a human above the configured limits, and secrets never leave
the encrypted vault in clear.**

Report a vulnerability privately to security@mzian.net (do not open a public issue).

## Principles and where they are enforced

| Principle | Enforcement |
| --- | --- |
| A human approves every project before any automation | State machine: only the `admin` actor can apply `approve`; `ApprovalService::admin()` requires an active human administrator account; tested in `ApprovalServiceTest`, `ProjectStateMachineTest` |
| Agents never have administrator powers | `AgentPermission::FORBIDDEN` (approve, reject, cancel, resume, budget change, provider change, refund, credential reveal, user management, settings) can never be granted; `ROLE_AGENT` cannot be combined with admin roles; transitions declare their allowed actors (`ActorGuardListener`) |
| No irreversible spending above the rules without explicit confirmation | `BudgetGuard::authorize()` before every purchase: project budget + automation policy (Admin > Settings); above → `WAITING_ADMIN_APPROVAL`; the administrator approves that specific amount (audited) |
| Retries never buy twice | Idempotency keys on every operation, provider call, cost and payment; per-project lock; duplicated jobs ignored ([ARCHITECTURE.md](ARCHITECTURE.md#idempotency)) |
| Success is measured, not declared | Tests and QA really run (HTTP, PHP lint, static checks); critical failures block deployment/delivery; quality gates cannot be skipped |
| Least privilege for agents | Each agent has only the permissions of its step (Admin > Agents); disabled or under-privileged agents stop the pipeline with an explicit reason |

## Secrets

- **Never committed**: `.env.local`, `.env.*.local`, `app.env`, keys and certificates are
  ignored by git. Committed env files only contain empty values or explicit
  development/test values (`dev-only…`, `test-only…`). CI checks it
  (`.github/scripts/check-env-files.sh`) and scans the whole history with gitleaks.
- **Refused in production**: `mzian:security:check` (run by the container entrypoint and the
  deployment workflow) and a request guard refuse missing, short or development values of
  `APP_SECRET`, `MZIAN_ENCRYPTION_KEY` and `MZIAN_WEBHOOK_SECRET`.
- **Encrypted at rest**: provider credentials (Admin > Providers, write-only) and the
  credentials delivered to customers are encrypted with libsodium secretbox
  (`SodiumSecretManager`, key `MZIAN_ENCRYPTION_KEY`, authenticated encryption, random nonce).
- **Decrypted only in memory, only where needed**: `CredentialVault::get()` for a driver call,
  `CredentialService::reveal()` for the customer who owns the project (logged in, rate limited
  20/hour, audited). Provider DTOs that carry passwords mask them in `__debugInfo()`.
- **Never in logs, audit entries, e-mails or AI prompts**: audit entries store a fingerprint
  (first 8 hex of a SHA-256) when a credential changes; delivery e-mails link to the customer
  area instead of containing passwords; the AI receives business information only.
- **Generated applications**: the admin password is hashed before deployment
  (`password_hash`), the runtime `config.php` is written on the target only, never committed
  to the customer repository; tests fail if a secret or a password hash appears in the
  sources.
- **CI/CD**: application secrets stay on the server (`.env`, `app.env`, chmod 600); GitHub
  only holds deployment access (SSH key with pinned host key) in a protected environment.

Key rotation: `APP_SECRET` can be changed at any time (sessions and CSRF tokens are reset).
`MZIAN_ENCRYPTION_KEY` encrypts stored credentials: rotating it requires re-entering them
(Admin > Providers) — keep it in a secret manager and in your backup procedure.

## Authentication and authorization

- Passwords hashed with Symfony's `auto` hasher (bcrypt/Argon2id), login form with CSRF,
  **login throttling** (5 attempts / 15 minutes), sessions `HttpOnly`, `SameSite=Lax`,
  `Secure` behind HTTPS.
- Roles: `ROLE_CUSTOMER`, `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` (providers, credentials, settings,
  pricing, agents, budgets), `ROLE_AGENT` (machine identity, no UI).
- Voters: `ProjectVoter`, `CustomerResourceVoter` — a customer only reaches their own
  requirements, quotes, orders, projects and credentials; other resources answer 404/403.
- API: stateless Bearer tokens (stored as SHA-256, expiring, revocable), JSON 401/403,
  rate limited (120/min per token or IP, stricter for AI endpoints). See [API.md](API.md).
- Webhooks: every request is authenticated by an HMAC signature (`Stripe-Signature` /
  `Mzian-Signature`, timestamped, replay window), events are processed once, amounts and
  currencies are checked. Unsigned requests change nothing.

## Web application protections

| Threat | Protection |
| --- | --- |
| CSRF | Symfony CSRF tokens on every form and admin action (`CsrfGuardTrait`); generated applications have their own CSRF tokens |
| XSS | Twig auto-escaping everywhere; generated sites rendered by a sandboxed Twig environment with escaping on; no user HTML rendered raw; CSP in production |
| SQL injection | Doctrine ORM / DBAL parameters only; generated applications use PDO prepared statements |
| Clickjacking, sniffing | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS in production |
| Abuse / spam | Rate limiters: registration 5/h, lead submission 20/h, AI analysis, chat, API, credential reveal; honeypot and throttling on the generated sites' public forms |
| CSV injection | Exports of the generated applications neutralize formulas |
| Mass assignment | API payloads mapped to DTOs with explicit validation constraints |
| Secrets in provider settings | Settings validator refuses secret-looking keys and values (stored only via the encrypted credential form) |
| Information leaks | API errors never include internals (500 → "Internal server error."); customers never see costs or margins; QA checks that configuration, code and database of generated apps are not reachable over HTTP |

## Audit

`audit_log` records who (human, customer, agent, webhook, system), what (action, entity,
old/new values without secrets), when, from where (IP, user agent). Highlights: approvals,
rejections, refunds, spending approvals, budget and provider changes, settings and
pricing changes, credential storage/rotation/reveal, agent configuration changes, skipped
steps, every state machine transition (also in the project timeline). Admin > Logs.

## Dependencies and supply chain

- `composer audit` in CI; lock files committed; images built from pinned base image versions (PHP 8.3, nginx 1.27, MySQL 8.4, Redis 7.4).
- Workflows use least-privilege `permissions`, no `pull_request_target`, no secrets exposed
  to pull requests.
- The generated applications have no third-party PHP dependencies.

## Known limitations

- Hosting, registrar and deployment drivers for real providers are not shipped (mocks are
  clearly flagged *simulated*); each new driver must follow the rules of
  [PROVIDERS.md](PROVIDERS.md#add-a-provider).
- Recurring subscription payments are not collected automatically yet ([PRICING.md](PRICING.md#subscriptions)).
- The demo `apps` server (`local_apps`) serves applications over HTTP for development; use a
  real deployment provider with TLS in production.
