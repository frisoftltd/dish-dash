# Dish Dash — SaaS Pricing Model
> Authored: May 2026 | Implemented: Phase 10

## Core Philosophy

Uber Eats rents restaurants their customers.  
Dish Dash builds restaurants their own.

We are NOT a marketplace. We are infrastructure.  
The restaurant owns their customers, their data, their brand.  
We charge for the system that makes it possible — not a cut of their revenue.

---

## Pricing Tiers (Per Restaurant)

### Tier 1 — Starter
- **Monthly base:** $0 (free during onboarding)
- **Per confirmed order fee:** $0.10 flat
- **Included:** Ordering system, WhatsApp notifications,
  basic tracking
- **Limit:** Up to 200 orders/month
- **Purpose:** Remove all friction to adoption.
  Restaurant risks nothing.

### Tier 2 — Growth
- **Monthly base:** $29/month
- **Per confirmed order fee:** $0.07 flat
- **Included:** Everything in Starter + reservations,
  customer profiles, opening hours control,
  delivery zone management
- **Limit:** Up to 1,000 orders/month
- **Purpose:** Restaurant is now dependent on the system.
  Monthly base covers our infrastructure costs.

### Tier 3 — Pro
- **Monthly base:** $79/month
- **Per confirmed order fee:** $0.05 flat
- **Included:** Everything in Growth + AI recommendations,
  smart nudges, loyalty system, advanced analytics dashboard,
  birthday engine, reorder flow
- **Limit:** Unlimited orders
- **Purpose:** High-volume restaurants.
  AI layer justifies premium.

### Tier 4 — Enterprise (Custom)
- **Pricing:** Negotiated annually
- **Included:** White-label branding, multi-branch,
  dedicated support, custom integrations
- **Target:** Hotel chains, large restaurant groups

---

## Why Flat Fee Per Order — Not Commission

| Model | 10,000 RWF order | Restaurant keeps |
|---|---|---|
| Uber Eats (25%) | 2,500 RWF fee | 7,500 RWF |
| Vuba Vuba (est. 20%) | 2,000 RWF fee | 8,000 RWF |
| Dish Dash ($0.07 ≈ 80 RWF) | 80 RWF fee | 9,920 RWF |

The pitch: "Pay less per order AND own your customers."

---

## What the Restaurant Owns (Forever)

- Customer WhatsApp numbers
- Order history
- Behavior data
- Their brand on the platform
- Their menu pricing
- Their delivery rules

If they cancel Dish Dash, they keep all of it.  
This is the trust that builds long-term retention.

---

## Revenue Projections (Internal Reference)

| Restaurants | Avg orders/month | Tier | MRR |
|---|---|---|---|
| 10 | 300 | Growth | $290 + order fees |
| 50 | 500 | Growth/Pro | $1,450–$3,950 + fees |
| 200 | 600 | Mix | ~$15,000+ MRR |

Break-even target: 20 paying restaurants on Growth tier.

---

## Phase 10 Implementation Scope

### New DB Tables Required
```sql
wp_dd_saas_tenants        -- one row per restaurant
wp_dd_saas_subscriptions  -- active tier per tenant
wp_dd_saas_order_log      -- every billable order event
wp_dd_saas_invoices       -- monthly invoice records
```

### New Modules Required
- `modules/billing/` — usage tracking, invoice generation
- `modules/tenant/` — per-restaurant config isolation
- `modules/saas-admin/` — super-admin dashboard
  (not restaurant-facing)

### Integration Points
- Billing fires on `woocommerce_order_status_completed`
  (same hook as notifications — already wired)
- Each tenant gets isolated `wp_options` namespace:
  `dd_{tenant_id}_setting_name`
- White-label: tenant uploads own logo/colors,
  overrides Dish Dash defaults

### Payment Collection (Phase 10)
- MTN MoMo API for Rwanda-based restaurants
- Stripe for international expansion
- Monthly auto-invoice via email + WhatsApp

---

## Go-To-Market Sequence

1. **Khana Khazana** — free forever (founding restaurant,
   live proof of concept)
2. **3 pilot restaurants** — Starter tier free,
   manual invoicing to test willingness to pay
3. **First 10 paying** — Growth tier, personal onboarding
4. **Automate billing** — Phase 10 full implementation
5. **East Africa expansion** — Uganda, Tanzania, Kenya

---

## Competitive Moat Summary

| Advantage | Why It Matters |
|---|---|
| Flat fee not commission | Restaurant margin protected |
| Restaurant owns customer data | No platform dependency |
| Behavior AI gets smarter per restaurant | Compounds over time |
| Built for East Africa | MTN MoMo, WhatsApp, local UX |
| WordPress-based | Restaurants already trust WP |

---

## Notes for Phase 10 Development

- Pricing tiers stored in `wp_dd_saas_plans` table —
  NOT hardcoded. Admin can adjust without code deploy.
- Order fee charged in USD internally,
  invoiced in local currency at time of billing.
- Free tier has hard order cap enforced server-side —
  orders above limit show upgrade prompt, not error.
- Never block a customer order because of billing failure —
  flag the restaurant account, notify admin only.

> Rule: A customer's order is never the restaurant's fault.
> Billing failures are between Dish Dash and the restaurant only.
