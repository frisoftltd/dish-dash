# Investigation: Nav Hover Color + Background Image Feasibility

**Status: read-only investigation. No code changed. No design opinions —
facts only, per the brief.**

---

## 1. Nav link hover — CSS rule found, and it is NOT hardcoded

### The rule

There is exactly one CSS rule controlling hover state on the new Home/Menu/
Reservation nav (`.dd-ml-nav__link`, added v3.18.44), confirmed via a
repo-wide grep — no duplicate/conflicting declaration exists elsewhere:

```css
/* assets/css/layouts/minimal-light.css:201 */
.dd-ml-nav__link:hover { color: var(--ml-accent); }
```

`--ml-accent` (same file, `:root`, line 57) is:

```css
--ml-accent: var(--accent, #e8832a);
```

`--accent` is emitted site-wide by `DD_Template_Module::build_root_tokens()`
from the `dish_dash_accent_color` option — the same chain v3.18.44 wired up
for the accent color generally. This fires on **every** frontend page,
including the Minimal Light homepage, via `inject_global_header_styles()`
(hooked on `wp_head`, gated only by `is_global_header_page()` which
unconditionally returns `true` on the frontend — confirmed in the prior
session's investigation and re-checked here).

**Conclusion: the hover rule is correctly token-bound, not hardcoded to
white or any other fixed color.** I could not find a literal `#fff`/`white`
value anywhere in this rule or its `--ml-accent` → `--accent` →
`dish_dash_accent_color` dependency chain. The "hardcoded to white"
hypothesis in the brief does not match what's actually in the code for
`.dd-ml-nav__link` specifically.

### Most likely real explanation: wrong component, not wrong CSS

The new `.dd-ml-nav` is `.dd-desktop-only` (hidden below 1025px, per
v3.18.44's design — hamburger+drawer is the mobile fallback). **If this was
tested on a phone or a narrow browser window, the new nav wouldn't be
visible at all.** There is a second, pre-existing, unrelated component with
nearly identical labels that *would* be visible on mobile: the mobile
bottom nav (`.dd-bottom-nav`/`.dd-bottom-link`, Home/Menu/Reserve/Cart,
shared component, explicitly left untouched by v3.18.44 per its own CSS
comment "restyled only"). This is the strongest candidate for what's
actually being observed:

```css
/* assets/css/theme.css:2111 — pre-existing, NOT touched by v3.18.44 */
.dd-bottom-link.active { background: #F1E8DB; color: var(--brand); }
```

This still uses `var(--brand)` — the OLD primary-color token, never
repointed to the new `--ml-accent`/`--accent` system — and a hardcoded
cream background (`#F1E8DB`) that isn't part of Minimal Light's token set
either. Worked out the actual specificity interaction with minimal-light.css's
own (pre-existing, unchanged) override:

```css
/* minimal-light.css — pre-existing, unchanged */
body.dd-tpl-minimal-light .dd-bottom-link { color: var(--ml-ink-soft); }
```

`body.dd-tpl-minimal-light .dd-bottom-link` (1 element + 2 classes) is
*more* specific than `.dd-bottom-link.active` (2 classes, 0 elements), so
on Minimal Light the active bottom-nav tab's color actually resolves to
`--ml-ink-soft` (dark grey-brown), not `--brand` — **neither is white**,
but neither reflects the new accent color either. This is a real, genuine
gap (the bottom nav's active/tapped state was never wired into the new
accent-color system at all), just not literally "white."

### Secondary, narrower risk found while checking

`--ml-accent-dark` (used by `.dd-ml-btn--solid:hover` and the tab-strip
scrollbar-thumb hover, **not** by `.dd-ml-nav__link:hover` itself) is
computed via `color-mix()`:

```css
--ml-accent-dark: color-mix(in srgb, var(--accent, #e8832a), black 15%);
```

`color-mix()` has broad modern support (Chrome 111+/Safari 16.2+/Firefox
113+) but no fallback value is given at its own declaration site. In an
unsupported browser this custom property would fail to resolve, and
anywhere `var(--ml-accent-dark)` is used without its own fallback would
fall through to unset/initial rather than a visible color. This affects
button/tab hover states, not the nav-link hover color directly — flagging
because it's the same token family and could look like a related "hover
color is broken" symptom on an older browser, but it is not the nav-link
rule itself.

### What I could not confirm from code alone

I could not reproduce a literal white value anywhere in this dependency
chain, and cannot browser-test the live deployed site from here. Two things
worth ruling out before writing a fix, in order of likelihood:
1. **Wrong component** — confirm in a real browser whether the color being
   observed is on the new desktop `.dd-ml-nav__link` (top header, ≥1025px)
   or the pre-existing mobile `.dd-bottom-link.active` (bottom bar,
   <1025px) — these are two different, independently-styled elements.
2. **Stale cache** — confirm LiteSpeed Cache was purged and the browser
   wasn't serving a cached pre-v3.18.44 page/asset.

If, after ruling those out, the new `.dd-ml-nav__link:hover` genuinely
computes to white in devtools, that would mean `--accent` itself is
failing to resolve on that specific page load — worth checking the
computed value of `--accent` directly in devtools at that point, since the
CSS as written has a non-white fallback (`#e8832a`) and I found no path
that would produce white from it.

### Fix approach (once the actual element is confirmed)

- If it's confirmed to be `.dd-ml-nav__link:hover`: the rule is already
  correctly wired to `dish_dash_accent_color` via `--ml-accent`/`--accent`
  — no different binding is needed, the existing chain is already "the
  same token already wired for `--ml-accent` in v3.18.44" per the brief's
  own suggested fix. If it's still showing wrong, the fault is upstream in
  token resolution, not in this rule.
- If it's confirmed to be the mobile bottom nav's active state: the fix is
  a small, contained addition to `minimal-light.css` — a
  `body.dd-tpl-minimal-light .dd-bottom-link.active` override binding to
  `var(--ml-accent)` (or a suitable light background pairing, since
  `#F1E8DB` is Khana Khazana's palette, not Minimal Light's
  `--ml-surface`/`--ml-surface-2`) — same pattern already used for every
  other shared-component override in that file (drawer, cart badge, footer,
  product modal).

---

## 2. Background-image field — already exists, already fully built, just unused by Minimal Light

Confirmed exactly as recalled: Homepage Settings (the shared, template-
agnostic admin page — `modules/homepage/class-dd-homepage-module.php`) has
a complete, working "Hero Background Image (full section background)"
field plus a companion overlay color/opacity pair, all already registered,
sanitized, and rendered with real admin UI:

| Option | Admin control | Sanitizer | Default |
|---|---|---|---|
| `dd_hero_bg_image` | Text field + Upload button + live preview, label: *"Hero Background Image (full section background)"* | `esc_url_raw` | empty |
| `dd_hero_overlay_color` | Color picker + hex text input, hint: "Color of the overlay gradient on top of the background image." | `sanitize_hex_color` | `#6B1D1D` |
| `dd_hero_overlay_opacity` | Range slider (0–100, step 5) with live `%` label | `absint` | `85` |

(`class-dd-homepage-module.php:97-105` for registration, `:633-680` for the
admin UI markup.)

### Khana Khazana already fully wires all three

`templates/page-dishdash.php:111-117` reads all three options and combines
overlay color+opacity into a single rgba string:

```php
$dd_hero_bg         = get_option( 'dd_hero_bg_image', '' );
$dd_overlay_color   = get_option( 'dd_hero_overlay_color', '#6B1D1D' );
$dd_overlay_opacity = (int) get_option( 'dd_hero_overlay_opacity', 85 );
$dd_overlay_rgba    = 'rgba(' . implode( ',', array_map( 'hexdec', str_split( ltrim( $dd_overlay_color, '#' ), 2 ) ) ) . ',' . round( $dd_overlay_opacity / 100, 2 ) . ')';
```

...then applies them as CSS custom properties on the `.dd-hero` section
(`:284-293`), consumed by `theme.css`'s `.dd-hero` rules
(`assets/css/theme.css:612-641`): a full-bleed, absolutely-positioned
background layer (`background-image: var(--dd-hero-bg, <unsplash fallback
url>)`) plus a second absolutely-positioned layer with a diagonal gradient
scrim (`linear-gradient(135deg, var(--dd-overlay-color, ...) 0%, ...)`) —
a single-column, dark, full-bleed hero with text overlaid on top of a
photo.

### Minimal Light: `dd_hero_bg_image` read but misused; the other two never read at all

`templates/layouts/minimal-light/page-home.php`'s hero image-resolution
logic:

```php
$hero_img = $dd_h_img ?: $dd_hero_bg;   // dish_dash_hero_image, fallback dd_hero_bg_image
```

`dd_hero_bg_image` is only consulted as a **second candidate source for
the same plain `<img>`** (now inside the dashed-circle frame added in
v3.18.44) when no `dish_dash_hero_image` is set — i.e. it's being treated
as an alternate foreground "card image," not a section background. This
was true before v3.18.44 too (same fallback logic existed in the original
text-link-CTA version of the hero) — not something v3.18.44 introduced or
changed.

`dd_hero_overlay_color` and `dd_hero_overlay_opacity` are **never read
anywhere in Minimal Light** — confirmed via repo-wide grep, the only
occurrences outside the admin module and Khana Khazana's own template are
in this investigation file and the prior investigation's findings doc.

### Feasibility

**Confirmed: this is a small wiring job, not new scope.** All three
options already exist, are already admin-editable via a real, working UI,
are already sanitized, and already have a proven consumption pattern to
reference (Khana Khazana's own template). No new field, no new admin UI,
no new option registration would be needed for Minimal Light to start
using them.

**One thing worth flagging before anyone writes the fix, not resolved
here per the brief:** Khana Khazana's exact CSS treatment — a full-bleed,
dark, single-column background photo with a diagonal color-gradient scrim
and white text laid on top — is built for a very different hero shape than
Minimal Light's. Minimal Light's hero (`.dd-ml-hero__grid`) is a light,
two-column split with a plain `var(--ml-bg)` (white) section background;
the "photo" is a small dashed-circle-framed image confined to the right
column, not a full-section backdrop. Copying Khana Khazana's `--dd-hero-bg`
+ diagonal-gradient-overlay mechanism onto `.dd-ml-hero` verbatim would
fight that layout (a full-bleed dark photo behind light-on-white body copy
reads as a different design entirely, not a small addition). The **data**
is 100% ready to wire in as-is; the **visual application** — e.g. a subtle
full-section background behind both columns, something scoped to just the
media/frame column, or another treatment — is a real design decision that
belongs in the Fix Brief, not decided here.

---

## Summary for planning

- **Nav hover**: the new `.dd-ml-nav__link:hover` rule itself is correctly
  bound to the accent token chain, not hardcoded — no fix needed there
  *if* that's confirmed to be the element actually observed. Strongest
  alternate explanation: the pre-existing mobile bottom-nav's `.active`
  state (`var(--brand)`, `#F1E8DB`) was never updated to the new accent
  system and is a likely source of confusion if tested on mobile — a
  small, contained fix if that's what's being seen. Recommend confirming
  the exact element/viewport in browser devtools before writing a fix.
- **Background image**: `dd_hero_bg_image` + `dd_hero_overlay_color` +
  `dd_hero_overlay_opacity` all already exist, fully admin-editable, fully
  proven via Khana Khazana's own working implementation. Minimal Light
  reads the first as a foreground-image fallback only and ignores the
  other two entirely. Wiring the data in is small; the visual treatment
  needs its own decision, not a literal port of Khana Khazana's full-bleed
  dark-overlay pattern.
