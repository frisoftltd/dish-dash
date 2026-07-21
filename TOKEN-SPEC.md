# TOKEN-SPEC — Brand Identity token contract (colours + 2 fonts + accent)

**Blueprint for making every frontend brand value configurable.** Read-only spec; no code yet.
Evidence in `token-spec-inputs.txt` (fonts + options) and `investigation.md` / `inject-scope.txt`
(colours + injection scope).

Scope recap from the injection investigation: `--brand`/`--brand-dark` are already emitted from
options via **P1** (`class-dd-template-module.php:352`, `wp_add_inline_style` after theme.css,
`is_dishdash_page`) and **P2** (`:569` `inject_global_header_styles()`, `wp_head`, **all frontend
pages**). Both write a single `:root{…}` block — that block is where all tokens below will be
emitted.

---

## 1. Token table

| Token | Purpose | Current hardcoded value(s) it replaces | Brand Identity field | Default | Status |
|---|---|---|---|---|---|
| `--brand` | Primary brand — buttons, active states, CTAs, links, FAB | `#65040d` (15 raw hardcodes + ~27 `var(--brand,#65040d)` fallbacks); `theme.css:90/125 --brand:#6B1D1D`; `cart.css:28 --dd-cart-red:#65040d` | `dish_dash_primary_color` | `#65040d` | ✅ **field exists, wired** (P1+P2) |
| `--brand-dark` | Darker brand — hovers, gradient ends, footer bg | `#4a0209` (cart 338/521, menu-page 429/621); `#a00015` (frontend 550); `#3d0208` (reservations 468); `theme.css:91/126 --brand-dark:#160F0D` | `dish_dash_dark_color` | `#160F0D` | ✅ **field exists, wired** — but the darker-shade hardcodes above don't reference it yet |
| `--accent` | Secondary accent — price text, highlights, dial CTAs, chips | `#e8832a`/`#E8832A` (~35 uses); `frontend.css:29 --dd-accent:#e8832a` | **NEW** `dish_dash_accent_color` | `#e8832a` | ❌ **new field** |
| `--bg` | Page background | `P2 --dd-bg:#F5EFE6` (hardcoded); `#F5EFE6` (~24 uses) | `dish_dash_background_color` | `#F5EFE6` | ⚠️ **field EXISTS but not wired** — P2 hardcodes `--dd-bg`; the field is only read in `class-dd-hooks.php:226` (a separate body-bg usage), never in the `:root` token |
| `--text` | Body text colour | `P2 --dd-text:#221B19` (hardcoded); `#221B19` (~30 uses) | **NEW** `dish_dash_text_color` | `#221B19` | ❌ **new field** |
| `--heading` | Heading text colour | heading hardcodes (`#221B19` in `.dd-serif` headings; some brand-coloured headings) | **NEW** `dish_dash_heading_color` (see decision note) | `#221B19` | ❌ **new field (decision)** |
| `--font-heading` | Heading typeface | `'Cormorant Garamond', Georgia, serif` (~11, incl. `theme.css:170 .dd-serif`, `functions.php:88`) | **NEW** `dish_dash_heading_font` | `Cormorant Garamond` | ❌ **new field** (replaces the dead `dish_dash_font`) |
| `--font-body` | Body typeface | `'Inter', system-ui, sans-serif` (~18, incl. `functions.php:76`) | **NEW** `dish_dash_body_font` (repurpose `dish_dash_font`) | `Inter` | ❌ **new field** — current `dish_dash_font` is **DEAD** (see §2) |

**Emitted-value shape (font stacks):** the font tokens must carry a full fallback stack, not the
bare family name — e.g. `--font-heading: "Cormorant Garamond", Georgia, serif;` and
`--font-body: "Inter", system-ui, sans-serif;`. The BI field stores the family name; the injector
composes the stack (serif fallback for the heading list, sans for the body list).

**Alias note (avoid churn):** the codebase already consumes `--dd-bg` and `--dd-text` widely. Rather
than rename ~54 usages, keep them as **aliases** of the new tokens in the same `:root` block:
`--dd-bg: var(--bg);` and `--dd-text: var(--text);` (and `--dd-accent: var(--accent);`). New CSS uses
`--bg`/`--text`/`--accent`; existing CSS keeps working.

---

## 2. Brand Identity — gap analysis

**Already exist AND wired to a `:root` token:**
- `dish_dash_primary_color` → `--brand` ✅
- `dish_dash_dark_color` → `--brand-dark` ✅

**Exist but NOT wired (saved, no effect on the token block):**
- `dish_dash_background_color` — form field + save + read exist (`brand-identity.php:37/74/226`), but
  P2 emits `--dd-bg:#F5EFE6` hardcoded. Only `class-dd-hooks.php:226` reads it (separate use). ⇒ wire
  it into `--bg`.
- `dish_dash_font` — **completely dead.** Read ONLY inside `brand-identity.php` (`:75` to populate its
  own `<select>` `:247`); consumed **nowhere** — no CSS var, no template `font-family`, not in the
  Google Fonts enqueue. `font_options = ['Inter','Poppins','Roboto','Lato','Montserrat']`
  (`brand-identity.php:84`) — 5 sans families, no serif, no heading/body split. ⇒ **retire/repurpose**
  into the two-font model below.

**New fields needed:**
- `dish_dash_accent_color` (colour) → `--accent`.
- `dish_dash_text_color` (colour) → `--text`.
- `dish_dash_heading_color` (colour) → `--heading` — **DECISION:** heading colour today is mostly the
  same dark as body text (`#221B19`), occasionally brand. Options: (a) add a dedicated field; (b) skip
  it and let headings inherit `--text` or `--brand`. Recommend (b)-ish: ship `--heading` defaulting to
  `var(--text)` and only add a field if a restaurant needs a distinct heading colour — keeps the page
  smaller. Flagged for your call.
- `dish_dash_heading_font` (select, serif-first list) → `--font-heading`.
- `dish_dash_body_font` (select, sans-first list) → `--font-body` (repurpose `dish_dash_font`'s slot).

**Default mismatch to reconcile:** BI defaults `dish_dash_dark_color` to `#000000`
(`brand-identity.php:73`) while the injectors/theme default `--brand-dark` to `#160F0D`. Pick one
(recommend `#160F0D`, the design value) so an un-set install matches the intended look.

---

## 3. Font handling — the moving part colours don't have ⚠️

Making fonts configurable is **not** just emitting `--font-*` vars — the font files must actually load.
Today there are **two hardcoded loaders**, both Cormorant Garamond + Inter:
1. `theme/dish-dash-theme/functions.php:53-55` — `wp_enqueue_style('dish-dash-fonts', 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:…&family=Inter:…')`.
2. `templates/page-dishdash.php:262` — a `<link href="…css2?family=Cormorant+Garamond…&family=Inter…">`.

And **every** `font-family` in CSS is a hardcoded family string (`.dd-serif`, body, etc.) — there is
**no** `--font-*` var in use today. So the font work is **three coupled pieces** and is its own
release (or two), separate from the colour token wiring:

- **F1. Emit tokens** — add `--font-heading` / `--font-body` to the `:root` injector (P1+P2), composed
  from `dish_dash_heading_font` / `dish_dash_body_font` with proper fallback stacks.
- **F2. Consume tokens** — replace the ~30 hardcoded `font-family` declarations (theme.css `.dd-serif`
  + body, functions.php base styles, page-dishdash inline, cart/menu/reservations) with
  `var(--font-heading)` / `var(--font-body)`.
- **F3. Dynamic loader** — rewrite BOTH enqueues (functions.php + page-dishdash `<link>`, ideally
  consolidated into one option-driven `wp_enqueue_style`) to build the Google Fonts `family=` query
  from the two selected families **with their weights** (headings need 500/600/700(+italic); body
  400/500/600/700). Must fail safe if a family is unknown (fall back to Cormorant/Inter).

**Sequencing rule:** never ship a font dropdown before F3 — a selectable font that doesn't load is worse
than none. Suggested split: **Release α** = F1+F3 (tokens emitted + dynamic loader, defaults unchanged →
behaviour-neutral) then **Release β** = F2 (swap CSS to the vars) + the BI heading/body font selectors.
Colours can ship independently and first.

**Font option lists (for the new selectors):** curate two curated Google-Fonts lists, e.g.
- heading (serif-first): Cormorant Garamond (default), Playfair Display, Lora, Merriweather, EB Garamond.
- body (sans-first): Inter (default), Poppins, Roboto, Lato, Montserrat, Work Sans.
Each entry needs its Google Fonts family slug + weight set for F3.

---

## 4. Injection plan — one `:root` block, all tokens

Extend the **existing** P1 + P2 `:root` emitters (do not add a new mechanism) so both write the full
token set from options. Keep P1 (guaranteed-after-theme.css, is_dishdash_page) and P2 (all frontend
pages) in sync — the same helper should build the block for both.

```css
:root {
    /* brand */
    --brand:        {dish_dash_primary_color   | #65040d};
    --brand-dark:   {dish_dash_dark_color      | #160F0D};
    --accent:       {dish_dash_accent_color    | #e8832a};
    /* surface / text */
    --bg:           {dish_dash_background_color | #F5EFE6};
    --text:         {dish_dash_text_color       | #221B19};
    --heading:      {dish_dash_heading_color    | var(--text)};
    /* type */
    --font-heading: {stack(dish_dash_heading_font | "Cormorant Garamond", Georgia, serif)};
    --font-body:    {stack(dish_dash_body_font    | "Inter", system-ui, sans-serif)};

    /* back-compat aliases — keep existing consumers working */
    --dd-bg:     var(--bg);
    --dd-text:   var(--text);
    --dd-accent: var(--accent);
}
```

Notes:
- All values `esc_attr()`-escaped (they land in a `style`/`:root` context). Colours validate to hex;
  font names whitelist-validated against the curated lists (never emit raw user input into the stack).
- The theme.css `--brand`/`--brand-dark` defaults (`#6B1D1D`/`#160F0D`) become redundant once the block
  emits every token on every page — per the investigation **Verdict A**, delete/neutralise them.
- Recommended build order overall: **(1)** colours — extend the `:root` block with `--accent`/`--bg`/
  `--text` from options + add the BI fields, then convert the raw `#65040d`/`#e8832a`/shade hardcodes
  per file (one file per release); **(2)** fonts Release α (F1+F3); **(3)** fonts Release β (F2 + BI font
  selectors). `--heading` field only if the decision in §2 says so.

---

## 5. Quick reference — what each file needs (for later briefs)

- `admin/pages/brand-identity.php` — add fields: accent, text, (heading colour?), heading font, body
  font; repurpose/retire `dish_dash_font`; add the two curated font lists; add to the `$fields` save
  allowlist (colours) with proper sanitisation (hex for colours; whitelist for fonts).
- `modules/template/class-dd-template-module.php` — P1 (`:352`) + P2 (`:569`) emit the full token block
  from options (shared helper); wire `--bg` from `dish_dash_background_color`.
- `theme/dish-dash-theme/functions.php` + `templates/page-dishdash.php:262` — dynamic Google Fonts
  loader (F3).
- `assets/css/*.css` — F2 (font-family → `var(--font-*)`) + the colour raw-hex → `var(--…)` conversions
  (per-file, from `investigation.md`).

**STOP — read-only spec. Awaiting review, then the v3.11.7 brief (expand Brand Identity page).**
