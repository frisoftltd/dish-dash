# INVESTIGATION — Cart dedup drops per-item notes

**Phase 1, read-only.** Plugin facts carry `file:line`. This is a **code** design question, not data — the one
data note (active cart transients) is marked **PENDING** with a check.

---

## TL;DR

- **`item_key()` is called in exactly one place — `add()` (`class-dd-cart.php:86`).** It's `private`. Nothing
  else computes it; `update()`/`remove()` address `$cart[$key]` using the key **echoed back** to the client via
  `summary()['key']`. So changing the key formula is **contained** — no caller breaks.
- **The dedup branch (`:88-89`) drops only `note` in practice.** The key = `md5(id + variation + addons)`, so a
  key match means `variation` and `addons` are **identical** by definition — they can never differ across a
  collision. Only `note` (absent from the key) can differ between two same-key adds, and it's the note that's
  silently discarded. My earlier "variation too" was imprecise — variation is never actually lost here.
- **`addons` is vestigial in the current UI** (always `[]`), like the variations were — so today the key is
  effectively `md5(id + variation)`.
- **Note's absence from the key is an oversight, not a decision** — notes were never captured until v3.10.81, so
  no one ever hit this branch with a note.
- **The three options are all contained to `class-dd-cart.php`;** none touches `variation`'s existing behaviour
  (it stays in the key in all three). Product decision required (§6) — reported, not chosen.

---

## 1. `item_key()` and the dedup branch — verbatim

```php
// class-dd-cart.php:178-180
private function item_key( array $item ): string {
    return md5( $item['id'] . ( $item['variation'] ?? '' ) . wp_json_encode( $item['addons'] ?? [] ) );
}
```
```php
// class-dd-cart.php:86-101 (add())
$key = $this->item_key( $item );
if ( isset( $cart[ $key ] ) ) {
    $cart[ $key ]['qty'] += absint( $item['qty'] ?? 1 );          // ← only qty; note NOT written
} else {
    $cart[ $key ] = [
        'id' => …, 'name' => …, 'price' => …, 'qty' => …, 'image' => …,
        'variation' => sanitize_text_field( $item['variation'] ?? '' ),
        'addons'    => $this->sanitize_addons( $item['addons'] ?? [] ),
        'note'      => sanitize_textarea_field( $item['note'] ?? '' ),   // ← only set on a NEW line
    ];
}
```

**Fields currently in the key:** `id`, `variation`, `addons` (JSON). **Not** in the key: `name`, `price`, `qty`,
`image`, **`note`**.

- **Why `variation` is in / `note` is out:** `variation` was added deliberately — a variant (spice level) must
  produce a distinct line, so it's part of identity. `note` was simply never part of it because **notes were
  never captured** before v3.10.81; the branch was never exercised with a real note. Oversight, surfaced only now.
- **`addons` — vestigial (like the variations).** No UI populates it: `menu-page.js:718` sends
  `addons: JSON.stringify([])`; the desktop modal sends none (`ajax_add` defaults `'[]'`, `class-dd-cart.php`).
  `cart.js:447` only *reads* `item.addons` for a display subtotal. So `addons` is always `[]` →
  `wp_json_encode([])` = `"[]"` for every item → a **constant** contribution → the key is effectively
  `md5(id + variation)` today. (Plumbing is live — `order_items.addons` is written and the kitchen reads it at
  `notifications.php:423-426` — but nothing produces addons, so it never varies.)

## 2. Everywhere `item_key` is used — blast radius

- **Only caller:** `add()` (`:86`). Grep for `item_key` returns exactly the definition (`:178`) and this one call.
- **`update()` (`:110-121`) and `remove()` (`:126-131`)** operate on `$cart[$key]` with a `$key` that arrives
  from the client (`$_POST['key']`, `:251/:260`) — they **never call `item_key()`**. The key is opaque: `add()`
  computes it, stores the line under it, and `summary()` echoes it as `['key' => $key]` (`:159`); `cart.js`
  renders each line with `data-key="…"` (`:451`) and the qty stepper / remove button send that same key back
  (`cart.js:276/301/326` → `dd_cart_update`/`dd_cart_remove`).
- **Therefore, if `note` enters the key:** a new add of the same dish with a **different** note computes a
  **different** key → a **second cart line**. Each line carries its **own** key in `summary()`, so:
  - the **qty stepper** on either line targets that line's key → works;
  - **remove** targets that line's key → works;
  - identical dish+variation+**same** note still collapses (same key) → qty bump, as today.
- **Blast radius: small and contained.** No caller recomputes the key, so changing the formula shifts **only**
  `add()`'s merge decision. `update`/`remove`/`summary` are formula-agnostic. **Nothing downstream breaks.**

## 3. The re-add path in full

`ajax_add` (`:200`) → `add()` (`:83`) → `item_key()` (`:86/178`) → **branch**:
- **key exists** (`:88-89`) → `qty += n`; **the new `note` is discarded** (the else-block that writes `note`
  never runs). → `summary()` (`:104/143`) → `place_order` passes `$summary['items']` (`orders-module.php:884/…`)
  → `insert_order_items` writes `special_note` from `$item['note']` (`:447`) — which is the **first** add's note.
- **key new** (`:91-100`) → full line incl. `note` stored → flows through correctly (order 199 proved this).

**Exact fields lost in the dedup branch:** **`note` only.** `variation` and `addons` **cannot** differ across a
key collision (they're *in* the key — a match means they're identical), so the qty-bump keeps the already-correct
variation. So the branch drops nothing on the variation path — the loss is exclusively the note.

## 4. Existing cart behaviour

- **The cart drawer already has a quantity stepper** — `cart.js:276/301` send `dd_cart_update` with the line's
  key to set qty **without re-adding**. So dedup-on-re-add (bump qty on a repeat Add) is a **convenience that
  overlaps** the stepper; a customer can already raise quantity in the drawer. This weakens the case for silent
  merging: re-adding is not the only (or primary) way to increase qty.
- **Mobile `/restaurant-menu/` shares the same cart backend** — `menu-page.js` posts `dd_cart_add` → the same
  `DD_Cart::ajax_add` → same `item_key`/dedup. There is **no separate mobile cart.** Mobile has **no note field**
  and always sends `note: ''` (`menu-page.js:719`), so mobile adds never carry a note to lose — mobile is
  unaffected by the bug and by any of the fixes (empty note = same behaviour under all three options).

## 5. Live/data safety — key-format change mid-session

- The cart is a **transient** (`set_transient($this->cart_key, $cart, DAY_IN_SECONDS*3)`, `:175`), an associative
  array **keyed by the md5 keys**. Keys are **stored**, never recomputed on read.
- **Changing the `item_key` formula does not corrupt in-flight carts.** An existing line stays under its old key;
  a new add of the "same" item computes a **new-formula** key → the two **won't merge** → the customer
  transiently sees **two lines** for what would previously have merged. `update`/`remove` still work on both
  (they use stored keys); a stale client holding an old key still targets the still-present old line. Worst case
  is a cosmetic non-merge for carts that span the deploy — **no data loss, no corruption**, and it self-heals as
  the 3-day transients expire / carts are checked out.
- **No SQL migration** (transient data, ephemeral). Optional visibility check — **PENDING (server)**:
  ```sql
  SELECT COUNT(*) FROM {$P}options WHERE option_name LIKE '_transient_dd_cart_%';
  ```
  (how many carts are currently in flight — bounds the number of sessions that could see a one-time non-merge).

## 6. Decomposition & the three options (report, do not decide)

All three are **contained to `class-dd-cart.php`** (no caller changes, §2). None alters `variation`'s current
behaviour — it stays in the key in every option (same dish + different spice → different line, as today).

| Option | Mechanics | Files/lines | Effect on identical re-adds | Trade-off |
|---|---|---|---|---|
| **1 — notes make items distinct** | append note to the key: `md5(id + variation + addons + note)` | `item_key()` `:179` (one line) | same dish + **same** note → merges (qty++); different note → new line | Clean & symmetric with variation. **But** free-text: `"no onion"` vs `"no onions"` (or trailing space/case) → separate lines. Mitigate by hashing a **normalized** note (`trim()`, maybe lowercase) — an implementation detail of this option |
| **2 — noted items never merge** | in the dedup branch, only bump qty when the note is empty; otherwise always a new line | `add()` branch `:88` (condition change) + typically still key by note | any item **with a note** always gets its own line; no-note items merge as today | Simplest mental model for food; matches "each special request is its own line". **But** identical noted re-adds don't merge (two lines at qty 1) — usually fine |
| **3 — last-write updates** | keep the key; in the dedup branch also refresh `note` | `add()` if-branch `:89` (add one line) | merges, overwriting the stored note with the newest | **Wrong for food** (brief): the 2nd "no onions" overwrites the 1st line's "extra spicy". Not recommended |

**On variation specifically:** it is already an identity field (in the key) and **none** of the options change
that. Option 1 makes note a peer of variation (both distinguish lines) — the most *consistent* model; Option 2
treats note as a "never-merge" flag layered on top; Option 3 leaves the key alone and is semantically wrong here.

**Decomposition:** **one fix**, one file. Option 1 is literally one line (`item_key`), plus an optional
normalization choice. Option 2 is a small branch-condition change in `add()`. Either way it does **not** touch
`update`/`remove`/`summary`, the R1 capture, the variation path, or the display gap (R2).

---

## Pending server checks (consolidated)
1. (optional, §5) `SELECT COUNT(*) FROM {$P}options WHERE option_name LIKE '_transient_dd_cart_%';` — active in-flight carts that could see a one-time non-merge across the deploy. Not a blocker (no corruption either way).
