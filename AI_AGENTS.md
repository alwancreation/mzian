# AI and agents

Two very different uses of "AI" coexist in Mzian:

1. **Language models** understand the customer (requirement analysis, chat, website copy).
   They recommend; they never price, approve, pay or deploy.
2. **Agents** are PHP services that execute the delivery pipeline after the human approval,
   each with minimal permissions, a budget, retries and a full audit trail. They call
   providers through interfaces; they cannot declare success: technical checks decide.

## AI layer (`src/AI`)

```
business code ──► AIGateway ──► ProviderRegistry (Admin > Providers) ──► driver: mock | openai | claude
                     │
                     ├─ JSON schema validation of structured outputs
                     ├─ cost estimate (price_per_million_tokens) and usage logging (channel "ai")
                     └─ AIException on any failure (callers fall back)
```

| Use | Service | Fallback when the AI fails or answers invalid JSON |
| --- | --- | --- |
| Requirement analysis | `RequirementAnalyzer` | `RuleBasedAnalyzer` (catalog rules `analysis.solution_rules` + text signals), flagged `fallback_used` |
| Chat | `ConversationAssistant` | `MockConversationResponder` (deterministic questions from the questionnaire) |
| Website copy | `Development\Content\ContentWriter` | deterministic copy from the template |

### Requirement analysis

Input: business type, questionnaire answers, free description, locale, business name, city.
Output (validated against a JSON schema, then against the catalog — unknown solutions or
features are rejected):

```json
{
  "solution_type": "web_application",
  "solution": "car_rental_management",
  "features": ["website", "vehicle_management", "customer_management", "online_reservations", "contracts", "pdf_contracts"],
  "complexity": "medium",
  "estimated_development_days": 8,
  "hosting_requirements": { "storage_gb": 20, "database": true, "ssl": true, "email_accounts": 5 },
  "domain_required": true,
  "recommendation": "…",
  "confidence": 0.9,
  "unsupported_features": [],
  "risks": [],
  "engine": "openai",
  "fallback_used": false
}
```

Features that no template supports are listed in `unsupported_features`: they are priced
as custom development (see [PRICING.md](PRICING.md)), flagged as a risk on the project,
and visible in **Admin > AI Recommendations** (candidates for new template modules).

What the AI receives: the business description and answers only — never credentials,
payment data, internal costs or other customers' data. API keys are read by the driver
from the encrypted vault at call time ([SECURITY.md](SECURITY.md)).

Choosing a model: Admin > Providers (OpenAI or Claude, `MZIAN_AI_PROVIDER`, model via
`OPENAI_MODEL` / `ANTHROPIC_MODEL`). The mock provider is deterministic and offline.

## Agents (`src/Agent`)

| Agent | Operations (Messenger jobs) | Permissions (default) | What it does |
| --- | --- | --- | --- |
| `hosting` | `provision_hosting` | `hosting.provision`, `budget.spend`, `credentials.store` | Picks the cheapest hosting plan that fits the analysis, checks the budget, creates the account (idempotent), stores the panel credentials encrypted |
| `domain` | `register_domain` | `domain.register`, `domain.dns`, `budget.spend` | Checks availability and price, registers the domain (or records DNS instructions for a customer-owned domain), points DNS to the hosting |
| `development` | `create_project`, `generate_application` | `repository.write`, `application.generate`, `ai.content`, `credentials.store`, `budget.spend` | Creates the repository (`mzian-client-<id>`), writes the copy (AI), generates the application from its template, commits, creates the application admin account |
| `testing` | `run_tests` | `tests.run` | Static checks (structure, SEO, links, accessibility, PHP lint, no secret in sources) then starts the application and drives it over HTTP (pages, admin login, CSRF, CRUD, public forms, exposure of config/database) |
| `deployment` | `deploy_application` | `deployment.deploy`, `credentials.hash` | Deploys the tested commit, writes the runtime configuration on the target only |
| `qa` | `run_qa` | `qa.run` | Audits the deployed site over HTTP: availability, speed, content, SEO, mobile, pages, assets, admin protection, forms, security headers, HTTPS and DNS on real hosting |
| `delivery` | `send_delivery` | `delivery.send` | Writes the delivery documentation, makes the credentials available in the customer area, activates the subscription, notifies the customer |

Agents are configured in `config/mzian/agents.yaml` and managed in **Admin > Agents**
(super administrators: enable/disable, permissions, maximum attempts).

### What an agent can never do

`AgentPermission::FORBIDDEN` lists decisions reserved to humans; they can never be granted
(the entity refuses them): `project.approve`, `project.reject`, `project.cancel`,
`project.resume`, `budget.change`, `provider.change`, `payment.refund`,
`credentials.reveal`, `user.manage`, `settings.change`.

On top of permissions, the state machine only lets the `agent` actor move the automation
forward, put a project on hold, or fail it. `ApprovalService` methods check that the
caller is an active human administrator. An agent's `ROLE_AGENT` cannot be combined with
administrator roles on any account.

### Orchestrator

`AgentOrchestrator` drives an approved project, one job at a time:

1. `kick()` queues the job of the next unfinished operation (after the current DB transaction).
2. `handle()` takes the project lock, ignores stale or duplicated jobs, then `run()`:
   checks the agent is installed, enabled and has the required permissions (otherwise:
   `hold` with the reason), applies the step's start transition, records an `AgentRun`
   (logs, duration, cost) and an `AgentTask` (idempotency key, output).
3. The agent executes and returns an `AgentResult` (summary + output), or throws:
   - `NeedsAdminException` → `WAITING_ADMIN_APPROVAL` with the reason (e.g. spending above
     the rules, domain to choose with the customer, domain already used);
   - transient `AgentException` / provider error → retried with exponential backoff
     (`mzian.agent_retry_backoff_seconds`, doubled each attempt) up to the agent's maximum
     attempts, then `WAITING_ADMIN_APPROVAL` ("<operation> failed N times");
   - permanent error → `FAILED`.
4. On success the step's completion transition is applied and the next job is queued.

Quality gates: critical test failures and a failed QA (critical check failed or score below
**Admin > Settings > Minimum QA score**) stop the pipeline before delivery. They cannot be
skipped, even by an administrator.

### Budget and automation policy

`BudgetGuard::authorize()` runs before any purchase:

| Rule (Admin > Settings) | Default | Above it |
| --- | --- | --- |
| `max_hosting_cost` | 100 | agent stops, project `WAITING_ADMIN_APPROVAL` |
| `max_domain_cost` | 30 / year | idem |
| `max_monthly_cost` | 30 / month | idem |
| `require_admin_approval_above` | 100 per spending | idem |
| project budget | expected cost + 15 % | idem |

The administrator sees the pending spending on the project page and approves it
explicitly (**Approve this spending and resume**); the approval is audited and only covers
that amount. Every spending is recorded once in `project_cost_entry` (idempotency key)
and added to the step cost.

### Administrator controls

On **Admin > Projects > (project)**, **Pipeline** and **Approvals**:

- approve / request changes / reject (with refund) a paid project;
- pause / unpause the automation (agents stop after their current job);
- resume a held project or retry a failed one, at any step (later steps run again;
  operations are idempotent, nothing is bought twice);
- skip a non-critical step handled outside the platform (hosting, domain, delivery);
- approve a spending above the rules;
- change the provider of a step that has not run yet;
- change the project budget (super administrators);
- cancel (with or without refund);
- read every agent run (logs), test report (JSON `{status, score, critical_errors, warnings}`),
  timeline event and audit log entry.

The same decisions are available on the API (`/api/v1/admin/...`, see [API.md](API.md)).

## Adding an agent

1. Implement `App\Agent\AgentInterface` (tagged `mzian.agent` automatically): `getCode()`,
   `step()`, `requiredPermissions($operation)`, `execute(AgentContext): AgentResult`.
2. Declare its permissions in `AgentPermission::ALL` (never in `FORBIDDEN`) and the agent in
   `config/mzian/agents.yaml`, then run `mzian:setup`.
3. Map its operations to messages in `AgentOrchestrator::OPERATIONS` / `STEP_OPERATIONS`
   (one `AbstractPipelineMessage` subclass per operation) and, for a new step, add it to
   `PipelineStep` and the workflow definition.
4. Make every external call idempotent (derive keys with `$context->idempotencyKey()`), call
   `BudgetGuard::authorize()` before spending and `record()` after.
5. Cover it in `tests/Integration/Agent/PipelineTest.php`.
