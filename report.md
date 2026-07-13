# R2 pill polish — brand-color pills + fix row overflow — Build Report

**Version:** 3.10.49 (bumped in `dish-dash.php` header + `DD_VERSION`, and CLAUDE.md).
**Status:** built, committed, pushed. **Not released** — developer creates the GitHub release.
**One file changed:** `assets/css/order-tracking.css` (list-row pills + row layout).

---

## 1. Brand-color pills (dropped per-status colors)
- All status pills now use `background: var(--brand, #65040d)` with white text — one consistent brand pill
  for `pending` / `confirmed` / `ready`.
- Removed the per-status rules (`.dd-status--pending` amber / `--confirmed` blue / `--ready` green).

## 2. Fixed the row overflow (pill + date clipping at the card edge)
Root cause: the row was `display: grid; grid-template-columns: 1fr auto auto`. The `1fr` (order-number)
column has an implicit `min-width: auto` = its content width, so a long order number couldn't shrink and
pushed the `auto` pill/time columns past the card's right edge → the pill text and timestamp got clipped.

Fix — converted the row to **flex** so the right-hand items can never be squeezed out:
```css
.dd-track__order-link { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 12px; padding: 14px 8px; }
.dd-track__order-num  { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dd-track__order-status { flex: 0 0 auto; background: var(--brand, #65040d); /* white text, nowrap */ }
.dd-track__order-time   { flex: 0 0 auto; white-space: nowrap; }
.dd-track__order-note   { flex: 1 0 100%; /* own line under the row */ }
```
- The **order number** flexes and ellipsizes (`min-width:0`), so it — not the pill/date — absorbs any tight
  space.
- The **pill** and **timestamp** are `flex: 0 0 auto` → they keep their full size and sit fully inside the
  card. Combined with the card's 24px padding + the row's 8px padding, they have proper right-edge breathing
  room. `flex-wrap` only drops them to a second line on an extreme squeeze (never mid-word clipping).
- Removed the now-obsolete grid overrides in the `≤480px` media block (flex + wrap reflow on their own).

## 3. Unchanged
Sidebar, timeline card, and the `--dd-track-accent` variable (still drives the timeline dots / order number /
cancel badge) are untouched. No PHP/template/query changes this release.

---

## Verify after deploy (LiteSpeed purge, logged in as user 14)
1. `/track-order/` list → every pill is the **brand color** with its label fully visible (Pending / Confirmed
   / Ready), and the **timestamp is fully inside the card** — nothing clipped at the right edge.
2. Try an order with a long order number → the number ellipsizes; the pill + date stay put.
3. Narrow the window / mobile → rows reflow cleanly (pill/date wrap to a second line only when truly needed),
   still no clipping.
4. Timeline (`?order_id=`) and sidebar look identical to v3.10.48.

## Note
- No local `php -l` (PHP not installed here) — but this release is CSS-only. Purge LiteSpeed and hard-refresh
  so the updated `order-tracking.css` loads.
