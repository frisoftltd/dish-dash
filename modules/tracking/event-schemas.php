<?php
/**
 * File: modules/tracking/event-schemas.php
 * Purpose: Single source of truth for event metadata schemas.
 *
 * Each event type has its own schema_version (integer). When you change
 * an event's metadata structure, bump the constant in dish-dash.php
 * (DISHDASH_SCHEMA_VIEW_EVENT, DISHDASH_SCHEMA_SEARCH_EVENT, etc.)
 * AND update the corresponding entry below.
 *
 * Python imports use these schemas to handle version differences:
 *   if event.schema_version >= 2: parse new metadata format
 *   else: parse legacy format
 *
 * Schema file v1.1 — removed dead schemas: reorder, deposit_failed
 * Last modified: v3.4.9
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * Schema contract:
 * - 'required' = keys that MUST be inside the `meta` JSON column for this event type
 * - 'optional' = keys that MAY be inside `meta`
 * - Dedicated DB columns (product_id, category_id, user_id, session_id) are NOT listed here
 *   — they are row-level fields, not metadata fields
 * - Validation strips any meta keys not in required ∪ optional before DB insert
 */
return [
    'view_product' => [
        'current_version' => DISHDASH_SCHEMA_VIEW_EVENT,
        'metadata_schema' => [
            'required' => [],
            'optional' => ['source', 'position'],
        ],
    ],
    'view_category' => [
        'current_version' => DISHDASH_SCHEMA_VIEW_EVENT,
        'metadata_schema' => [
            'required' => ['slug'],
            'optional' => ['name', 'source'],
        ],
    ],
    'search' => [
        'current_version' => DISHDASH_SCHEMA_SEARCH_EVENT,
        'metadata_schema' => [
            'required' => ['query'],
            'optional' => ['result_count'],
        ],
    ],
    'add_to_cart' => [
        'current_version' => DISHDASH_SCHEMA_CART_EVENT,
        'metadata_schema' => [
            'required' => ['qty'],
            'optional' => ['price', 'source'],
        ],
    ],
    'cart_open' => [
        'current_version' => DISHDASH_SCHEMA_CART_EVENT,
        'metadata_schema' => [
            'required' => [],
            'optional' => ['item_count', 'trigger'],
        ],
    ],
    'cart_quantity_change' => [
        'current_version' => DISHDASH_SCHEMA_CART_EVENT,
        'metadata_schema' => [
            'required' => ['product_id', 'new_qty'],
            'optional' => ['old_qty', 'direction'],
        ],
    ],
    'cart_abandon' => [
        'current_version' => DISHDASH_SCHEMA_CART_EVENT,
        'metadata_schema' => [
            'required' => [],
            'optional' => ['item_count', 'total', 'time_open_seconds'],
        ],
    ],
    'remove_from_cart' => [
        'current_version' => DISHDASH_SCHEMA_CART_EVENT,
        'metadata_schema' => [
            'required' => [],
            'optional' => ['product_id', 'qty'],
        ],
    ],
    'checkout_start' => [
        'current_version' => 1,
        'metadata_schema' => [
            'required' => ['item_count', 'subtotal'],
            'optional' => ['cart_key'],
        ],
    ],
    'order' => [
        'current_version' => DISHDASH_SCHEMA_ORDER_EVENT,
        'metadata_schema' => [
            'required' => ['order_id', 'total'],
            'optional' => ['items', 'payment_method'],
        ],
    ],
    'page_view' => [
        'current_version' => DISHDASH_SCHEMA_VIEW_EVENT,
        'metadata_schema' => [
            'required' => ['url'],
            'optional' => ['referrer'],
        ],
    ],
    'reservation_made' => [
        'current_version' => 1,
        'metadata_schema' => [
            'required' => [ 'date', 'time', 'session', 'guests' ],
            'optional' => [ 'source' ],
        ],
    ],
    // NOTE: Dead path — deposit feature disabled ($deposit_enabled = 0 in reservations module).
    // Schemas retained for when deposit is re-enabled.
    'deposit_initiated' => [
        'current_version' => 1,
        'metadata_schema' => [
            'required' => [ 'booking_ref', 'amount' ],
            'optional' => [ 'deposit_type', 'wc_order_id' ],
        ],
    ],
    'deposit_paid' => [
        'current_version' => 1,
        'metadata_schema' => [
            'required' => [ 'booking_ref' ],
            'optional' => [ 'wc_order_id' ],
        ],
    ],
    'booking_auto_cancelled' => [
        'current_version' => 1,
        'metadata_schema' => [
            'required' => [ 'booking_ref' ],
            'optional' => [ 'hours_elapsed' ],
        ],
    ],
];
