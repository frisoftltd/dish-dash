# Investigation — Footer Dynamic / White-Label Readiness (v3.10.66)

READ-ONLY investigation. No code changed. All findings are `file:line`.

---

## A. Where the footer renders

**Plugin, not theme.** The visible footer is injected by the plugin, not the theme.

- Renderer: `modules/template/class-dd-template-module.php:852` — `inject_global_footer()`.
- Hook: `modules/template/class-dd-template-module.php:75` — `add_action( 'wp_footer', [ $this, 'inject_global_footer' ] )`.
- Theme footer is a no-op for markup: `theme/dish-dash-theme/footer.php:20` is only `<?php wp_footer(); ?>` + `</body></html>`. No visible footer markup in the theme.
- Gate: `inject_global_footer()` early-returns unless `is_global_header_page()` (`class-dd-template-module.php:853`). That method **always returns `true`** — `class-dd-template-module.php:550-555` (`return true;` at :554, comment "Show on ALL frontend pages — including the DishDash homepage template"). So the footer renders on **every** frontend page.

**Single source — the others just reach `wp_footer()`:**
- `templates/page-dishdash.php:1127` — `wp_footer()` (homepage full template) → fires the injected footer.
- `templates/page-simple.php:24` — `get_footer()` → theme `footer.php` → `wp_footer()` → injected footer.
- Theme `index.php` / `page.php` / `singular.php` call `get_footer()` → same path.

**No footer-variant swap.** There is no conditional that renders a different footer. `page-dishdash.php` *reads* footer variables (`page-dishdash.php:160-166, 232`) but **never renders them** — a repo search for `<footer`/`dd-footer` in `page-dishdash.php` returns nothing; the only "footer" below the reads is `wp_footer()` at :1127. Those variables are **dead** (leftovers from an earlier inline footer). See the "dead toggles" note in §C.

---

## B. Per-part verdicts

### 1. Brand column — **OPTION** (all)
`class-dd-template-module.php:875-891`
- Logo: `dish_dash_logo_url` — `class-dd-template-module.php:856` (rendered :878). Empty → initials badge (`$dd_initials` = first 2 chars of name, :857, rendered :880).
- Name: `dish_dash_restaurant_name` (default `'Khana Khazana'`) — `:855` (rendered :881).
- Description: `dd_footer_description` (default `'Premium Indian dining and a refined digital ordering experience.'`) — `:866` (rendered :884).
- Social icons: `dish_dash_facebook` `:862`, `dish_dash_instagram` `:863`, `dish_dash_whatsapp` `:864`, `dish_dash_tiktok` `:865` (rendered :886-889, each only if non-empty).

### 2. EXPLORE column — **LITERAL markup** (labels + link structure), URLs `home_url()`-based
`class-dd-template-module.php:893-903`. Not a WP nav menu, not page-generated — hardcoded `<li><a>`:
- Home → `home_url('/')` (`$home_url` :868) — :896
- Our Menu → `home_url('/restaurant-menu/')` — :897
- Reserve Table → `home_url('/#reserve')`, class `js-open-reservation` — :898
- Track Order → `$orders_url` = `wc_get_account_url('orders')` fallback `/my-account/orders/` (:869) — :899
- Privacy Policy → `home_url('/privacy-policy/')` — :900
- Refund & Returns → `home_url('/refund_returns/')` — :901

The link **labels** ("Home", "Our Menu", …) are literal English strings; there is no config surface for them. The menu itself is NOT a WP nav-menu location.

### 3. CONTACT column — **OPTION** (three separate options)
`class-dd-template-module.php:905-912`. Not a blob — three distinct options, each `<li>` rendered only if non-empty:
- Address: `dish_dash_address` — `:858` (rendered :908, `📍`)
- Phone: `dish_dash_phone` — `:859` (rendered :909, `tel:` link)
- Email: `dish_dash_contact_email` — `:860` (rendered :910, `mailto:` link)

Registered in **Brand Identity** (see §C): `admin/pages/brand-identity.php:38-40` (save), fields `:242/250/256`.

### 4. OPENING HOURS column — **OPTION with LITERAL fallback**  ⚠ see PRIORITY below
`class-dd-template-module.php:914-924`
- Reads `dish_dash_opening_hours` (default `''`) — `:861`; split on newlines `:870`; each line rendered `:918`.
- **Fallback literal when the option is empty** — `:920-921`: `Mon – Fri: 10AM – 10PM` / `Sat – Sun: 9AM – 11PM`.

### 5. Copyright line — **MIXED**
`class-dd-template-module.php:929`:
`&copy; <?php echo date('Y'); ?> <?php echo esc_html($dd_name); ?> &mdash; Built by <strong>Fri Soft Ltd</strong>`
- Year: dynamic `date('Y')`.
- Name: `$dd_name` = OPTION `dish_dash_restaurant_name` (:855).
- "Built by Fri Soft Ltd": **LITERAL**, and there is **no toggle** for it anywhere.
- The observed "- The Authentic Indian Restaurant" tagline is **NOT in the footer code** — the footer only prints `$dd_name`. So that tagline is part of the **stored value of `dish_dash_restaurant_name`** (admin typed it into the name field). There is no separate footer tagline field.

---

## PRIORITY — Opening Hours discrepancy (footer shows "Monday – Friday 10 AM – 7 PM")

**Verdict: candidate (d) — a hardcoded default literal that gets persisted — compounded by TWO forms writing the SAME key with different formats.** Not (a) (the footer *does* call `get_option`). Not "footer reads a different key than the Homepage form" — both use `dish_dash_opening_hours`.

The observed footer string **"Monday – Friday 10 AM – 7 PM" is byte-identical to a hardcoded default in the Template page** (`admin/pages/template-settings.php:50`).

**Read side (footer):** `class-dd-template-module.php:861` — `get_option( 'dish_dash_opening_hours', '' )`. Default is empty, so an empty option would show the §B.4 fallback ("Mon – Fri: 10AM – 10PM"), NOT the observed text. Therefore the option is **not empty** — it holds "Monday – Friday 10 AM – 7 PM".

**Two write sides, one key (`dish_dash_opening_hours`):**

1. **Homepage → Footer Section** (what the user calls "Homepage settings"):
   - Field: `modules/homepage/class-dd-homepage-module.php:920` — `<textarea name="dish_dash_opening_hours">` (multi-line; hint "One line per entry" :921).
   - Save/sanitize: `class-dd-homepage-module.php:156` — `'dish_dash_opening_hours' => 'sanitize_textarea_field'` (preserves newlines), applied at `:168`.
   - This is where "Monday – Sunday 11:30 AM – 10:30 PM" was entered.

2. **Template page** (`Dish Dash → Template`):
   - Field: `admin/pages/template-settings.php:190` — `<input type="text" name="dish_dash_opening_hours" value="<?php echo esc_attr($opening_hours); ?>">` (single line).
   - Value pre-fill / read default: `template-settings.php:50` — `$opening_hours = get_option( 'dish_dash_opening_hours', 'Monday – Friday 10 AM – 7 PM' )`. **This literal is the observed footer text.**
   - Save/sanitize: `template-settings.php:33` (in the allowlist) + `:37` — `update_option( $field, sanitize_text_field( $_POST[$field] ) )` (**collapses to a single line**).

**Mechanism:** When the Template page is opened while the option is empty (or ever saved), its text input is pre-filled with the hardcoded default `'Monday – Friday 10 AM – 7 PM'` (`template-settings.php:50`); saving the page writes that literal into the shared `dish_dash_opening_hours` key via `sanitize_text_field` — **overwriting** the multi-line value the Homepage Footer form saved, and flattening newlines. So the footer (and `page-dishdash.php:166`) render the Template page's default, while the Homepage textarea still shows whatever it last saved. Two admin surfaces silently competing for one key is the root problem.

**Also note — a THIRD, unrelated key:** `dd_opening_hours` (JSON, per-day sessions) drives the open/closed logic and is **not** used by the footer. Do not conflate it. Write: `admin/pages/settings.php:69-73`. Reads: `settings.php:115`, `admin/pages/dashboard.php:186`, `dishdash-core/class-dd-helpers.php:400`.

---

## C. Existing settings infrastructure

### Every footer-related option key already registered

**Homepage → "7. Footer Section"** (`modules/homepage/class-dd-homepage-module.php`, saved in the `$fields` map :152-159, applied :162-169):

| Key | Field label | Type | Save / field line | Read by footer? |
|---|---|---|---|---|
| `dd_footer_show_description` | Show Footer Description | checkbox | :153 / :913 | **NO — dead** |
| `dd_footer_description` | (description textarea) | textarea | :154 / :916 | ✅ footer :866 |
| `dd_footer_show_social` | Show Social Media Icons | checkbox | :155 / :926 | **NO — dead** |
| `dish_dash_opening_hours` | Opening Hours | textarea | :156 / :920 | ✅ footer :861 (collides w/ Template) |
| `dd_footer_show_explore` | Show Explore Column | checkbox | :157 / :930 | **NO — dead** |
| `dd_footer_show_contact` | Show Contact Column | checkbox | :158 / :934 | **NO — dead** |
| `dd_footer_show_hours` | Show Opening Hours Column | checkbox | :159 / :938 | **NO — dead** |

**Template page** (`admin/pages/template-settings.php`):
| `dish_dash_opening_hours` | Opening Hours | text input | :33 (save), :37 (sanitize), :190 (field), :50 (default) | ✅ footer :861 (competes with Homepage) |

**Brand Identity** (`admin/pages/brand-identity.php`, save allowlist :38-44, fields :242-297) — feed footer Contact + Social:
| Key | Field | Save / field | Read by footer |
|---|---|---|---|
| `dish_dash_address` | Address | :38 / :242 | footer :858 |
| `dish_dash_phone` | Phone | :39 / :250 | footer :859 |
| `dish_dash_contact_email` | Email | :40 / :256 | footer :860 |
| `dish_dash_facebook` | Facebook | :41 / :276 | footer :862 |
| `dish_dash_instagram` | Instagram | :42 / :283 | footer :863 |
| `dish_dash_whatsapp` | WhatsApp | :43 / :290 | footer :864 |
| `dish_dash_tiktok` | TikTok | :44 / :297 | footer :865 |
| `dish_dash_restaurant_name` | Restaurant name | field :107 | footer :855 (brand + copyright) |

- `dish_dash_logo_url` (footer logo, read `:856`): registration line **not confirmed** this pass — only the read site was verified. Brand Identity is the likely home; reporting as unconfirmed rather than guessing.

> ⚠ **Social icons memory claim — REFUTED.** Memory said social icons "already live in Template Settings." They are registered in **Brand Identity** (`brand-identity.php:41-44`, fields :276-297), **not** Template Settings. (Template Settings only manages hero fields + opening hours.)

> ⚠ **Dead footer toggles.** The five `dd_footer_show_*` checkboxes are saved by the Homepage form but **never gate the rendered footer**: `inject_global_footer()` (:852-934) never reads them, and `page-dishdash.php` reads them into variables (:160-165) that are never used (that template renders no footer). So "Show Explore/Contact/Hours/Social/Description Column" currently do nothing on the live site.

### One working footer option, traced end-to-end: `dd_footer_description`
1. **Field render** — `modules/homepage/class-dd-homepage-module.php:916`:
   `<textarea name="dd_footer_description" …><?php echo esc_textarea( $this->get( 'dd_footer_description', 'Premium Indian dining …' ) ); ?></textarea>`
2. **Save / sanitize** — `class-dd-homepage-module.php:154` (`'dd_footer_description' => 'sanitize_textarea_field'`), applied in the loop `:162-169` → `:168` `update_option( $key, $sanitizer( $_POST[$key] ?? '' ) )`.
3. **Read site** — `modules/template/class-dd-template-module.php:866`:
   `$dd_footer_desc = get_option( 'dd_footer_description', 'Premium Indian dining and a refined digital ordering experience.' );` → output at `:884` (`esc_html`).

Pattern new footer fields should follow: register in the Homepage `$fields` map → render field with `$this->get()` → read via `get_option` in `inject_global_footer()`.

---

## D. Wide grep — other hardcoded restaurant data (scope check)

> The brief's explicit literal list did not arrive intact (text truncated after "Grep theme + plugin for these literals"). I grepped the obvious brand/locale literals: `Khana Khazana`, `Fri Soft`, `Authentic Indian`, `Kigali`, `Indian Restaurant/dining`, `Africa/Kigali`. All hits below.

### D1 — TRUE hardcoded literals (do NOT read an option → white-label leaks)
- `modules/orders/class-dd-orders-module.php:151` — `'— Khana Khazana 🍽'` appended to a customer-facing order message. **Hardcoded, ignores `dish_dash_restaurant_name`.**
- `modules/orders/class-dd-notifications.php:183` — `'[Khana Khazana] New Order …'` (admin email subject). Hardcoded.
- `class-dd-notifications.php:207` — `Khana Khazana &mdash; …` (email header HTML). Hardcoded.
- `class-dd-notifications.php:227` — `Dish Dash &mdash; Khana Khazana ordering system` (email footer). Hardcoded.
- `class-dd-notifications.php:233` — `'From: Khana Khazana <…>'` (email From name). Hardcoded.
- `class-dd-notifications.php:263` — `'✅ Order Confirmed! — Khana Khazana'` (customer email subject). Hardcoded.
- `class-dd-notifications.php:293` — `'🔔 New Order … — Khana Khazana'` (admin email subject). Hardcoded.
- `templates/page-dishdash.php:292` — `<span class="dd-pill">Authentic Indian Dining</span>` — homepage hero pill. **Hardcoded literal**, not an option.
- `modules/template/class-dd-template-module.php:929` — footer copyright `Built by Fri Soft Ltd` — literal, no toggle (in scope for this brief).
- `modules/auth/class-dd-auth-module.php:843` — login page `Built with care by … Fri Soft Ltd`. Literal.
- `modules/auth/class-dd-auth-module.php:958` — auth email footer `&copy; … <name> &mdash; Fri Soft Ltd` (name is option; "Fri Soft Ltd" literal).

### D2 — Option-backed defaults (fallback only; correct pattern, but default = Khana Khazana / Indian / Kigali)
`dish_dash_restaurant_name` default `'Khana Khazana'`:
- `class-dd-template-module.php:630, :855`; `modules/reservations/class-dd-reservations-module.php:204`; `class-dd-reservations-admin.php:400`; `class-dd-notifications.php:44`; `modules/menu/class-dd-menu-module.php:321`; `modules/auth/class-dd-auth-module.php:376, 936, 1066` (+ `dd_smtp_from_name` fallbacks :201, 983, 1024); `templates/menu/grid.php:175, 346`; `templates/page-dishdash.php:87`.

Other option defaults carrying brand/locale text:
- `templates/page-dishdash.php:91` — `dish_dash_address` default `'Kigali, Rwanda'`.
- `templates/page-dishdash.php:105` — `dish_dash_hero_title` default `'Best Indian Flavor in Kigali'`.
- `templates/page-dishdash.php:119` — `dd_hero_chip_1` default `'Authentic Indian Flavors'`.
- `class-dd-template-module.php:866` / `page-dishdash.php:161` / `homepage-module.php:916` — `dd_footer_description` default `'Premium Indian dining …'`.
- `dd_timezone` default `'Africa/Kigali'` — `reservations-module.php:71`; `helpers.php:329, 412, 417, 458`; `settings.php:83, 120, 556`. (Locale default; reasonable.)

### D3 — Admin-only placeholders / preview labels (cosmetic, not shipped to customers)
- `modules/homepage/class-dd-homepage-module.php:411` placeholder `'Best Indian Flavor in Kigali'`; `:533` chip defaults `['Authentic Indian Flavors', …]`; `:916` placeholder.
- `admin/pages/brand-identity.php:107` placeholder `'e.g. Khana Khazana'`.
- `admin/pages/template-settings.php:81, 92` and `class-dd-template-module.php:474, 484` — Template-card preview labels "Khana Khazana" (admin UI only).
- `templates/cart/cart.php:170` — address input placeholder `'Kacyiru, Kigali'`.

### D4 — Legitimate authorship (leave as-is)
- `dish-dash.php:7` — plugin header `Author: Fri Soft Ltd`.
- `dishdash-core/class-dd-github-updater.php:142` — updater author link `Fri Soft Ltd`.
- `modules/activity/class-dd-activity-module.php:28` — code comment.
- `vendor/giggsey/libphonenumber-for-php/…/map_data.php:2097` — vendored library data (`250 => 'Africa/Kigali'`), not ours.

---

## Summary for the fix phase (no code changed)

1. **Footer is one place:** `inject_global_footer()` (`class-dd-template-module.php:852`), on every frontend page.
2. **Opening-hours bug:** two admin forms write `dish_dash_opening_hours` — Homepage textarea (`homepage-module.php:156/920`) vs Template text input with a hardcoded default `'Monday – Friday 10 AM – 7 PM'` (`template-settings.php:50/37/190`). The Template default is the string on the live footer. Fix = pick ONE writer (or split into distinct keys), drop the hardcoded default, and reconcile single-line vs multi-line sanitize. Read site: `class-dd-template-module.php:861`.
3. **Dead toggles:** `dd_footer_show_*` (`homepage-module.php:153-159`) are saved but never gate the footer — wire them into `inject_global_footer()` or remove them.
4. **Explore labels** and **"Built by Fri Soft Ltd"** are literals with no config surface (copyright has no toggle).
5. **Hardcoded "Khana Khazana"** in order messages + all notification emails (§D1) ignore the option — white-label leaks beyond the footer.
6. Social icons live in **Brand Identity**, not Template Settings (memory corrected).
