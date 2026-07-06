# R4a Report — Order Phone E.164 Normalization (data-only) — v3.10.42

**Nature:** data migration **already applied + verified on live**; this release is a
version bump + documentation only — **no plugin code logic changed**.
**Scope:** rewrote legacy non-E.164 `wp_dishdash_orders.customer_phone` values to `+250…`
so the order-side match key aligns with the R3 customers table. **No `customer_id` /
attribution / schema changes.**

> Phases A–D ran on the cPanel server (Claude Code has no live-DB access). Results below
> are the confirmed live output.

## Migrated — 6 rows → clean `+250…`
| id | before | after |
|---|---|---|
| 1  | `0780006956`  | `+250780006956` |
| 25 | *(0-prefixed / bare 250, per dry-run)* | `+250…` (clean, confirmed) |
| 26 | *(0-prefixed / bare 250, per dry-run)* | `+250…` (clean, confirmed) |
| 35 | *(0-prefixed / bare 250, per dry-run)* | `+250…` (clean, confirmed) |
| 44 | `0787538546`  | `+250787538546` |
| 57 | `0788496581`  | `+250788496581` |

(Transform: `0XXXXXXXXX`→`+250`+last 9; `250XXXXXXXXX`→prefix `+`. All six confirmed
against the Phase B dry-run and re-read in Phase D.)

## Excluded — truncated / unrecoverable (8 rows, left untouched)
ids **38, 43, 88, 90, 91, 110, 111, 112** — stored as `+2507865340` (7 significant digits)
or `+25078562304` (8 digits). Missing digits cannot be reconstructed, so these are left
intact per the R3 malformed-row precedent (never guess/fabricate a phone).

## Excluded — foreign, preserved (1 row)
id **116** `+674069873633` (Nauru, +674) — a valid international number; **not coerced to
+250** (consistent with the R3-fix normalizer). Its len-13 `+…` value correctly sits with
the clean set by prefix but is a foreign number, not Rwandan.

## Verification (live, Phase D — actual)
- **Non-clean count:** `15 → 9` (the 9 = 8 truncated + 1 foreign, all intentional exclusions).
- **Format distribution:** 113 clean Rwandan E.164 (`+250` + 9 digits, len 13) + 8 truncated
  (7×len-11 `+2507865340`, 1×len-12 `+25078562304`) + 1 foreign (`+674069873633`, len 13) =
  **122 total**. (The Nauru row is len-13 so it counts inside the 114 `+…` rows; true
  clean-Rwandan = 113. Everything reconciles: 113 + 8 + 1 = 122.)
- **customer_id / attribution:** untouched. The 7 conflict orders (3, 52, 81, 83, 97, 98,
  109) not touched.

## Backup
`~/dd-backups/pre-r4a-20260706-155534.sql` (7.4M, non-empty, taken before the first UPDATE).

## Flag forward (NOT R4a scope)
The 8 truncated rows all share the value `+2507865340` across **distinct** orders — that
pattern points to a **write-path truncation bug at insert time**, not independent typos.
Belongs to the parked write-path / guest-linkage investigation, not this data release.

## Out of scope (confirmed not done)
- No `customer_id` / attribution write (parked pending the user-1/user-14 conflict decision).
- No read-side phone-anchor OR (that's R4b).
- No customers-table change (R3 owns it).

---

## Release (v3.10.42)
- Version bumped: `dish-dash.php` header + `DD_VERSION` constant → `3.10.42`; CLAUDE.md
  `Last updated` + Current State + release table updated.
- Commit: `git add dish-dash.php CLAUDE.md report.md` →
  `v3.10.42 — R4a: normalize 6 legacy order phones to E.164; document 9 excluded (8 truncated, 1 foreign)` → push `main`.
- GitHub release: tag `v3.10.42`, title "R4a — Order phone E.164 normalization".
