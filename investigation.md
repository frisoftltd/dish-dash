# INVESTIGATION — Hardcoded brand-color leaks in the frontend

**Read-only. No fixes.** Plugin: dish-dash (universal white-label). Surfaced on: nyarutarama,
v3.11.6. Brand colours must be dynamic (`dish_dash_primary_color` etc. → CSS vars); any raw
`#65040d` (or a derived red shade) that isn't a `var(...)` fallback renders the wrong colour
for a restaurant whose primary isn't Khana Khazana's maroon.

Raw grep output is in **`investigation-color-leaks.txt`** (1,113 lines: every `#65040d`, every
frontend hex, and every CSS-var definition).

---

## How the brand var is wired (baseline)

- **`--brand`** is the frontend brand token. It is set to the restaurant's primary colour by
  PHP inline styles:
  - `templates/page-dishdash.php:265` → `--brand: {dish_dash_primary_color}`
  - `modules/template/class-dd-template-module.php:354` and `:577` → `--brand: {primary}`
- **`--dd-brand`** is the ADMIN token (set on `<body>` by admin PHP). Admin CSS uses
  `var(--dd-brand, #65040d)` — fine.

### ⭐ ROOT / STRUCTURAL FINDING — `--brand` itself defaults to a hardcoded maroon
`assets/css/theme.css` defines, in TWO `:root`-level blocks:
- `theme.css:90` → `--brand: #6B1D1D;`
- `theme.css:125` → `--brand: #6B1D1D;` (comment right above: *"overridden by inline PHP style
  in template"*)
- (companion `--brand-dark: #160F0D;` at `:91` / `:126`)

So on any frontend surface where the PHP inline `--brand` override does **not** run, `--brand`
falls back to the hardcoded **#6B1D1D**, and every `var(--brand, …)` resolves to that maroon —
not the restaurant's colour. **This is why the `var(--brand, #65040d)` fallbacks below are
"dead" (the `#65040d` never triggers) yet the pages can still render maroon: the leak is the
`#6B1D1D` default, not the `#65040d` fallback.** Whether restaurant #2's cart/menu/reservation
pages show its colour depends entirely on whether the PHP `--brand` injection reaches those
pages — needs confirming per surface. This should be release #1.

---

## `#65040d` occurrences — leak vs legit, grouped by file

Legend: **LEAK** = raw hardcode (always maroon) · **legit** = `var(--brand|--dd-brand, #65040d)`
fallback (only used if the var is undefined; low priority, but still a hardcoded hex in source).

### assets/css/theme.css — the source of `--brand`
- `:90`, `:125` `--brand: #6B1D1D` · `:91`, `:126` `--brand-dark: #160F0D` — **LEAK (root)**.
  Not `#65040d` (a *different* maroon) — see the structural finding above.

### assets/css/menu-page.css — **5 LEAKS** (mobile menu)
- `:74` `background: #65040d;` — LEAK
- `:417` `background: #65040d;` — LEAK
- `:569` `background: #65040d;` — LEAK
- `:570` `border-color: #65040d;` — LEAK
- `:602` `background: #65040d;` — LEAK
- (+ derived-red hover: `:429` `background: #4a0209;` — LEAK, see "Secondary reds")

### assets/css/reservations.css — **2 LEAKS** + 17 legit fallbacks
- `:468` `linear-gradient(135deg, #65040d 0%, #3d0208 100%)` — **LEAK** (both stops hardcoded)
- `:598` `color: #65040d;` — **LEAK**
- `:32, :144, :164, :239, :254, :255, :288, :291, :292, :351, :377(×2), :380, :381, :402,
  :445, :462, :522` — all `var(--brand, #65040d)` — **legit** (fallback only)

### assets/css/frontend.css — **3 LEAKS** + 1 legit
- `:550` `linear-gradient(135deg, #65040d, #a00015)` — **LEAK** (both stops hardcoded)
- `:592` `border: 2px solid #65040d;` — **LEAK**
- `:593` `color: #65040d;` — **LEAK**
- `:727` `var(--brand, #65040d)` — legit

### assets/css/cart.css — **3 LEAKS**
- `:28` `--dd-cart-red: #65040d;` — **LEAK** (defines a local var TO the hardcoded maroon;
  every consumer of `--dd-cart-red` inherits the leak — fix here fixes many)
- `:618` `color: #65040d;` — **LEAK**
- `:654` `color: #65040d;` — **LEAK**
- (+ derived-red: `:338`, `:521` `background: #4a0209;` — LEAK, see below)

### assets/css/birthday.css — **2 LEAKS**
- `:34` `color: #65040d;` — **LEAK**
- `:95` `background: #65040d;` — **LEAK**

### assets/css/order-tracking.css — 0 leaks (both legit)
- `:20` `--dd-track-accent: var(--brand, var(--dd-accent, var(--dd-brand, #65040d)))` — legit
- `:209` `background: var(--brand, #65040d)` — legit

### assets/css/admin.css — ADMIN (out of "frontend" scope) — 0 true leaks
- `:301, :360, :427, :477, :478` — all `var(--dd-brand, #65040d)` — legit fallbacks.

### assets/css/reservations-admin.css — ADMIN — 0 true leaks
- `:526` `var(--dd-brand, #65040d)` — legit.

### templates/*.php — 0 `#65040d` hits (all leaks are in CSS files).

---

## Secondary brand-red shades — also LEAKS (hardcoded darker/hover/gradient reds)

These are brand-derived shades that won't adapt to the restaurant's colour:
- `#4a0209` (dark hover/active): `cart.css:338`, `cart.css:521`, `menu-page.css:429`,
  `menu-page.css:621` — **LEAK ×4**
- `#a00015` (gradient end): `frontend.css:550` — **LEAK**
- `#3d0208` (gradient end): `reservations.css:468` — **LEAK**
- `#6B1D1D` / `#160F0D`: `theme.css` `--brand` / `--brand-dark` defaults — **LEAK** (root).

There is no derived-shade CSS var today (e.g. `--brand-dark` exists but is itself hardcoded),
so a proper fix needs a brand-dark token driven from the primary (or `color-mix()` off
`--brand`).

---

## Decision-needed (flagged, NOT classified as a definite leak)

- **Accent orange `#e8832a` / `#E8832A` (~35 uses; defined `frontend.css:29 --dd-accent:
  #e8832a`, admin `--dd-primary: #E8832A`).** This is a pervasive SECONDARY colour but is
  **not** in the restaurant-configurable set (only `dish_dash_primary_color`, `_dark_color`,
  `_background_color`, `_font` exist). Either it's an intentional design constant (leave) or
  the white-label model should add a configurable accent. **Product decision required before
  touching it** — do not fix blind.

---

## Legit (NOT leaks) — neutral / semantic palette

For completeness (from the frontend hex frequency scan) — these are correct as constants and
should be LEFT alone:
- Neutral greys: `#6b7280, #9ca3af, #e5e7eb, #d1d5db, #374151, #f3f4f6, #f9fafb`
- Text/dark: `#221b19, #1a1a1a, #6e5b4c`
- Backgrounds: `#f5efe6, #fbf7f1, #eadfce, #f0ece6, #f7f0e8, #fafafa, #ffffff`
- Status colours: red `#991b1b / #c0392b / #fee2e2`, green `#166534 / #065f46 / #dcfce7`,
  amber `#92400e`, WhatsApp `#25d366`.
  (These convey meaning, not brand — keep hardcoded.)

---

## Leak tally (frontend only)

| File | Raw `#65040d` leaks | Secondary-red leaks | Legit `var()` fallbacks |
|---|---|---|---|
| theme.css | — (`--brand:#6B1D1D` root leak ×2 + `--brand-dark` ×2) | — | — |
| menu-page.css | 5 | 2 (`#4a0209`) | 0 |
| reservations.css | 2 | 1 (`#3d0208`) | 17 |
| frontend.css | 3 | 1 (`#a00015`) | 1 |
| cart.css | 3 (incl. `--dd-cart-red`) | 2 (`#4a0209`) | 0 |
| birthday.css | 2 | 0 | 0 |
| order-tracking.css | 0 | 0 | 2 |
| **Frontend total** | **15** | **6** | **37** |
| admin.css / reservations-admin.css (admin) | 0 | 0 | 6 |

---

## Recommended fix order (one file per release, per workflow)

1. **theme.css — the root.** Decide the `--brand`/`--brand-dark` default (neutral placeholder,
   or ensure the PHP `--brand` injection runs on EVERY frontend surface). Confirm the injection
   coverage first — this likely fixes the most visible leakage in one move.
2. **menu-page.css** (5+1) — highest raw-leak count, primary ordering surface.
3. **cart.css** (3+2) — includes `--dd-cart-red` (fix the local var → cascades).
4. **frontend.css** (3+1) — homepage/menu shared.
5. **reservations.css** (2+1) — mostly already var-driven; fix the 2 raw + 1 gradient.
6. **birthday.css** (2) — small, isolated.
7. Convert the remaining `var(--brand, #65040d)` fallbacks to a neutral/no-op fallback (cosmetic,
   low priority) once `--brand` is guaranteed defined.
8. **Accent `#e8832a`** — only after the product decision above.

Each fix = replace the raw hex with `var(--brand, …)` (and introduce a real `--brand-dark` /
`color-mix()` token for the darker shades) so the surface tracks `dish_dash_primary_color`.

**STOP — read-only. Awaiting the implementation brief (one file per release).**
