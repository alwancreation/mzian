# REST API — `/api/v1`

JSON over HTTPS. Amounts are integers in **minor units** (`29400` = 294.00) with their
`currency`. Dates are ISO 8601. The API never returns internal costs, margins, credentials
or other customers' data.

## Authentication

Stateless Bearer tokens:

```bash
php bin/console mzian:api-token:create client@example.com --name="mobile app" --days=90
# prints the token once (mzn_…); only its hash is stored
curl https://mzian.net/api/v1/me -H "Authorization: Bearer mzn_…"
```

| Endpoints | Access |
| --- | --- |
| `GET /solutions`, `POST /ai/analyze`, `/conversations/*` | public (rate limited) |
| `/me`, `/quotes`, `/orders`, `/projects` | authenticated user (customers only see their own data) |
| `/admin/*` | administrators (`ROLE_ADMIN`); decisions are recorded with the administrator's name |

Agent and webhook identities cannot use the API to approve, resume or cancel anything:
those decisions are reserved to human administrators.

## Errors

Every error has the same shape:

```json
{ "error": { "code": 422, "message": "Validation failed.",
             "violations": [ { "property": "acceptTerms", "message": "The terms of service must be accepted." } ] } }
```

| Status | When |
| --- | --- |
| 400 / 422 | malformed JSON / validation failed (`violations`) |
| 401 | missing, invalid or expired token (`WWW-Authenticate: Bearer`) |
| 403 | authenticated but not allowed |
| 404 | not found (or not yours) |
| 409 | transition not allowed in the current state (e.g. approving an unpaid project) |
| 429 | rate limited (`Retry-After`) |

The AI being unavailable is not an error: the analysis and the chat fall back to the
rule-based engine and say so (`engine`, `fallback_used`).

Rate limits (`config/packages/rate_limiter.yaml`): 120 requests / minute per token (or per
IP when anonymous); AI analysis 10 burst then 5 per 10 minutes per IP; chat 40 messages
per 10 minutes.

## Catalog

### `GET /api/v1/solutions`

```json
{ "data": [ {
  "code": "website_starter", "slug": "site-vitrine", "name": "Site vitrine Starter",
  "description": "Un site professionnel de 5 pages, rapide et optimisé mobile.",
  "category": "website", "estimated_development_days": 2, "maintenance_price": 900,
  "sectors": ["craftsman", "association", "other"], "domain_required": true,
  "features": [ { "code": "contact_form", "name": "Formulaire de contact", "included": true, "option_price": null },
                { "code": "gallery", "name": "Galerie de réalisations", "included": false, "option_price": 2000 } ]
} ] }
```

`?locale=fr|en|ar` selects the language (default `fr`).

## AI analysis

### `POST /api/v1/ai/analyze`

```json
{ "description": "Agence de location de voitures à Marrakech, 15 voitures, réservations en ligne et contrats PDF",
  "locale": "fr", "businessName": "Atlas Cars", "city": "Marrakech",
  "businessType": "car_rental", "answers": { "has_domain": "no" } }
```

Response (validated against the analysis JSON schema and the catalog):

```json
{ "solution_type": "web_application", "solution": "car_rental_management",
  "features": ["website", "vehicle_management", "vehicle_catalog", "customer_management", "online_reservations", "contracts", "dashboard", "pdf_contracts"],
  "complexity": "low", "estimated_development_days": 6,
  "hosting_requirements": { "storage_gb": 20, "database": true, "ssl": true, "email_accounts": 5 },
  "domain_required": true, "recommendation": "Pour Atlas Cars, nous recommandons …",
  "confidence": 0.9, "unsupported_features": [], "risks": [],
  "engine": "rules", "fallback_used": false }
```

`businessType` must be a sector code of the catalog (`422` otherwise); answers are kept
only for known, visible questions of the questionnaire. The analysis is advisory: it
creates no quote. Use a conversation (below) to get a quote.

## Conversations (AI chat)

| Method | Path | Body |
| --- | --- | --- |
| `POST` | `/api/v1/conversations` | `{ "locale": "fr", "message": "Restaurant à Fès, menu en ligne et réservations" }` (message optional) |
| `GET` | `/api/v1/conversations/{token}` | — |
| `POST` | `/api/v1/conversations/{token}/messages` | `{ "content": "Oui" }` |

```json
{ "token": "<conversation-token>", "locale": "fr", "ready": false,
  "messages": [ { "role": "assistant", "content": "Bonjour ! Je suis l'assistant Mzian…" },
                { "role": "user", "content": "Restaurant à Fès, menu en ligne et réservations" },
                { "role": "assistant", "content": "Noté : restaurant / Café, à Fes… Voulez-vous recevoir des réservations de table ?" } ],
  "requirement": { "sector": "restaurant", "city": "Fes",
                   "items": [ { "key": "feature.menu", "label": "Menu en ligne", "value": true, "source": "conversation" } ] } }
```

`ready` becomes `true` when the assistant has enough information; a quote can be requested
at any time once the activity is known. The conversation token is the capability: keep it
private.

## Quotes

### `POST /api/v1/quotes` (authenticated)

```json
{ "conversation": "<conversation-token>" }
```

`201 Created`:

```json
{ "token": "<quote-token>", "number": "Q-2610-MR68F8", "status": "issued",
  "expired": false, "valid_until": "2026-11-01T21:49:17+00:00", "project": "MZ-2610-5GVMG6",
  "solution": { "code": "restaurant_website", "name": "Site Restaurant" },
  "features": ["menu", "reservation_requests", "gallery", "opening_hours", "contact_form"],
  "currency": "USD", "price": 29400, "recurring_monthly": 1500,
  "subscription_plan": "starter", "hosting_plan": "starter",
  "domain": { "mode": "register", "name": "dar-fes.com", "requested": null },
  "warnings": [],
  "lines": [ { "code": "solution", "label": "Site Restaurant", "type": "development", "price": 24200, "recurring": false },
             { "code": "hosting", "label": "Hébergement 1re année — Starter (10 GB, 1 DB, 5 e-mails)", "type": "hosting", "price": 4000, "recurring": false },
             { "code": "domain", "label": "Nom de domaine dar-fes.com (1 an)", "type": "domain", "price": 1200, "recurring": false },
             { "code": "subscription.starter", "label": "Mzian Starter — maintenance et support", "type": "subscription", "price": 1500, "recurring": true } ] }
```

`GET /api/v1/quotes/{token}` returns the same document.

## Orders

### `POST /api/v1/orders` (authenticated)

```json
{ "quote": "<quote-token>", "payment_provider": "mock_payment",
  "subscription": "starter", "accept_terms": true }
```

`subscription`: a plan code, `"none"`, or omitted (the plan of the quote). Payment providers
available: those enabled in Admin > Providers (`mock_payment`, `stripe`, `bank_transfer`).

`201 Created`:

```json
{ "number": "ORD-2610-XMHJYD", "status": "pending_payment", "project": "MZ-2610-5GVMG6",
  "quote": "Q-2610-MR68F8", "currency": "USD", "total": 29400, "recurring_monthly": 1500,
  "paid_at": null, "created_at": "2026-10-02T21:49:27+00:00",
  "items": [ { "code": "solution", "label": "Site Restaurant", "amount": 24200, "recurring": false } ],
  "payment": { "status": "pending", "provider": "mock_payment",
               "checkout_url": "https://mzian.net/fr/paiement-simule/pay-ORD-2610-XMHJYD-1", "offline": false } }
```

Redirect the customer to `payment.checkout_url` (hosted checkout: the platform never sees
card data). The order becomes `paid` when the provider's **signed webhook** arrives
(`POST /webhooks/payment/{provider}`), then the project waits for the administrator.

`GET /api/v1/orders` lists the customer's orders, `GET /api/v1/orders/{number}` returns one.

## Projects

| Method | Path | Returns |
| --- | --- | --- |
| `GET` | `/api/v1/projects` | the customer's projects (customer tokens; administrators use `/admin/approvals` and the admin) |
| `GET` | `/api/v1/projects/{reference}` | project, solution, order, deliverables (URLs) — never credentials |
| `GET` | `/api/v1/projects/{reference}/status` | status, progress, timeline |

```json
{ "reference": "MZ-2610-5GVMG6", "status": "ORDERED", "progress": 15, "terminal": false,
  "updated_at": "2026-10-02T21:49:27+00:00",
  "events": [ { "at": "2026-10-02T21:49:27+00:00", "type": "transition", "transition": "order",
                "from": "QUOTED", "to": "ORDERED", "message": "Order ORD-2610-XMHJYD placed" } ] }
```

Statuses: `DRAFT, ANALYZING, QUOTED, ORDERED, PAID, PENDING_ADMIN_APPROVAL, CHANGES_REQUESTED,
APPROVED, PROVISIONING, HOSTING_READY, DOMAIN_READY, DEVELOPMENT, TESTING, DEPLOYING, DEPLOYED,
QA, DELIVERY, COMPLETED, WAITING_ADMIN_APPROVAL, FAILED, CANCELLED` (see
[ARCHITECTURE.md](ARCHITECTURE.md#project-state-machine)).

Credentials of a delivered application are only shown in the customer area (logged in,
rate limited, audited), never through the API.

## Administration

### `GET /api/v1/admin/approvals`

```json
{ "pending": [ { "reference": "MZ-2610-5GVMG6", "status": "PENDING_ADMIN_APPROVAL", "…": "…" } ],
  "blocked": [ ] }
```

`pending`: paid projects waiting for a decision (or for the customer's answer); `blocked`:
projects held by an agent (`WAITING_ADMIN_APPROVAL`) or `FAILED`.

### `POST /api/v1/admin/projects/{reference}/{decision}`

| Decision | Body | Effect |
| --- | --- | --- |
| `approve` | `{ "message": "optional note" }` | `APPROVED`, agents start |
| `request-changes` | `{ "message": "question for the customer" }` | `CHANGES_REQUESTED`, customer notified |
| `reject` | `{ "message": "reason", "refund": true }` | `CANCELLED`, payment refunded |
| `cancel` | `{ "message": "reason", "refund": false }` | `CANCELLED` |
| `resume` | `{ "at": "DOMAIN_READY" }` (optional) | resumes a held project (default: where it stopped) |
| `retry` | `{ "at": "PROVISIONING" }` (optional) | retries a failed project |
| `pause` / `unpause` | — | stops / restarts the automation after the current job |

Spending approvals, step skips, provider and budget changes are available in the admin
interface only.

## Webhooks

`POST /webhooks/payment/{provider}` — payment provider callbacks. Every request must be
signed: Stripe `Stripe-Signature`, mock gateway `Mzian-Signature` (`t=<timestamp>,v1=<HMAC-SHA256
of "<timestamp>.<payload>">` with the provider's webhook secret). Old timestamps are
refused (replay protection), each event is processed once (a duplicate gets `200
duplicate`), and the amount and currency are checked against the order. An invalid
signature gets `401`, an event that does not match its payment `400`, an unknown provider
`404`; none of them changes anything.
