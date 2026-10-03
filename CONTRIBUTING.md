# Contributing

## Branch strategy

```
main ─────●──────────────●──────────── production, every commit deployable, tags vX.Y.Z
           \            / (release merge, tag)
develop ────●────●────●──────────────── integration, deployed to staging
             \  /  \  /
   feature/*  ●     ●                   new features (from develop)
   fix/*            ●                   bug fixes (from develop; hotfix/* from main if urgent)
```

| Branch | From | Merges into | Rules |
| --- | --- | --- | --- |
| `main` | — | — | protected: pull requests only, `Tests` and `Build` green, 1 review |
| `develop` | `main` | `main` (release) | protected: pull requests only, `Tests` green |
| `feature/<short-name>` | `develop` | `develop` | one feature per branch |
| `fix/<short-name>` | `develop` | `develop` | one fix per branch, with a regression test |
| `hotfix/<short-name>` | `main` | `main` and `develop` | production emergencies only |

Releases: open a pull request `develop → main`, merge, tag `vX.Y.Z` (semantic versioning).
The tag builds the images and deploys to production after the environment approval
([DEPLOYMENT.md](DEPLOYMENT.md#cicd-github-actions)).

## Commits and pull requests

- Conventional commits: `feat:`, `fix:`, `docs:`, `ci:`, `refactor:`, `test:`, `chore:`.
- Small, reviewable pull requests describing **why**; link the issue.
- Never commit `.env.local`, `app.env`, keys, tokens, passwords or customer data
  (CI scans every commit; a leaked secret must be revoked, not just deleted).
- A database change ships with its migration (backward compatible, see
  [DATABASE.md](DATABASE.md#migrations)).

## Before pushing

```bash
docker compose exec php vendor/bin/php-cs-fixer fix
docker compose exec php vendor/bin/phpstan analyse
docker compose exec php php bin/phpunit
docker compose exec php php bin/console lint:twig templates
docker compose exec php php bin/console lint:container
```

## Code conventions

- Symfony best practices, PHP 8.3, strict types, `final` and `readonly` by default, PHPStan
  level 6, `@Symfony` code style.
- One module per business capability (`src/<Module>`); modules talk through services and
  events, external services only through provider interfaces with a mock implementation.
- No business value hardcoded: prices, limits, texts and catalog live in settings, YAML or
  the database.
- Money in minor units with a currency; dates immutable, UTC.
- Every state change of a project goes through `ProjectStateMachine`; every important
  action is audited; secrets go through `CredentialVault` / `CredentialService` only.
- Every external operation is idempotent (idempotency key) and every spending goes through
  `BudgetGuard`.
- User-facing texts in `translations/` (fr, en, ar — RTL supported); admin is in English.
- Never present an unimplemented feature as working: use a provider interface + mock +
  clear "simulated"/"not available" state, and document the gap.

## Tests

| Suite | Location | Scope |
| --- | --- | --- |
| Unit | `tests/Unit` | pure logic (pricing, signatures, permissions, state machine integrity…) |
| Integration | `tests/Integration` | services with the container and SQLite: payments, approval, providers, generator (all templates are generated and checked), **the whole agent pipeline** |
| Functional | `tests/Functional` | HTTP: public pages, funnel, checkout, customer area, admin, API |

New code comes with tests; a fix comes with a test that fails without it.
