# INVESTIGATION — Reservations: `status` vs `deposit_status` conflation

**Phase 1, read-only.** Every claim carries `file:line`. Live-DB facts are marked **PENDING (server)** with the
exact command.

---

## TL;DR

- Two **independent** columns encode two **different** things: `status` = booking workflow state
  (pending/confirmed/cancelled/no_show/auto_cancelled), `deposit_status` = money state
  (none/pending/claimed/paid/failed).
- **No writer conflates them.** Each writer sets its own column; the two just aren't correlated. The conflation
  is entirely on the **display + human-semantics** side: the prominent **status badge reads only `status`**
  (`class-dd-reservations-admin.php:373`), so a booking a human "Confirmed" shows **"Confirmed"** even while the
  separate Deposit column says **"🙋 Claimed (unverified)"** or **"⏳ Awaiting"**. To a restaurant, "Confirmed"
  reads as "paid & secured" — it isn't. → **candidate (a)**.
- **Money amplifier:** the "Send Confirmation" WhatsApp is gated **only** on `status==='confirmed'`
  (`:407`) — it tells the customer *"your table is booked! 🎉"* regardless of whether the deposit landed.
- **Auto-cancel is decoupled** — it keys on `deposit_status` and **never reads `status`**
  (`class-dd-reservations-module.php:547-556`). So a "Confirmed" booking with an unpaid deposit is **still
  auto-cancelled** on schedule, silently flipping to `auto_cancelled`. "Confirmed" is therefore not just
  misleading, it's non-durable.
- This is **not one fix**. Display (make "Confirmed" tell the truth) and message-gating (don't promise a table on
  an unpaid deposit) are separable. Proposed split in §7.

---

## 1. The schema (`install.php:209-250`, table `dishdash_reservations`)

State-encoding columns:

| Column | `install.php:` | Type | Default | Meaning |
|---|---|---|---|---|
| `status` | `:219` | `VARCHAR(20)` | `'pending'` | Booking workflow state |
| `deposit_required` | `:234` | `TINYINT(1)` | `0` | Whether THIS booking owes a deposit |
| `deposit_amount` | `:235` | `INT UNSIGNED` | `0` | Fixed deposit owed (RWF) |
| `deposit_status` | `:236` | `VARCHAR(20)` | `'none'` | Money state |
| `deposit_paid_at` | `:237` | `DATETIME` | `NULL` | When restaurant marked paid |
| `payment_ref` | `:238` | `VARCHAR(100)` | `NULL` | Unused by the deposit flow (no writer found) |

**`status` value set** (VARCHAR, not ENUM — values are convention, not DB-enforced):
- Written in code: `pending` (booking insert `module:112`), `confirmed` / `cancelled` / `no_show` (admin actions,
  allow-lists `admin:50`, `module:362`, bulk `module:525`), `auto_cancelled` (cron `module:562`).
- Labelled in the admin map `admin:168-175`: adds `pending_payment => 'Awaiting Payment'` — a **phantom label**;
  no writer ever sets `status='pending_payment'` (grep: zero writes). Dead map entry.

**`deposit_status` value set** (VARCHAR; convention documented at `module:106`
`none|pending|claimed|paid|failed`):
- `none` (default; no deposit), `pending` (booking insert when deposit enabled, `module:111`), `claimed`
  (customer claim `module:487`), `paid` (admin mark-paid `module:428`), `failed` (auto-cancel `module:562`).
- Admin labels add `refunded => '↩ Refunded'` (`admin:388`) — no writer sets it; label-only.

**Other confirmation/payment fields:** `deposit_required` (per-booking gate), `deposit_paid_at`, `payment_ref`
(unused). No other column encodes confirmation/payment state.

---

## 2. Every writer

| Writer `file:line` | Trigger | writes `status` → | writes `deposit_status` → |
|---|---|---|---|
| Booking insert `module:115-131` | Customer submits reservation | `'pending'` (`:112`) | `'pending'` if `dd_reservation_deposit_enabled` else `'none'` (`:111`) |
| `ajax_update_status()` `module:374` | Admin **Confirm / Cancel / No-show** button (AJAX) | `$status` ∈ {pending,confirmed,cancelled,no_show} (`:362`) | **untouched** |
| Admin POST fallback `admin:55` | Same buttons, non-JS POST path | `$new_status` ∈ same set (`:50`) | **untouched** |
| `ajax_bulk_action()` `module:526-527` | Admin **bulk** Confirm/Cancel/No-show | `$action` ∈ {confirmed,cancelled,no_show} | **untouched** |
| `ajax_mark_deposit_paid()` `module:426-428` | Admin **"✅ Mark deposit paid"** (v3.10.66) | **untouched** | `'paid'` (+ `deposit_paid_at`), only from `pending`/`claimed` (`:425`) |
| `ajax_claim_deposit()` `module:485-487` | Customer **"I have paid"** claim (v3.10.65) | **untouched** | `'claimed'`, only from `pending` (`:484`) |
| `run_autocancel()` cron `module:560-562` | WP-Cron single event, deposit window elapsed | `'auto_cancelled'` | `'failed'` (**both**, only when `deposit_status IN (pending,claimed)`) |

**Key facts:**
- **Only the cron writes both columns together.** Every human action writes exactly one column.
- **No code path writes `status='confirmed'` on a deposit claim or on mark-paid.** `status='confirmed'` comes
  *solely* from the admin Confirm button / bulk-confirm (`module:374`, `admin:55`, `module:527`), which do **not**
  look at `deposit_status`. (Confirms the CLAUDE.md flag.)
- Conversely, marking a deposit paid does **not** advance `status` to `confirmed`. The two are orthogonal writes.

---

## 3. Every reader

| Reader `file:line` | Reads | Drives |
|---|---|---|
| **Status badge** `admin:373-375` | `status` **only** | The prominent per-row badge the user sees (`dd-res-badge--{status}` + label from `$statuses` `:168`) |
| **Deposit column** `admin:380-396` | `deposit_status` (+ `deposit_required`, `deposit_amount`) | Separate column: `⏳ Awaiting / 🙋 Claimed (unverified) / ✅ Paid / ✗ Failed`; shows `—` when `deposit_required=0` (`:393`) |
| **"Mark deposit paid" button gate** `admin:494-495` | `deposit_required` + `deposit_status ∈ {pending,claimed}` | Whether the amber button renders (per-row) |
| **Confirm/Cancel/No-show button gates** `admin:473/478/483` | `status` | Hides the button matching the current status |
| **"Send Confirmation" WhatsApp** `admin:407-471` | `status` **only** (`if $r->status==='confirmed'`) | Builds *"RESERVATION CONFIRMED ✅ … your table is booked! 🎉"* — **ignores `deposit_status`** |
| **"Today's confirmed covers" KPI** `admin:158-164` | `status='confirmed'` | KPI number + covers sum |
| **Filter tabs** `admin:249-262` | `status` (via `?status=`) | Tab filtering; a `confirmed` tab lists all `status='confirmed'` regardless of deposit |
| **Reservations analytics** `analytics-reservations.php:33,123,125` | `status='confirmed'` (`:123/:125` also split by `deposit_required`, but **not** by `deposit_status`) | Confirmed-count + deposit-required/none breakdown |
| **Main dashboard** `dashboard.php:107,358` | `status IN ('confirmed','pending')` / `status='confirmed'` | Today's reservations KPI + a confirmed metric |
| **Auto-cancel query** `module:547-556` | `deposit_required=1 AND deposit_status IN (pending,claimed)` — **does NOT read `status`** | Whether the cron cancels |
| **Customer-facing** | — | No live customer reservation-status page. Customer sees only the WhatsApp confirmation (admin-sent, gated on `status` `:407`) and the booking-time confirmation. |

---

## 4. Where the conflation actually is

**Candidate (a) — confirmed.** With refinements:

- **Write side:** the admin Confirm button writes `status='confirmed'` with **no `deposit_status` check** —
  `ajax_update_status()` `module:374` (and its POST twin `admin:55`, bulk `module:527`). Allow-list is purely
  workflow (`module:362`), deposit is never consulted.
- **Read side:** the headline **status badge reads only `status`** — `admin:373-375`. So the confirmation signal
  a human scans is blind to money state.

**Rule the others out:**
- **(b) mislabelled field** — *No.* Each column renders under its own correct label: the status badge says
  "Confirmed" for `status`, the Deposit column says "Claimed (unverified)/Paid" for `deposit_status`
  (`admin:380-396`). Neither is displayed under the other's label.
- **(c) a writer sets both when it should set one** — *No.* Every human writer sets exactly one column (§2). The
  only dual-write is the cron (`module:562`), which is correct (cancel + fail together).
- **(d) a computed value collapses the two** — *No.* There is no derived/combined status; the two columns are
  read independently in two separate cells.

**So the defect is: the two columns are correct and independent, but the UI presents `status` as the primary
"is this booking good?" signal while money state lives in a second column that `status` neither reflects nor
gates.** A restaurant reading "Confirmed" reasonably assumes secured/paid. Amplified by the confirmation WhatsApp
(`admin:407`) promising a booked table on `status` alone, and undercut by auto-cancel (`module:547`) which can
later flip that same "Confirmed" row to `auto_cancelled` because it only trusts `deposit_status`.

---

## 5. What SHOULD the admin see? (raw material only — not designing the fix)

- **Can a booking legitimately be `confirmed` without a deposit?** **Yes.** `deposit_required` is per-booking
  (`install.php:234`), set at insert from `dd_reservation_deposit_enabled` (`module:109`). When deposits are off
  (or for pre-deposit-era rows) `deposit_required=0`, `deposit_status='none'`, and "Confirmed" with no deposit is
  entirely valid — the Deposit column correctly shows `—` (`admin:393-394`). So any fix must **not** treat
  "confirmed + no paid deposit" as wrong when `deposit_required=0`; the problem case is specifically
  **`deposit_required=1 AND status='confirmed' AND deposit_status<>'paid'`**.
- **Is `deposit_status` meaningful when no deposit is owed?** No — it holds `'none'` (default `install.php:236`)
  and the Deposit column short-circuits on `deposit_required` before reading it (`admin:381`). `'none'` = N/A.
- **Existing rows where `status='confirmed'` but the deposit is unpaid** — **PENDING (server):**
  ```bash
  wp db query "SELECT
     SUM(status='confirmed' AND deposit_required=1 AND deposit_status<>'paid' AND is_test=0) AS confirmed_unpaid_deposit,
     SUM(status='confirmed' AND deposit_required=1 AND deposit_status='claimed' AND is_test=0) AS confirmed_claimed_only,
     SUM(status='confirmed' AND deposit_required=1 AND deposit_status='paid'   AND is_test=0) AS confirmed_paid,
     SUM(status='confirmed' AND deposit_required=0 AND is_test=0)                             AS confirmed_no_deposit
   FROM wp_dishdash_reservations" --skip-column-names
  ```
  (Adjust `wp_` prefix if different.) `confirmed_unpaid_deposit > 0` quantifies live exposure.

---

## 6. Blast radius — everything keyed off `status='confirmed'`

If a fix changes what "confirmed" means or what gets written on Confirm, these move:

- **Auto-cancel** — **NOT affected.** `run_autocancel()` reads only `deposit_status`/`deposit_required`
  (`module:547-556`); it ignores `status`. Decoupling display from money will not touch cancellation. (Good —
  the safety net already lives on the correct column.)
- **Availability/capacity** — **NOT affected.** `ajax_check_availability()` is a stub returning `available:true`
  (`module:350-353`); no capacity engine reads `status`.
- **"Today's confirmed covers" KPI** — `admin:158-164` counts `status='confirmed'`. Today it counts
  deposit-unpaid confirmations too (may overstate covers that will auto-cancel).
- **Reservations analytics** — `analytics-reservations.php:33` (confirmed count), `:123/:125` (confirmed split by
  `deposit_required`, **not** by paid). A paid-vs-unpaid confirmed distinction does not exist in reporting yet.
- **Main dashboard** — `dashboard.php:107` (today `status IN (confirmed,pending)`), `:358`
  (`status='confirmed'`).
- **Filter tabs** — `admin:249-262`; a `confirmed` tab currently mixes paid and unpaid deposit bookings.
- **Confirmation WhatsApp** — `admin:407-471`; gated on `status` alone.
- **Confirm-button visibility** — `admin:473` hides Confirm once `status='confirmed'`.

Nothing safety-critical (cancel, capacity) keys off `status`, so a display/semantics fix is low-risk to the
money mechanic. The reporting KPIs are the only numeric consumers that would shift meaning.

---

## 7. Release decomposition (one fix per release)

This is **not** one change. Ranked by what a prospect demo exposes first:

**R1 — Display: make the admin row tell the truth (HIGHEST demo exposure, lowest risk).**
Read-side only, `class-dd-reservations-admin.php`. Surface deposit state in/next to the status badge so a
"Confirmed" row with `deposit_required=1 AND deposit_status<>'paid'` visibly reads as *not secured* (e.g. a
warning treatment on the status cell, or a combined indicator) — the raw material is already in the row
(`$r->status`, `$r->deposit_required`, `$r->deposit_status`). Touches no writer, no cron, no reporting. This is
the thing a demo sees: a green "Confirmed" beside "🙋 Claimed (unverified)". **Do first.**

**R2 — Money message: don't promise a table on an unpaid deposit (HIGH money-safety, separable).**
Read-side gate on the confirmation WhatsApp (`admin:407`): when `deposit_required=1 AND deposit_status<>'paid'`,
the "Send Confirmation" message should not assert *"your table is booked!"* (either withhold it or change wording
— design deferred). Independent of R1: R1 changes what the admin sees, R2 changes what the customer is told.
Separable because they touch different outputs (badge vs wa.me message) even though both live in the same file.

**R3 — Write/relabel semantics (OPTIONAL, deferred, decision-gated).**
The deeper question of whether "Confirmed" should be *blocked* until the deposit is paid, or the workflow
relabelled/decoupled (e.g. a derived "Secured" state), or the phantom `pending_payment`/`refunded` labels removed.
This is a behavior + data-meaning change with reporting blast radius (§6) and needs a product decision. Do **not**
bundle with R1/R2. Auto-cancel already protects the money regardless (`module:547`), so there is no urgency to
change the write path — the urgency is purely that the **display and the customer message lie**.

**Recommended order:** R1 (display truth) → R2 (message safety) → R3 (optional semantics), each its own release.
R1 is the single change that removes the demo-visible contradiction; R2 removes the customer-facing false promise.

---

## Pending server checks (consolidated)
1. `wp db query "SELECT … confirmed_unpaid_deposit …"` (full query in §5) — live count of `confirmed` bookings with an unpaid/claimed required deposit.
2. (context) `wp option get dd_reservation_deposit_enabled` — are new bookings currently requiring a deposit at all? Determines whether R1/R2 affect new inbound bookings or only the existing backlog.
