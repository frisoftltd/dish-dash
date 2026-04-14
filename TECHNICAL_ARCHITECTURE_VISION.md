# 🏗 Dish Dash — Technical Architecture Vision

> Last updated: v3.1.13 (2026-04-14)
> Status: Current stack is PHP/WordPress monolith. Hybrid Python microservice planned for Phase 8+.

## 🎯 Executive summary

Dish Dash is built as a **WordPress plugin today** and will evolve into a **hybrid PHP + Python architecture** when AI features require it. This document captures the long-term technical vision so every development decision aligns with where we're going — not just where we are.

**Core principle:** Don't rewrite what works. Augment with microservices only when the business justifies the complexity.

---

## 🏛 Current Architecture (v3.1.x — Phase 1-3)
┌─────────────────────────────────────────────────────────┐
│  WordPress + Dish Dash Plugin (monolith)                │
│  ─────────────────────────────────────                  │
│  • Menus, cart, checkout, reservations                  │
│  • Admin panels for restaurant owners                   │
│  • User auth, sessions, payments                        │
│  • Behavior event tracking → wp_dishdash_user_events    │
│  • MySQL database (shared with WordPress)               │
│  • Hosted on cPanel (server347.web-hosting.com)         │
└─────────────────────────────────────────────────────────┘

**Stack:**
- PHP 8.0+
- WordPress 6.0+
- WooCommerce (payment + product catalog)
- MySQL (WordPress default)
- LiteSpeed Cache
- cPanel hosting

**Strengths for current phase:**
- Fast to ship
- Restaurant owners already know WP admin
- Cheap hosting
- Mature ecosystem

**Weaknesses to plan for:**
- PHP is slow for ML inference
- MySQL struggles with deep analytics queries
- No native async processing
- Hard to scale horizontally
- No real-time capabilities (WebSockets, streaming)

---

## 🚀 Target Architecture (Phase 8+)
┌─────────────────────────────────────────────────────────┐
│  FRONTEND & CORE BUSINESS LOGIC                         │
│  WordPress + Dish Dash Plugin                           │
│  ─────────────────────────────────────                  │
│  • All UI (customer + admin)                            │
│  • Orders, payments, reservations                       │
│  • Restaurant CMS                                       │
│  • User authentication                                  │
│  • Event tracking (fires to AI service)                 │
└────────────────┬────────────────────────────────────────┘
│ HTTP / REST API
▼
┌─────────────────────────────────────────────────────────┐
│  AI BRAIN (Python microservice)                         │
│  ─────────────────────────────────────                  │
│  • Behavior analysis                                    │
│  • Recommendations engine                               │
│  • Demand forecasting                                   │
│  • Voice / NLP search                                   │
│  • Image recognition (menu photos → tags)               │
│  • Claude API integration (conversational assistant)    │
│  • Anomaly detection (fraud, unusual orders)            │
└─────────────────────────────────────────────────────────┘
▲
│ Event stream
│
All tracking events from Dish Dash

**One-way data flow: WordPress → Python. Python never writes to WordPress database.**

---

## 🧩 Responsibility Split

### ✅ STAYS in WordPress/PHP (forever)

| Domain | Why |
|---|---|
| User authentication & sessions | WP handles this perfectly |
| Restaurant admin UI | WP admin is familiar and reliable |
| Menu management (products, categories) | WooCommerce is proven |
| Cart, checkout, payment processing | Payment integrations mature in WP |
| Order lifecycle | Simple CRUD, no ML needed |
| Email/SMS notifications | WP has robust plugins |
| Reservation booking UI | Form-heavy, WP excels |
| Static content (about, contact, FAQ) | Obvious |

### 🚀 MOVES to Python (at Phase 8)

| Domain | Why Python |
|---|---|
| Behavior event analysis | numpy/pandas crush PHP for analytics |
| Recommendation engine | scikit-learn, collaborative filtering libraries |
| Demand forecasting | Time-series models (Prophet, ARIMA) |
| Voice search | Whisper, Claude API |
| Personalization rules engine | Async, fast, complex logic |
| Anomaly/fraud detection | ML-native |
| Customer segmentation | Clustering algorithms |
| Dynamic pricing suggestions | Real-time optimization |
| Natural language chatbot | Claude API (Anthropic SDK is Python-first) |
| Image analysis (menu photo auto-tagging) | PyTorch, computer vision |
| Multi-branch aggregate reports | Heavy DB queries |

---

## 🏗 Python Stack (when we get there)

| Layer | Technology | Purpose |
|---|---|---|
| API framework | **FastAPI** | Async, fast, auto-docs |
| ML framework | **PyTorch** / **scikit-learn** | Models |
| Database | **PostgreSQL** | Analytics-grade queries |
| Cache/queue | **Redis** | Sub-ms responses |
| Async tasks | **Celery** | Background model training |
| LLM | **Anthropic Claude API** | Conversational features |
| Deployment | **Docker** on **Hetzner** or **DigitalOcean** | Not cPanel |
| Monitoring | **Sentry** + **Grafana** | Observability |

**Estimated infrastructure cost for 10 restaurants:** $20–40/month.

---

## 🗓 Migration Triggers — WHEN to build the Python service

Do NOT migrate early. Build the Python microservice when ANY of these are true:

- [ ] 1,000+ active users monthly
- [ ] 5+ restaurants on the platform
- [ ] WordPress dashboard queries consistently exceed 500ms
- [ ] Real-time recommendations required (<100ms response)
- [ ] Enough data to train a useful model (3+ months, 1,000+ orders)
- [ ] Budget to maintain two codebases
- [ ] A developer available who knows Python + DevOps

Until those conditions are met: **stay in PHP.** Premature microservices kill startups.

---

## 🎯 What to do TODAY to prepare for the future migration

Three disciplines, zero cost, massive future payoff:

### 1. Maintain clean event schemas
The `wp_dishdash_user_events` table is already structured like something Python will consume directly. Keep it that way:
- Consistent field names
- JSON metadata that follows documented schemas
- No dumping unstructured blobs

### 2. Build an internal API layer in the plugin
Create `dishdash-core/class-dd-api.php` that wraps ALL data access:

```php
DD_API::get_product($id)             // Not wc_get_product directly
DD_API::get_user_events($user_id)    // Not direct $wpdb calls
DD_API::get_popular_products()       // Abstracted logic
DD_API::get_recommendations($user)   // Today: simple rules. Future: calls Python.
```

When Python needs data later, we expose these methods as REST endpoints in two lines each. No rewriting.

### 3. Version event schemas
Add a `schema_version` column to `wp_dishdash_user_events`. When metadata shape changes, bump the version. Python imports handle v1 vs v2 cleanly.

---

## 🚨 Honest warnings

1. **Two codebases = 2x maintenance cost.** Only worth it at scale.
2. **Python devs cost more than PHP devs in Africa.** Hire accordingly.
3. **Python deployment is harder than cPanel.** Docker, CI/CD, monitoring, SSL — real DevOps work.
4. **Never rewrite what works.** The PHP side serves 90% of requests forever. Python handles the 10% that's AI-heavy.
5. **Start Python as a SIDE service, not a replacement.** Augment, don't rewrite.

---

## 💼 Competitive positioning

Most African restaurant tech falls into 3 buckets:
- **Foreign SaaS (Uber Eats, Glovo):** 30% commission, restaurants hate them
- **Basic WordPress/Shopify templates:** No intelligence, generic
- **Custom PHP builds:** Hit scaling walls at exactly the point we're planning for

**Dish Dash with a Python AI brain is positioned to be the only locally-built, intelligent, commission-free ordering platform in East Africa.** That's a defensible moat — but only if executed in phases.

---

## 🎬 Phased Execution Plan

| Phase | Stack | Goal |
|---|---|---|
| Phase 1-3 (current) | PHP/WP only | Foundation, cart, template system |
| Phase 4-5 | PHP/WP only | Delivery, multi-branch |
| Phase 6-7 | PHP/WP only | Reservations, POS |
| **Phase 8 (TRIGGER POINT)** | **+ Python microservice** | **Analytics + basic recommendations** |
| Phase 9 | Hybrid | Loyalty, QR — AI-personalized |
| Phase 10 | Hybrid mature | SaaS platform, full AI suite |

---

## 🔗 Related documents

- `ARCHITECTURE.md` — Current codebase structure (URL → file mapping)
- `CSS_REGISTRY.md` — CSS class registry
- `MODULE_CONTRACT.md` — Module dependencies and isolation rules
- `TRACKING_ROADMAP.md` — Behavior tracking expansion plan

---

## 📌 Decision log

| Date | Version | Decision |
|---|---|---|
| 2026-04-14 | v3.1.13 | Hybrid architecture adopted as long-term vision. Python microservice deferred until Phase 8. PHP monolith continues through Phase 7. |
