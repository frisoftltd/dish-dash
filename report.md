# R4b Report — Read-Side Phone-Anchored Order Resolution — v3.10.43

**Scope:** the three customer-facing history/aggregation queries now resolve a user's
orders by `( customer_id = %d OR customer_phone IN (<their canonical E.164>) )`, tying
previously-guest orders to the logged-in identity. **Read-side only** — no `customer_id`
write, no schema change, ownership gates untouched.

## Shared pattern (all three)
Known-phone set = **canonical-only**: the user's `customers.whatsapp` via
`get_customer_for_user()` (user_id is UNIQUE → exactly one per user). Built in PHP,
`array_filter` drops `''`. **If empty → the original `customer_id = %d` query runs
unchanged** (never emits `IN ()` / `IN (NULL)`). Phones bound via generated `%s`
placeholders through `$wpdb->prepare(..., array_merge([$user_id], $phones))` — never
string-concatenated.

## Query A — Favorites (`class-dd-customer-profile.php:106`)
- Added, before the query: build `$phones`, then `$where_o` (aliased, favorites) /
  `$where_p` (plain, recent) / `$match_args`.
- `WHERE o.customer_id = %d` → `WHERE {$where_o}` =
  `( o.customer_id = %d OR o.customer_phone IN (%s…) )`.
- **`GROUP BY oi.menu_item_id, oi.item_name` unchanged** — item-level, not order-level. The
  wider order set folds MORE orders into the same item buckets → higher favorite counts, no
  grouping-cardinality change, no fragmentation. `ORDER BY times_ordered DESC LIMIT 6`,
  `is_test = 0`, `status NOT IN ('cancelled')` all preserved.

## Query B — Recent orders (`class-dd-customer-profile.php:126`)
- Reuses `$where_p` / `$match_args` computed for A (same user, same set).
- `WHERE customer_id = %d` → `WHERE {$where_p}` =
  `( customer_id = %d OR customer_phone IN (%s…) )`. Single table → OR dedupes at row level.
- `AND is_test = 0 AND status NOT IN ('cancelled') ORDER BY id DESC LIMIT 5` preserved.

## Query C — Order history (`class-dd-profile-module.php:142`)
- Own `$customer` (method returns early if unlinked, so `$customer` is non-null here).
  Builds its own `$phones` / `$where` / `$args`.
- `WHERE customer_id = %d AND is_test = 0` → `WHERE {$where} AND is_test = 0` with
  `( customer_id = %d OR customer_phone IN (%s…) )`. `ORDER BY id DESC LIMIT 50` preserved.

## Safety / no-double-count
- B and C are single-table; A adds no JOIN (the OR is a WHERE filter) and groups by item.
  No row multiplication on any of the three → **no `DISTINCT` needed** (Phase 1 confirmed).
- Empty-set guard verified on all three: unlinked user → `$phones` empty →
  `customer_id`-only query → sees only their own (empty) history, never everyone's.

## Out of scope (confirmed NOT touched)
- Ownership / IDOR gates (`class-dd-orders-module.php` ~1159/1631/1687/1718) — remain strict
  `customer_id = get_current_user_id()`.
- No `customer_id` write / attribution (parked R4c).
- The 7 conflict orders (3, 52, 81, 83, 97, 98, 109) — untouched (canonical-only set
  cannot include the conflict phones).
- No schema / customers-table change.

## Files changed
- `modules/orders/class-dd-customer-profile.php` (queries A + B)
- `modules/profile/class-dd-profile-module.php` (query C)
- `dish-dash.php` (v3.10.43, header + constant), `CLAUDE.md`

## Verification (manual, after deploy — pending developer)
- Log in as **user 14** (whatsapp `+250785553103`, 46 guest orders under it) → order
  history + recent-orders should now include those previously-guest orders; record
  before/after count.
- A **fresh user** with no customers row → history renders empty (the prompt to add a
  phone), not errored, not everyone's orders (1d guard).
