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
 * Last modified: v3.1.17
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
    'remove_from_cart' => [
        'current_version' => DISHDASH_SCHEMA_CART_EVENT,
        'metadata_schema' => [
            'required' => [],
            'optional' => ['product_id', 'qty'],
        ],
    ],
    'order' => [
        'current_version' => DISHDASH_SCHEMA_ORDER_EVENT,
        'metadata_schema' => [
            'required' => ['order_id', 'total'],
            'optional' => ['items', 'payment_method'],
        ],
    ],
    'reorder' => [
        'current_version' => DISHDASH_SCHEMA_ORDER_EVENT,
        'metadata_schema' => [
            'required' => ['original_order_id'],
            'optional' => [],
        ],
    ],
    'page_view' => [
        'current_version' => DISHDASH_SCHEMA_VIEW_EVENT,
        'metadata_schema' => [
            'required' => ['url'],
            'optional' => ['referrer'],
        ],
    ],
];
