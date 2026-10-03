# Pricing

Prices are computed by `App\Pricing\PricingEngine`, a pure function of the costs and of the
pricing policy. **The AI never sets a price**: it recommends a solution and features; the
engine prices them. Nothing is hardcoded: every amount comes from the catalog
(`config/mzian/catalog.yaml`, Admin > Solution Catalog), the provider configuration
(hosting plans, domain prices) and the pricing policy (Admin > Pricing).

Amounts are stored in **minor units** (cents) with their currency; settings are written in
major units (`50` = 50.00).

## Formula

```
development = solution base price × complexity multiplier (low 1.0, medium 1.25, high 1.6)
cost before fees = development
                 + options chosen (feature option prices)
                 + custom features (no template) × custom_feature_price
                 + hosting (cheapest plan satisfying the analysis, first year)
                 + domain (registration price of the TLD, first year; 0 if the customer owns it)
                 + e-mail accounts beyond those included in the hosting plan × email_cost_per_account
                 + infrastructure_cost (CI, monitoring, backups)
                 + AI cost estimate (by complexity)
margin           = max(minimum_margin, target_margin, cost before fees × target_margin_rate %)
price            = (cost before fees + margin + payment_fee_fixed) / (1 − payment_fee_percent),
                   rounded up to the rounding step
payment fees     = price × payment_fee_percent + payment_fee_fixed
final margin     = price − (cost before fees + payment fees)   ≥ minimum_margin, always
```

The guarantee is strict: if fee rounding would bring the margin under the minimum, the price
is raised by one rounding step until it is not.

### Worked example (default policy)

Restaurant website (base 140, low complexity) + option "online ordering" (60), Starter
hosting (40/year), a `.com` domain (12/year):

| Line | Cost | Price shown to the customer |
| --- | ---: | ---: |
| Restaurant website (development) | 140.00 | 244.00 |
| Online ordering (option) | 60.00 | 60.00 |
| Hosting Starter (1 year) | 40.00 | 40.00 |
| Domain (1 year) | 12.00 | 12.00 |
| Infrastructure | 10.00 | — |
| AI / API usage (estimate) | 3.00 | — |
| Payment fees (2.9 % + 0.30) | 10.63 | — |
| **Total** | **275.63** | **356.00** |
| Margin | | **80.37 (22.6 %)** |
| Automation budget (cost + 15 %) | 316.98 | |

Hosting, domain, e-mail and options are shown at cost (pass-through); internal costs
(infrastructure, AI, fees) and the margin are folded into the development line. Customers
never see costs or margins (the API omits them); administrators see the full breakdown on
every quote and project.

## Pricing policy (Admin > Pricing)

| Setting | Default | Meaning |
| --- | --- | --- |
| `currency` | USD | Platform currency |
| `minimum_margin` | 50 | Guaranteed on every order |
| `target_margin` | 80 | Margin aimed for |
| `target_margin_rate` | 0 | Optional extra margin, % of cost (0 = off) |
| `payment_fee_percent` / `payment_fee_fixed` | 2.9 / 0.30 | Payment provider fees paid by Mzian |
| `infrastructure_cost` | 10 | Per project |
| `email_cost_per_account` | 2 | Per account and year, beyond the hosting plan |
| `ai_cost` | low 3, medium 5, high 8 | AI/API estimate per project |
| `complexity_multipliers` | 1.0 / 1.25 / 1.6 | Development effort |
| `custom_feature_price` | 80 | Each requested feature without a template |
| `rounding` | 1 | Prices rounded up to this step |
| `quote_validity_days` | 30 | Quote expiry |
| `budget_buffer_percent` | 15 | Automation budget = cost × (1 + buffer) |

Only super administrators change the policy; changes apply to new proposals (issued quotes
keep their price and pricing snapshot). The page also lists the resulting "starting at"
price of every solution and contains a price simulator.

## Proposal

`ProposalBuilder` assembles a priced proposal from the analysis:

- **solution and features** from the analysis (included features are free, options are
  priced, unsupported features become custom development and a project risk);
- **hosting**: the cheapest enabled plan of the hosting provider satisfying the analysis
  (storage, databases, e-mail accounts, capabilities);
- **domain**: the customer's own domain (no cost, DNS instructions at delivery), the desired
  name if available, otherwise the first available variant of the business name; solutions
  that do not need a domain run on a Mzian address;
- **subscription**: the cheapest plan covering the solution's maintenance cost (the customer
  can choose another one or none at checkout).

The quote stores a pricing snapshot (policy and inputs) so that it can always be explained.

## Subscriptions

| Plan | Monthly | Includes |
| --- | ---: | --- |
| Mzian Starter | 15 | Hosting, security updates, weekly backups |
| Mzian Business | 29 | Starter + priority support, daily backups, 1 h of changes / month |
| Mzian Pro | 49 | Business + AI assistant for content, premium features, 3 h of changes / month |

Plans live in `catalog.yaml` (`subscription_plans`) and in Admin > Pricing. The order
total covers the one-off lines; the plan is shown as "then X / month from delivery". The
`Subscription` (plan, price, period, status) is created with the paid order and activated
by the delivery agent.

> **Not automated yet:** collecting the monthly payments. Renewals are tracked
> (`current_period_end`, `isDue()`), but no job charges them: invoice and collect them
> outside the platform for now. Planned: Stripe Billing subscriptions created at checkout
> (`PaymentProviderInterface` extension) and a scheduled renewal command.

## Automation budget and spending rules

Each project gets a budget (`expected cost × (1 + budget_buffer_percent)`). Agents can only
spend within the budget **and** within the automation policy (Admin > Settings:
`max_hosting_cost`, `max_domain_cost`, `max_monthly_cost`, `require_admin_approval_above`).
Above any limit the agent stops and the project waits for an administrator, who approves
that specific spending explicitly or changes the provider. See [AI_AGENTS.md](AI_AGENTS.md#budget-and-automation-policy).
