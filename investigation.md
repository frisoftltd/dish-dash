# Investigation — `normalize_phone()` Trunk-0 Fix (READ-ONLY)

Date: 2026-07-05. Working tree v3.10.31 (`9d774d4`). No edits. Raw reads only.

**Bottom line:** the current output format for already-correct inputs is **bare
`250XXXXXXXXX`** (12 digits, no `+`). The only defect is that the national trunk `0` is
not stripped, so `0788123456` misses the `+250` path. The minimal, **format-preserving**
fix strips a leading `0` from 10-digit input so it re-enters the existing 9-digit→`250…`
branch. Do **not** switch to E.164 `+…` now — that would orphan every stored key until the
backfill. Format change belongs to the library phase.

---

## 1. The function verbatim

`modules/orders/class-dd-customer-manager.php:321-329`:
```php
321  /**
322   * Normalize phone to digits only with Rwanda prefix.
323   */
324  public static function normalize_phone( string $phone ): string {
325      $digits = preg_replace( '/[^0-9]/', '', $phone );
326      if ( strlen( $digits ) === 9 ) $digits = '250' . $digits;
327      if ( strlen( $digits ) < 9 )  return '';
328      return $digits;
329  }
```

Branch-by-branch on the raw digit string (`$digits`, after stripping all non-digits):

| Input | `$digits` | len | Result | Correct? |
|---|---|---|---|---|
| `788123456` (bare 9) | `788123456` | 9 | `250788123456` | ✅ |
| `+250 788 123 456` (has `+`, spaces) | `250788123456` | 12 | `250788123456` | ✅ |
| `250788123456` (leading 250) | `250788123456` | 12 | `250788123456` | ✅ |
| `0788123456` (leading 0) | `0788123456` | 10 | **`0788123456`** | ❌ trunk-0 not stripped |
| `12345` (short) | `12345` | 5 | `''` | ✅ rejected |
| `14155552671` (US, 11) | `14155552671` | 11 | `14155552671` | ⚠️ passes through, no country logic |

So: `+`, embedded spaces, and a leading `250` all already collapse to bare `250…`. The
**only** broken canonicalization is the leading-`0` national form (10 digits), which is left
untouched and therefore never matches the `250…` key.

**Canonical form the working cases produce today: bare `250` + 9 digits (12 digits, no `+`).**

---

## 2. All callers

Grep `normalize_phone(` across the plugin — 4 call sites:

| # | File:line | Method | Input | Output used as… |
|---|---|---|---|---|
| 1 | `modules/orders/class-dd-customer-manager.php:33` | `upsert()` | `$whatsapp` (order phone) | **identity key** — `WHERE whatsapp = %s` (`:40`) **and** stored `INSERT whatsapp` (`:69`) |
| 2 | `class-dd-customer-manager.php:183` | `on_resolve_customer_id()` | `$whatsapp` | **identity key** — `WHERE whatsapp = %s` (`:188`) **and** stored `INSERT whatsapp` (`:196`) |
| 3 | `class-dd-customer-manager.php:244` | `link_user_to_phone()` | `$phone` (profile input) | **identity key** — `WHERE whatsapp = %s` (`:255-256`), stored on `INSERT whatsapp` (`:289`), and `update_user_meta('dd_phone'/'billing_phone')` (`:299-300`) |
| 4 | `modules/orders/class-dd-orders-module.php:136` | birthday-WhatsApp scheduler | `$whatsapp` | **presence/validity guard only** — `if ( ! $phone ) return;` (`:137`); not stored, not a match key |

**Store-and-match confirmed for #1–#3.** Each uses the *same* `normalize_phone()` output to
both look up and to persist `customers.whatsapp`. Therefore existing stored keys are in
whatever format the function emitted at write time. Caller #4 only truthiness-checks the
result, so it is format-insensitive.

**Implication.** The fix must keep the identity-key callers (#1–#3) matching existing rows.
A format-preserving trunk-0 fix does exactly that; a format *change* (to `+250…`) would break
their `WHERE whatsapp = %s` against all previously stored `250…` values until a backfill runs.

---

## 3. The exact minimal change

**Target canonical form (interim): keep the existing bare `250788123456`** (no `+`). This is
what callers #1–#3 already stored for correctly-entered numbers, so keeping it means those
rows keep matching. E.164 `+250…` is deferred to the library phase where the backfill rewrites
all stored keys in one coordinated pass.

**Smallest edit** — strip a single leading trunk `0` from 10-digit input *before* the existing
9-digit check, so `0788123456` flows into the current `+250` branch:

```php
// BEFORE
public static function normalize_phone( string $phone ): string {
    $digits = preg_replace( '/[^0-9]/', '', $phone );
    if ( strlen( $digits ) === 9 ) $digits = '250' . $digits;
    if ( strlen( $digits ) < 9 )  return '';
    return $digits;
}

// AFTER
public static function normalize_phone( string $phone ): string {
    $digits = preg_replace( '/[^0-9]/', '', $phone );
    // National trunk prefix: 0788123456 (10) → 788123456 (9), then the +250 branch applies.
    if ( strlen( $digits ) === 10 && $digits[0] === '0' ) {
        $digits = substr( $digits, 1 );
    }
    if ( strlen( $digits ) === 9 ) $digits = '250' . $digits;
    if ( strlen( $digits ) < 9 )  return '';
    return $digits;
}
```

- Output format **unchanged** for every currently-working case (still bare `250…`).
- The only behavioral change: 10-digit leading-`0` input now yields `250…` instead of
  `0788123456`. That is strictly an improvement — it *adds* matches to the existing `250…`
  keyspace rather than moving the keyspace.

**Does stored data depend on the current format such that changing it orphans matches?**
- The correctly-stored keys are already `250…`; the fix keeps emitting `250…`, so **they keep
  matching** (no orphaning of the good rows).
- The **previously mis-stored** `0788…` rows (written when trunk-0 slipped through) will *not*
  match the new `250…` output — but they were already broken/duplicated. The fix doesn't create
  new orphans of good data; it leaves the already-bad rows for the backfill to collapse.
- Therefore: **format-preserving trunk-0-only fix now; defer any `+`/E.164 change to the
  library+backfill phase.** This is the low-risk path.

---

## 4. Test vectors the fix must satisfy

Rwanda canonical target = **`250XXXXXXXXX`** (bare). All variants of one subscriber → one key:

| Input | Expected output | Note |
|---|---|---|
| `0788123456` | `250788123456` | trunk-0 stripped → prefixed |
| `250788123456` | `250788123456` | unchanged |
| `+250788123456` | `250788123456` | `+` stripped |
| `+250 788 123 456` | `250788123456` | spaces + `+` stripped |
| `0788 123 456` | `250788123456` | spaces stripped, trunk-0 stripped |
| `788123456` | `250788123456` | 9-digit branch |
| `+250 787 538 546` (real, spaced) | `250787538546` | — |
| `0787538546` (real) | `250787538546` | **matches the `+250 787…` variant** ✅ |
| `250785553103` (real, no `+`) | `250785553103` | unchanged |
| `+2507865340` (real, short/invalid) | `2507865340` | digits=`2507865340` (10, **not** leading `0`) → not trunk-stripped, not 9 → returned as-is. **Not** mangled into a false 12-digit match; stays a distinct, non-colliding (malformed) key. Acceptable — a fuller validity check is the library's job. |
| `+1 415 555 2671` (US, non-RW) | `14155552671` | 11 digits → untouched, **no false 250 prefix** ✅ |
| `+44 7911 123456` (UK, 12) | `447911123456` | untouched, no false prefix ✅ |

---

## Proposed fix

**Diff** (one file, `modules/orders/class-dd-customer-manager.php`, inside `normalize_phone()`):
```diff
     $digits = preg_replace( '/[^0-9]/', '', $phone );
+    // National trunk prefix: 0788123456 (10) → 788123456 (9), then the +250 branch applies.
+    if ( strlen( $digits ) === 10 && $digits[0] === '0' ) {
+        $digits = substr( $digits, 1 );
+    }
     if ( strlen( $digits ) === 9 ) $digits = '250' . $digits;
     if ( strlen( $digits ) < 9 )  return '';
     return $digits;
```
**Confirmed canonical output form (interim): bare `250` + 9 digits** (no `+`) — identical to
what the working callers already store, so no format migration is triggered by this change.

## Risks

1. **No format-orphan for good data.** Output format is unchanged for all currently-working
   inputs, so `upsert()`/`on_resolve_customer_id()`/`link_user_to_phone()` keep matching the
   `250…` rows they already wrote. This is the key reason to do the trunk-0-only fix now and
   defer the `+`/E.164 change to the backfill phase (where existing keys are rewritten in one
   pass). Changing the format now instead would break `WHERE whatsapp = %s` against every stored
   `250…` value until the backfill runs.
2. **Previously mis-stored `0788…` rows stay unmatched until backfill.** The fix won't retro-match
   rows that were saved in the broken trunk-0 format; those remain for the migration to collapse.
   New activity for those subscribers now lands on the correct `250…` key (possibly creating one
   more row to merge) — bounded, and exactly what the backfill handles.
3. **Interim Rwanda assumption — flag for the library phase.** "10-digit leading-`0` ⇒ strip and
   prefix `250`" is Rwanda-specific. A non-Rwandan 10-digit national number beginning with `0`
   would be mis-prefixed to `250…`. Acceptable **only** because capture is currently Rwanda-only
   (no country picker). This is precisely the limitation the libphonenumber phase removes; do not
   generalize this heuristic by hand.
4. **Non-Rwandan / malformed inputs pass through unhandled, not corrupted** (US 11-digit, UK
   12-digit, short `2507865340`): none receive a false `250` prefix. They yield distinct
   non-colliding keys rather than wrong matches — the intended interim behavior.

**No code written. Not committed. Awaiting the Phase 2 fix brief.**
