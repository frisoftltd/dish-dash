<?php
/**
 * File:    admin/pages/csv-menu-import.php
 * Purpose: CSV menu bulk import tool — wipes ALL WooCommerce products and
 *          reimports from an uploaded CSV. INTERNAL (Fri Soft) USE ONLY —
 *          never intended for restaurant owners/managers.
 *
 *          Two-step flow, modeled on scripts/dd-r3-migrate.php's
 *          dry-run-then-commit pattern:
 *            Step 1 (Preview) — parse + validate the CSV, no writes.
 *            Step 2 (Commit)  — only after an explicit typed confirmation,
 *                                wipes all products then reimports.
 *
 * Rendered by: DD_Admin::render_tools() (appended after event-health.php,
 *              same "Tools" admin page — admin.php?page=dish-dash-tools)
 *
 * Access: manage_options AND the user must NOT hold the dd_restaurant_owner
 *         or dd_restaurant_manager role. Both those roles are granted
 *         manage_options directly (install.php), so a plain manage_options
 *         check is not sufficient here — see the investigation this tool
 *         shipped from (spice-selector role-gating brief).
 *
 * CSV columns: name, regular_price, description, short_description,
 *              category, image_url (optional), prep_time (optional).
 *              No spice column — spice visibility is category-driven
 *              (dd_spice_included_categories, v3.13.4) and untouched here.
 *
 * What this tool does NOT touch: product_cat terms (categories are
 * upserted by name, never deleted) and media attachments (never deleted;
 * sideloaded images are de-duped across rows/runs via _dd_csv_source_url
 * postmeta).
 *
 * Last modified: v3.13.5
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Access gate — role-exclusion first, capability second. ──────────────
// dd_restaurant_owner / dd_restaurant_manager both hold manage_options
// directly (install.php), so checking manage_options alone would let them
// in via direct URL even though the menu link is CSS-hidden for them.
$dd_csvi_user               = wp_get_current_user();
$dd_csvi_is_restaurant_role = array_intersect( [ 'dd_restaurant_owner', 'dd_restaurant_manager' ], (array) $dd_csvi_user->roles );
if ( $dd_csvi_is_restaurant_role || ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Access denied.', 'dish-dash' ) );
}

// ─────────────────────────────────────────────────────────────────────────
//  HELPERS (guarded — this file is included once from render_tools(), but
//  guard anyway to match the codebase's helper-function convention).
// ─────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'dd_csvi_upsert_category' ) ) {
    /**
     * Find-or-create a product_cat term by exact name match. Reusing an
     * existing term (rather than always inserting) is what keeps category
     * slugs stable across repeated wipe/reimport runs — dd_spice_included_categories
     * is slug-keyed, so a stable slug is what keeps spice config intact.
     *
     * @return int Term ID, or 0 on failure.
     */
    function dd_csvi_upsert_category( string $name ): int {
        $existing = get_term_by( 'name', $name, 'product_cat' );
        if ( $existing instanceof WP_Term ) {
            return (int) $existing->term_id;
        }
        $result = wp_insert_term( $name, 'product_cat' );
        if ( is_wp_error( $result ) ) {
            return 0;
        }
        return (int) $result['term_id'];
    }
}

if ( ! function_exists( 'dd_csvi_get_or_sideload_image' ) ) {
    /**
     * Reuse an attachment already sideloaded from this exact URL (tagged via
     * _dd_csv_source_url on a prior run/row), else download + attach it.
     * Never throws — returns 0 on any failure so the calling row can skip
     * the image and continue rather than failing the whole row.
     *
     * @return int Attachment ID, or 0 on failure/skip.
     */
    function dd_csvi_get_or_sideload_image( string $url, int $product_id ): int {
        global $wpdb;

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dd_csv_source_url' AND meta_value = %s LIMIT 1",
            $url
        ) );
        if ( $existing_id > 0 && get_post( $existing_id ) ) {
            return $existing_id;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            dd_log( 'CSV import: image sideload failed for ' . $url . ' — ' . $attachment_id->get_error_message() );
            return 0;
        }

        update_post_meta( (int) $attachment_id, '_dd_csv_source_url', esc_url_raw( $url ) );
        return (int) $attachment_id;
    }
}

if ( ! function_exists( 'dd_csvi_parse_and_validate' ) ) {
    /**
     * STEP 1 — parse the uploaded CSV, validate every row, write nothing.
     * Valid rows are stashed in a short-lived transient (avoids re-posting
     * a possibly large file for step 2); the token is handed back to the
     * confirm form as a hidden field.
     *
     * @return array{
     *   file_error?: string,
     *   token?: string, existing_count?: int, valid_count?: int,
     *   error_rows?: array, has_orders?: bool
     * }
     */
    function dd_csvi_parse_and_validate(): array {
        if ( empty( $_FILES['dd_csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['dd_csv_file']['tmp_name'] ) ) {
            return [ 'file_error' => __( 'No CSV file was uploaded.', 'dish-dash' ) ];
        }

        $handle = fopen( $_FILES['dd_csv_file']['tmp_name'], 'r' );
        if ( ! $handle ) {
            return [ 'file_error' => __( 'Could not read the uploaded file.', 'dish-dash' ) ];
        }

        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            return [ 'file_error' => __( 'CSV file is empty.', 'dish-dash' ) ];
        }

        $header    = array_map( static fn( $h ) => sanitize_key( trim( (string) $h ) ), $header );
        $col_index = array_flip( $header );

        if ( ! isset( $col_index['name'], $col_index['regular_price'] ) ) {
            fclose( $handle );
            return [ 'file_error' => __( 'CSV is missing required column(s): name, regular_price.', 'dish-dash' ) ];
        }

        $valid_rows = [];
        $errors     = [];
        $row_num    = 1; // header = row 1

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $row_num++;

            if ( count( array_filter( $row, static fn( $v ) => '' !== trim( (string) $v ) ) ) === 0 ) {
                continue; // fully blank line
            }

            $get = static function ( string $col ) use ( $row, $col_index ) {
                return isset( $col_index[ $col ], $row[ $col_index[ $col ] ] ) ? trim( (string) $row[ $col_index[ $col ] ] ) : '';
            };

            $name          = $get( 'name' );
            $regular_price = $get( 'regular_price' );
            $description   = $get( 'description' );
            $short_desc    = $get( 'short_description' );
            $category      = $get( 'category' );
            $image_url     = $get( 'image_url' );
            $prep_time_raw = $get( 'prep_time' );

            $row_errors = [];
            if ( '' === $name ) {
                $row_errors[] = __( 'Missing name', 'dish-dash' );
            }
            if ( '' === $regular_price ) {
                $row_errors[] = __( 'Missing regular_price', 'dish-dash' );
            } elseif ( ! is_numeric( $regular_price ) || (float) $regular_price < 0 ) {
                $row_errors[] = __( 'regular_price is not a valid non-negative number', 'dish-dash' );
            }
            if ( '' !== $image_url && ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
                $row_errors[] = __( 'image_url is not a valid URL', 'dish-dash' );
            }
            if ( '' !== $prep_time_raw && ( ! ctype_digit( $prep_time_raw ) || (int) $prep_time_raw <= 0 ) ) {
                $row_errors[] = __( 'prep_time must be a positive whole number', 'dish-dash' );
            }

            if ( ! empty( $row_errors ) ) {
                $errors[] = [ 'row' => $row_num, 'name' => $name, 'errors' => $row_errors ];
                continue;
            }

            $valid_rows[] = [
                'name'              => sanitize_text_field( $name ),
                'regular_price'     => (float) $regular_price,
                'description'       => wp_kses_post( $description ),
                'short_description' => wp_kses_post( $short_desc ),
                'category'          => sanitize_text_field( $category ),
                'image_url'         => esc_url_raw( $image_url ),
                'prep_time'         => '' !== $prep_time_raw ? (int) $prep_time_raw : 0,
            ];
        }
        fclose( $handle );

        $token = wp_generate_password( 32, false );
        set_transient( 'dd_csv_import_' . $token, $valid_rows, 15 * MINUTE_IN_SECONDS );

        return [
            'token'          => $token,
            'existing_count' => count( wc_get_products( [ 'limit' => -1, 'return' => 'ids' ] ) ),
            'valid_count'    => count( $valid_rows ),
            'error_rows'     => $errors,
            'has_orders'     => count( wc_get_orders( [ 'limit' => 1, 'return' => 'ids' ] ) ) > 0,
        ];
    }
}

if ( ! function_exists( 'dd_csvi_commit' ) ) {
    /**
     * STEP 2 — wipe all WooCommerce products, reimport from the token'd
     * preview rows. Categories and media attachments are never deleted.
     *
     * @return array{fatal?: string, deleted?: int, delete_errors?: array,
     *   created?: int, images_ok?: int, images_skipped?: int, row_log?: array}
     */
    function dd_csvi_commit(): array {
        $site_name    = get_option( 'dish_dash_restaurant_name' ) ?: get_bloginfo( 'name' );
        $confirm_text = isset( $_POST['dd_csv_confirm_text'] ) ? sanitize_text_field( wp_unslash( $_POST['dd_csv_confirm_text'] ) ) : '';
        $confirmed    = isset( $_POST['dd_csv_confirm_checkbox'] );
        $token        = isset( $_POST['dd_csv_token'] ) ? sanitize_text_field( wp_unslash( $_POST['dd_csv_token'] ) ) : '';

        if ( ! $confirmed || '' === $site_name || $confirm_text !== $site_name ) {
            return [ 'fatal' => __( 'Confirmation text did not match the restaurant name, or the confirm checkbox was not checked. Nothing was changed.', 'dish-dash' ) ];
        }

        $rows = get_transient( 'dd_csv_import_' . $token );
        if ( false === $rows ) {
            return [ 'fatal' => __( 'This preview has expired (15-minute limit) or was already committed. Please re-upload the CSV and preview again.', 'dish-dash' ) ];
        }
        delete_transient( 'dd_csv_import_' . $token );

        if ( empty( $rows ) ) {
            return [ 'fatal' => __( 'No valid rows to import — commit blocked to avoid wiping the menu with nothing to replace it.', 'dish-dash' ) ];
        }

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );

        $result = [
            'deleted'        => 0,
            'delete_errors'  => [],
            'created'        => 0,
            'images_ok'      => 0,
            'images_skipped' => 0,
            'row_log'        => [],
        ];

        try {
            // ── Wipe — products (+ their variations) only. Categories and
            // attachments are deliberately left untouched. ──
            foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'ids' ] ) as $pid ) {
                $product = wc_get_product( $pid );
                if ( $product && $product->delete( true ) ) {
                    $result['deleted']++;
                } else {
                    $result['delete_errors'][] = $pid;
                }
            }

            // ── Reimport ──
            foreach ( $rows as $i => $row ) {
                $row_num = $i + 2; // account for header row + 0-index
                try {
                    $product = new WC_Product_Simple();
                    $product->set_name( $row['name'] );
                    $product->set_regular_price( (string) $row['regular_price'] );
                    $product->set_description( $row['description'] );
                    $product->set_short_description( $row['short_description'] );
                    $product->set_status( 'publish' );

                    if ( '' !== $row['category'] ) {
                        $term_id = dd_csvi_upsert_category( $row['category'] );
                        if ( $term_id ) {
                            $product->set_category_ids( [ $term_id ] );
                        }
                    }

                    $product_id = $product->save();

                    if ( $row['prep_time'] > 0 ) {
                        update_post_meta( $product_id, '_dd_prep_time', $row['prep_time'] );
                    }

                    $image_status = 'n/a';
                    if ( '' !== $row['image_url'] ) {
                        $attachment_id = dd_csvi_get_or_sideload_image( $row['image_url'], $product_id );
                        if ( $attachment_id ) {
                            $product->set_image_id( $attachment_id );
                            $product->save();
                            $result['images_ok']++;
                            $image_status = 'ok';
                        } else {
                            $result['images_skipped']++;
                            $image_status = 'failed — skipped';
                        }
                    }

                    $result['created']++;
                    $result['row_log'][] = [ 'row' => $row_num, 'name' => $row['name'], 'status' => 'created', 'image' => $image_status ];
                } catch ( \Throwable $row_e ) {
                    // Row-level failure — skip this row, keep the batch going.
                    $result['row_log'][] = [ 'row' => $row_num, 'name' => $row['name'] ?? '', 'status' => 'error: ' . $row_e->getMessage(), 'image' => 'n/a' ];
                }
            }

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'fatal' => sprintf(
                /* translators: %s: exception message */
                __( 'Import failed and the database was rolled back: %s', 'dish-dash' ),
                $e->getMessage()
            ) ];
        }

        // WooCommerce CRUD writes bypass DD_API's own 5-min transient cache —
        // clear it regardless of outcome so the change is visible immediately.
        if ( class_exists( 'DD_API' ) ) {
            DD_API::clear_cache();
        }

        do_action( 'dd_log_activity', [
            'action'      => 'csv_menu_import',
            'object_type' => 'product',
            'object_id'   => 'bulk',
            'details'     => [ 'deleted' => $result['deleted'], 'created' => $result['created'] ],
        ] );

        return $result;
    }
}

// ─────────────────────────────────────────────────────────────────────────
//  REQUEST HANDLING
// ─────────────────────────────────────────────────────────────────────────

$dd_csvi_preview = null;
$dd_csvi_result  = null;

if ( isset( $_POST['dd_csv_preview_submit'] ) ) {
    check_admin_referer( 'dd_csv_import_preview' );
    $dd_csvi_preview = dd_csvi_parse_and_validate();
}

if ( isset( $_POST['dd_csv_commit_submit'] ) ) {
    check_admin_referer( 'dd_csv_import_commit' );
    $dd_csvi_result = dd_csvi_commit();
}

$dd_csvi_site_name = get_option( 'dish_dash_restaurant_name' ) ?: get_bloginfo( 'name' );
?>
<div class="dd-settings-card">

    <h2>📋 <?php esc_html_e( 'CSV Menu Import', 'dish-dash' ); ?></h2>

    <p style="color:#666;margin-bottom:8px;">
        <?php esc_html_e( 'Internal tool — wipes ALL existing products and reimports from a CSV. Two-step: preview first (no writes), then an explicit typed confirmation to commit.', 'dish-dash' ); ?>
    </p>
    <p style="color:#888;font-size:12px;margin-bottom:20px;">
        <?php esc_html_e( 'Required columns: name, regular_price. Optional: description, short_description, category, image_url, prep_time. No spice column — spice visibility is category-driven and untouched by this tool.', 'dish-dash' ); ?>
    </p>

    <?php if ( $dd_csvi_result !== null ) : ?>

        <?php if ( ! empty( $dd_csvi_result['fatal'] ) ) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html( $dd_csvi_result['fatal'] ); ?></p></div>
        <?php else : ?>
            <div class="notice notice-success inline">
                <p>
                    <?php
                    printf(
                        /* translators: 1: deleted count, 2: created count */
                        esc_html__( 'Import complete. %1$d existing products deleted, %2$d new products created.', 'dish-dash' ),
                        (int) $dd_csvi_result['deleted'],
                        (int) $dd_csvi_result['created']
                    );
                    ?>
                </p>
            </div>

            <table class="widefat striped" style="margin-bottom:24px;max-width:520px;">
                <tbody>
                    <tr><td><?php esc_html_e( 'Products deleted', 'dish-dash' ); ?></td><td><strong><?php echo esc_html( number_format( $dd_csvi_result['deleted'] ) ); ?></strong></td></tr>
                    <tr><td><?php esc_html_e( 'Products created', 'dish-dash' ); ?></td><td><strong><?php echo esc_html( number_format( $dd_csvi_result['created'] ) ); ?></strong></td></tr>
                    <tr><td><?php esc_html_e( 'Images sideloaded', 'dish-dash' ); ?></td><td><?php echo esc_html( number_format( $dd_csvi_result['images_ok'] ) ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Images skipped/failed', 'dish-dash' ); ?></td><td><?php echo esc_html( number_format( $dd_csvi_result['images_skipped'] ) ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Delete errors', 'dish-dash' ); ?></td><td><?php echo esc_html( number_format( count( $dd_csvi_result['delete_errors'] ) ) ); ?></td></tr>
                </tbody>
            </table>

            <p style="font-size:12px;color:#27ae60;margin-bottom:20px;">
                ✅ <?php esc_html_e( 'Product categories and media attachments were NOT touched by this run.', 'dish-dash' ); ?>
            </p>

            <?php if ( ! empty( $dd_csvi_result['row_log'] ) ) : ?>
                <h3 style="font-size:13px;font-weight:700;color:#1a1a1a;margin:0 0 12px;"><?php esc_html_e( 'Row Log', 'dish-dash' ); ?></h3>
                <table class="widefat striped" style="margin-bottom:24px;">
                    <thead><tr>
                        <th><?php esc_html_e( 'Row', 'dish-dash' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'dish-dash' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'dish-dash' ); ?></th>
                        <th><?php esc_html_e( 'Image', 'dish-dash' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $dd_csvi_result['row_log'] as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) $log['row'] ); ?></td>
                                <td><?php echo esc_html( $log['name'] ); ?></td>
                                <td><?php echo esc_html( $log['status'] ); ?></td>
                                <td><?php echo esc_html( $log['image'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ( $dd_csvi_preview !== null && ! empty( $dd_csvi_preview['file_error'] ) ) : ?>

        <div class="notice notice-error inline"><p><?php echo esc_html( $dd_csvi_preview['file_error'] ); ?></p></div>

    <?php elseif ( $dd_csvi_preview !== null ) : ?>

        <h3 style="font-size:13px;font-weight:700;color:#1a1a1a;margin:0 0 12px;"><?php esc_html_e( 'Preview — nothing has been written yet', 'dish-dash' ); ?></h3>

        <table class="widefat striped" style="margin-bottom:16px;max-width:520px;">
            <tbody>
                <tr><td><?php esc_html_e( 'Existing products (will be deleted)', 'dish-dash' ); ?></td><td><strong style="color:#c0392b;"><?php echo esc_html( number_format( $dd_csvi_preview['existing_count'] ) ); ?></strong></td></tr>
                <tr><td><?php esc_html_e( 'Valid rows (will be created)', 'dish-dash' ); ?></td><td><strong style="color:#27ae60;"><?php echo esc_html( number_format( $dd_csvi_preview['valid_count'] ) ); ?></strong></td></tr>
                <tr><td><?php esc_html_e( 'Rows with errors (will be skipped)', 'dish-dash' ); ?></td><td><strong style="color:#e67e22;"><?php echo esc_html( number_format( count( $dd_csvi_preview['error_rows'] ) ) ); ?></strong></td></tr>
            </tbody>
        </table>

        <?php if ( $dd_csvi_preview['has_orders'] ) : ?>
            <div class="notice notice-warning inline">
                <p>⚠️ <?php esc_html_e( 'This site has order history. Analytics/event data referencing the wiped products will be orphaned (product IDs will no longer resolve). Order history itself is unaffected — proceed with caution.', 'dish-dash' ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $dd_csvi_preview['error_rows'] ) ) : ?>
            <h3 style="font-size:13px;font-weight:700;color:#1a1a1a;margin:16px 0 12px;"><?php esc_html_e( 'Row Errors', 'dish-dash' ); ?></h3>
            <table class="widefat striped" style="margin-bottom:20px;">
                <thead><tr>
                    <th><?php esc_html_e( 'Row', 'dish-dash' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'dish-dash' ); ?></th>
                    <th><?php esc_html_e( 'Errors', 'dish-dash' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $dd_csvi_preview['error_rows'] as $err ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $err['row'] ); ?></td>
                            <td><?php echo esc_html( $err['name'] ); ?></td>
                            <td><?php echo esc_html( implode( '; ', $err['errors'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( $dd_csvi_preview['valid_count'] > 0 ) : ?>
            <div style="border:1px solid #e0b4b4;background:#fff6f6;border-radius:8px;padding:16px;margin-bottom:20px;max-width:520px;">
                <h3 style="font-size:13px;font-weight:700;color:#c0392b;margin:0 0 10px;">⚠️ <?php esc_html_e( 'Confirm — this permanently deletes existing products', 'dish-dash' ); ?></h3>
                <form method="post">
                    <?php wp_nonce_field( 'dd_csv_import_commit' ); ?>
                    <input type="hidden" name="dd_csv_token" value="<?php echo esc_attr( $dd_csvi_preview['token'] ); ?>">
                    <p>
                        <label>
                            <input type="checkbox" name="dd_csv_confirm_checkbox" value="1" required>
                            <?php
                            printf(
                                /* translators: %d: number of products to be deleted */
                                esc_html__( 'I understand this will permanently delete all %d existing products.', 'dish-dash' ),
                                (int) $dd_csvi_preview['existing_count']
                            );
                            ?>
                        </label>
                    </p>
                    <p>
                        <label style="display:block;margin-bottom:4px;">
                            <?php
                            printf(
                                /* translators: %s: restaurant/site name to type */
                                esc_html__( 'Type the restaurant name to confirm: %s', 'dish-dash' ),
                                '<strong>' . esc_html( $dd_csvi_site_name ) . '</strong>'
                            );
                            ?>
                        </label>
                        <input type="text" name="dd_csv_confirm_text" style="width:100%;max-width:280px;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-family:inherit;" required>
                    </p>
                    <button type="submit" name="dd_csv_commit_submit" value="1" class="button button-primary" style="background:#c0392b;border-color:#a83226;">
                        <?php esc_html_e( 'Wipe & Reimport', 'dish-dash' ); ?>
                    </button>
                </form>
            </div>
        <?php else : ?>
            <div class="notice notice-error inline"><p><?php esc_html_e( 'No valid rows to import — commit is blocked so the menu is never wiped with nothing to replace it. Fix the row errors above and re-upload.', 'dish-dash' ); ?></p></div>
        <?php endif; ?>

    <?php endif; ?>

    <h3 style="font-size:13px;font-weight:700;color:#1a1a1a;margin:24px 0 12px;"><?php esc_html_e( 'Upload CSV', 'dish-dash' ); ?></h3>
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field( 'dd_csv_import_preview' ); ?>
        <p><input type="file" name="dd_csv_file" accept=".csv" required></p>
        <button type="submit" name="dd_csv_preview_submit" value="1" class="button button-primary">
            <?php esc_html_e( 'Preview Import', 'dish-dash' ); ?>
        </button>
    </form>

</div>
