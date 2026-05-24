# Dish Dash — Session Log

> One entry per development session. Most recent at the top.
> Format: date, versions shipped, what broke, what was fixed, root cause, lessons.

---

## Session: 2026-05-24

**Versions shipped:** v3.4.39 → v3.4.43
**Phase:** Bug fix session — Add to Cart broken on mobile + desktop

---

### What was broken
Add to Cart was not working on both mobile and desktop across all entry points:
- Homepage product modal
- Desktop menu page cards
- Mobile product list quick-add button
- Mobile single product screen

---

### Root causes found (in order of discovery)

**1. Restaurant permanently "closed" — no hours configured (v3.4.39)**
- `DD_Hours::get_state()` returned `'closed'` when `dd_opening_hours` option was empty
- This disabled ALL Add to Cart buttons via JS (`pointer-events: none`) AND rejected every AJAX add-to-cart server-side with `restaurant_closed` error
- Fix: added two guards — return `'open'` when schedule is empty, return `'open'` when today's day is missing from schedule
- File: `dishdash-core/class-dd-helpers.php`

**2. Wrong AJAX action name for remove from cart (v3.4.39)**
- `frontend.js` was sending `action: 'dd_remove_from_cart'`
- Backend registered action is `dd_cart_remove`
- Silent failure — WordPress returned `{success:false, data:0}` for unknown action
- Fix: corrected action name in `frontend.js`
- File: `assets/js/frontend.js`

**3. Wrong nonce action in window.DD (v3.4.39)**
- `class-dd-template-module.php` created `window.DD.nonce` with `wp_create_nonce('dd_nonce')`
- Backend verifies against `'dish_dash_frontend'`
- Latent bomb — only fires when `ddCartData` fails to enqueue
- Fix: corrected nonce action string
- File: `modules/template/class-dd-template-module.php`

**4. Mobile quick-add button opened detail screen instead of adding to cart (v3.4.41)**
- Click handler called `this.showProductDetails()` for ALL products including simple ones
- Fix: added `isSimple` check — simple products add directly, variable products open detail screen
- Then reverted in v3.4.43 — product owner preference is to always open detail screen first
- File: `assets/js/menu-page.js`

**5. `data-is-simple` value mismatch (v3.4.41)**
- PHP outputs `"1"` for true but JS was comparing `=== 'true'`
- Condition never passed — every product treated as variable
- Fix: `card.dataset.isSimple === 'true' || card.dataset.isSimple === '1'`
- File: `assets/js/menu-page.js`

**6. admin-ajax.php blocked by custom admin path guard — PRIMARY CAUSE (v3.4.42)**
- Phase 5A added a custom admin path feature (`/khazana`) that blocks all `/wp-admin/` requests for security
- `maybe_block_wp_admin()` in `class-dd-hooks.php` had no exception for `admin-ajax.php`
- Every frontend AJAX call routes through `wp-admin/admin-ajax.php` → got 404 → returned HTML instead of JSON → `SyntaxError: Unexpected token '<'`
- This broke: add to cart, cart updates, tracking events, search, everything
- Fix: added single guard — `if ( str_ends_with( $request_uri, 'admin-ajax.php' ) ) return;`
- File: `dishdash-core/class-dd-hooks.php`

---

### Lessons learned

- **Custom admin path must always whitelist `admin-ajax.php`** — any feature that blocks `/wp-admin/` will kill all frontend AJAX
- **Version bump is mandatory in every Claude Code brief** — missing it means the deployed version doesn't match the repo, making deployment verification impossible
- **Scope is a hard wall** — Claude Code must never edit files not explicitly listed in the brief
- **Silent AJAX failures need console debugging** — `[DD Cart] fetch error: SyntaxError` was the key signal; always check Network tab for 404s on `admin-ajax.php`
- **Detection beats prevention** — add UptimeRobot monitor on `admin-ajax.php` before going multi-tenant (Phase 10 checklist)

---

### Workflow improvement added this session
- CLAUDE.md updated: every Claude Code brief must end with version bump + commit + push (Rule 0)
- CLAUDE.md updated: scope is a hard wall — report bugs found outside scope, never fix them (Rules 1a, 1b)
- SESSION_LOG.md created — session history moved out of CLAUDE.md into this dedicated file
