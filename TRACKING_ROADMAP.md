# 🧠 Dish Dash — Behavior Tracking Roadmap

> Last updated: v3.1.13 (2026-04-14)
> Status: Baseline tracking live. Expansion planned in 4 releases.

## 🎯 Why tracking matters

Dish Dash's long-term vision is an AI-powered ordering system that learns user behavior and reduces time-to-order. Every interaction must be logged. The AI layer (Phase 8) is only as smart as the data we collect today. **Start logging everything now, even before building AI features.**

All events are stored in `wp_dishdash_user_events` with fields: `id`, `user_id`, `session_id`, `event_type`, `product_id`, `category_id`, `metadata` (JSON), `timestamp`.

---

## ✅ Currently Tracked (as of v3.1.13)

| Event | Description | Fires from |
|---|---|---|
| `view_product` | Product card viewed/in viewport | tracking.js |
| `view_category` | Category clicked (carousel or deep link) | menu-page.js, frontend.js |
| `search` | Search query typed | search.js |
| `add_to_cart` | Product added to cart | cart.js |

### Baseline metrics (April 14, 2026)
- view_product: 3,157
- view_category: 204
- search: 38
- add_to_cart: 16
- **Raw conversion rate**: 0.5% (add_to_cart / view_product)

Run this query weekly to monitor growth:
```sql
SELECT event_type, COUNT(*) as count 
FROM wp_dishdash_user_events 
GROUP BY event_type;
```

---

## 🚀 Release A — Cart & Order Funnel (HIGH PRIORITY, NEXT)

**Why:** Without funnel tracking, we cannot measure conversion rate or identify where users drop off. This is the most important missing data.

| Event | Fires when | Metadata needed |
|---|---|---|
| `remove_from_cart` | User removes an item | product_id, quantity, reason if known |
| `cart_viewed` | Cart drawer/page opened | cart_total, item_count |
| `checkout_started` | User clicks "Checkout" | cart_total, item_count, items[] |
| `checkout_step` | User completes a checkout step | step_name (details/delivery/payment), time_on_step |
| `checkout_abandoned` | Checkout started but not completed after N min | last_step, cart_value |
| `order_placed` | Order successfully submitted | order_id, total, items[], payment_method, delivery_or_pickup |
| `order_failed` | Payment/submission failed | error_type, step |

**Target metrics this unlocks:**
- View → Cart conversion
- Cart → Checkout conversion
- Checkout → Order completion
- Abandonment rate at each step
- Average order value
- Most common drop-off step

---

## 🍽 Release B — Reservation Tracking (HIGH PRIORITY, PARALLEL TO A)

**Why:** Reservations signal high-intent, often group/special-occasion customers. Valuable for:
- Predicting busy hours/days (staffing, inventory)
- Upsell opportunities (pre-order dishes, set menus)
- No-show prediction (send reminders)
- Customer segmentation (regular vs occasional diners)

| Event | Fires when | Metadata needed |
|---|---|---|
| `reservation_form_viewed` | Reservation form displayed | source_page, entry_point |
| `reservation_started` | User starts filling form | — |
| `reservation_date_selected` | User picks a date | date, day_of_week, days_from_now |
| `reservation_time_selected` | User picks a time | time, meal_period (breakfast/lunch/dinner) |
| `reservation_guests_selected` | User picks group size | guest_count, group_bucket (solo/couple/small/large) |
| `reservation_submitted` | Reservation booked | full details (date, time, guests, special requests, contact) |
| `reservation_abandoned` | Form started, not submitted after N min | last_field_completed |
| `reservation_cancelled` | User cancels their booking | cancellation_time, hours_before_booking |
| `reservation_modified` | User changes date/time/guests | what_changed |

**Target metrics this unlocks:**
- Reservation funnel conversion rate
- Popular dining times (AI can suggest slots)
- Average group size by day
- Abandonment drop-off points
- No-show patterns (cross-reference with attendance data later)
- Special occasion signals (special_requests text field — keywords like "birthday", "anniversary")

---

## 🔍 Release C — Search & Engagement Intelligence

**Why:** Tells us what users want and how they browse. Critical input for AI recommendations.

| Event | Fires when | Metadata needed |
|---|---|---|
| `search_no_results` | Query returned zero items | query_string, nearest_match |
| `search_result_click` | User clicks a search result | query_string, clicked_product_id, result_position |
| `product_click` | User taps a product card (vs just viewing) | product_id, source (grid/search/recommendation) |
| `category_scroll_depth` | How far user scrolled in a category | scroll_percent, products_viewed |
| `session_start` | New session begins | entry_url, referrer, device_type, is_returning |
| `session_end` | Session closes (N min inactivity) | session_duration, pages_viewed, actions_taken |
| `filter_applied` | User uses any filter (when we add them) | filter_type, filter_value |

**Target metrics this unlocks:**
- Menu gaps (what people search for but we don't sell)
- Most effective product positioning
- Session quality (engaged vs bounced)
- Browse depth before conversion

---

## 📍 Release D — Contextual & Social Signals

**Why:** These are the AI-magic signals. Location, time patterns, communication channel preferences — feeds personalization.

| Event | Fires when | Metadata needed |
|---|---|---|
| `phone_click` | User taps phone number link | source_page |
| `whatsapp_click` | User taps WhatsApp button | source_page, message_context |
| `location_granted` | User allows location access | approximate_location |
| `location_denied` | User denies location | — |
| `repeat_visit` | Returning user identified | days_since_last_visit, visit_count |
| `time_of_day_bucket` | Session tagged by meal period | period (breakfast/brunch/lunch/tea/dinner/late), local_time |
| `device_type` | Captured per session | mobile/tablet/desktop, OS, browser |
| `social_share` | User shares a product/page | product_id, share_channel |

**Target metrics this unlocks:**
- Off-platform conversion (calls, WhatsApp)
- Delivery zone behavior patterns
- Customer lifecycle (new → returning → loyal)
- Time-of-day demand patterns (feeds kitchen prep AI)
- Device-specific UX issues

---

## 🧠 AI Layer Readiness Checklist

The AI features in Phase 8 (Analytics + AI Insights) require:

- [ ] At least 3 months of Release A + B data collected
- [ ] At least 1,000 completed orders logged with full item/time/user data
- [ ] At least 100 completed reservations with timing patterns
- [ ] User profile table populated (Release C feeds this)
- [ ] Session data for at least 5,000 unique sessions
- [ ] Repeat visitor pattern established (Release D)

**Until these thresholds are met, AI features will produce noise, not insight.** Collecting well-labeled data is the moat.

---

## 📐 Implementation Rules

1. **Never block the UI for tracking.** All events fire asynchronously. If tracking AJAX fails, the user never knows.
2. **No PII in events.** Never log phone numbers, emails, or addresses in the `metadata` JSON. Store only IDs that reference other tables.
3. **Session ID for guests.** Anonymous users get a session_id cookie (UUID) so we can stitch their journey before they authenticate.
4. **All events go through one endpoint.** `dd_track_event` AJAX action only. Never create event-specific endpoints — keeps nonce/auth logic in one place.
5. **Respect user privacy.** Add a `do_not_track` option in user account that disables all event logging for that user.
6. **Event definitions live in one file.** Create `modules/tracking/events.php` as the single source of truth for event names and expected metadata schemas.
7. **Weekly health check.** The baseline query above should be run weekly; event counts should never decrease between checks (except after database maintenance).

---

## 🗓 Suggested Release Schedule

| Release | Timing | Scope |
|---|---|---|
| Release A | After mobile version ships | Cart & Order funnel |
| Release B | Parallel with A OR after | Reservation tracking |
| Release C | 4–6 weeks after A/B | Search & engagement |
| Release D | 8–12 weeks after C | Contextual signals |
| AI Phase 8 | 3+ months of A/B data | Reports & recommendations |

Do NOT accelerate this schedule. Each release needs production data to validate the event schema before layering more events on top.
