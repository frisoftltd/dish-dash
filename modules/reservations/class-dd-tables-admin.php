<?php
/**
 * DD_Tables_Admin
 * WP Admin → Dish Dash → Tables
 *
 * @package DishDash
 * @since   3.2.91
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Tables_Admin {

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_submenu(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Tables', 'dish-dash' ),
            __( 'Tables', 'dish-dash' ),
            'manage_options',
            'dd-tables',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ): void {
        if ( strpos( $hook, 'dd-tables' ) === false ) return;
        wp_enqueue_style(
            'dd-reservations-admin',
            plugin_dir_url( __FILE__ ) . '../../assets/css/reservations-admin.css',
            [],
            DD_VERSION
        );
    }

    private function page_tabs(): void {
        $page_tabs = [
            'dd-reservations' => [ 'label' => 'Reservations',    'icon' => 'dashicons-calendar-alt' ],
            'dd-tables'       => [ 'label' => 'Tables',           'icon' => 'dashicons-grid-view'    ],
            'dd-sections'     => [ 'label' => 'Seating Sections', 'icon' => 'dashicons-layout'       ],
        ];
        $current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'dd-tables';
        echo '<div class="dd-res-page-tabs">';
        foreach ( $page_tabs as $slug => $tab ) {
            printf(
                '<a href="%s" class="dd-res-page-tab %s"><span class="dashicons %s"></span>%s</a>',
                esc_url( admin_url( 'admin.php?page=' . $slug ) ),
                $current === $slug ? 'active' : '',
                esc_attr( $tab['icon'] ),
                esc_html( $tab['label'] )
            );
        }
        echo '</div>';
    }

    public function render_page(): void {
        global $wpdb;
        $table    = $wpdb->prefix . 'dishdash_tables';
        $base_url = admin_url( 'admin.php?page=dd-tables' );
        $action   = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';
        $edit_id  = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
        $notice   = '';

        // ── Save (add / edit) ─────────────────────────────────────────────
        if (
            isset( $_POST['dd_table_save'], $_POST['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dd_table_save' )
        ) {
            $data = [
                'name'       => sanitize_text_field( wp_unslash( $_POST['name']       ?? '' ) ),
                'capacity'   => intval( $_POST['capacity']   ?? 2 ),
                'section'    => sanitize_text_field( wp_unslash( $_POST['section']    ?? 'indoor' ) ),
                'sort_order' => intval( $_POST['sort_order'] ?? 0 ),
                'is_active'  => isset( $_POST['is_active'] ) ? 1 : 0,
            ];
            $formats = [ '%s', '%d', '%s', '%d', '%d' ];

            if ( $edit_id ) {
                $wpdb->update( $table, $data, [ 'id' => $edit_id ], $formats, [ '%d' ] );
                $notice = 'Table updated.';
            } else {
                $wpdb->insert( $table, $data, $formats );
                $notice = 'Table added.';
            }
            $action = 'list';
        }

        // ── Delete ────────────────────────────────────────────────────────
        if (
            $action === 'delete' && $edit_id &&
            isset( $_GET['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dd_table_delete_' . $edit_id )
        ) {
            $wpdb->delete( $table, [ 'id' => $edit_id ], [ '%d' ] );
            $notice = 'Table deleted.';
            $action = 'list';
        }

        // ── Edit / Add form ───────────────────────────────────────────────
        if ( $action === 'edit' || $action === 'add' ) {
            $row   = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ) ) : null;
            $title = $row ? 'Edit Table' : 'Add Table';
            ?>
            <div class="wrap dd-admin-wrap">
            <div class="dd-page-wrap">

                <div class="dd-res-header">
                    <h1>
                        <span class="dashicons dashicons-grid-view"
                              style="font-size:26px;width:26px;height:26px;margin-right:8px;vertical-align:middle;"></span>
                        <?php echo esc_html( $title ); ?>
                    </h1>
                    <p>Manage physical tables for reservation assignment</p>
                </div>

                <?php $this->page_tabs(); ?>

                <div class="dd-card" style="max-width:600px;background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                    <form method="post">
                        <?php wp_nonce_field( 'dd_table_save' ); ?>
                        <input type="hidden" name="dd_table_save" value="1">
                        <table class="form-table">
                            <tr>
                                <th>Name</th>
                                <td><input type="text" name="name" class="regular-text"
                                    value="<?php echo esc_attr( $row->name ?? '' ); ?>" required></td>
                            </tr>
                            <tr>
                                <th>Capacity</th>
                                <td><input type="number" name="capacity" min="1" max="50"
                                    value="<?php echo esc_attr( $row->capacity ?? 2 ); ?>"></td>
                            </tr>
                            <tr>
                                <th>Section</th>
                                <td>
                                    <select name="section">
                                        <?php foreach ( [ 'indoor', 'outdoor', 'private' ] as $s ) : ?>
                                            <option value="<?php echo esc_attr( $s ); ?>"
                                                <?php selected( $row->section ?? 'indoor', $s ); ?>>
                                                <?php echo esc_html( ucfirst( $s ) ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Sort Order</th>
                                <td><input type="number" name="sort_order" min="0"
                                    value="<?php echo esc_attr( $row->sort_order ?? 0 ); ?>"></td>
                            </tr>
                            <tr>
                                <th>Active</th>
                                <td><input type="checkbox" name="is_active" value="1"
                                    <?php checked( $row->is_active ?? 1, 1 ); ?>></td>
                            </tr>
                        </table>
                        <p class="submit" style="margin-top:16px;">
                            <button type="submit" class="button button-primary">Save Table</button>
                            <a href="<?php echo esc_url( $base_url ); ?>" class="button">Cancel</a>
                        </p>
                    </form>
                </div>

            </div><!-- /dd-page-wrap -->
            </div><!-- /wrap -->
            <?php
            return;
        }

        // ── List view ─────────────────────────────────────────────────────
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC" );
        ?>
        <div class="wrap dd-admin-wrap">
        <div class="dd-page-wrap">

            <div class="dd-res-header">
                <h1>
                    <span class="dashicons dashicons-grid-view"
                          style="font-size:26px;width:26px;height:26px;margin-right:8px;vertical-align:middle;"></span>
                    Tables
                </h1>
                <p>Manage physical tables for reservation assignment</p>
            </div>

            <?php $this->page_tabs(); ?>

            <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
                <a href="<?php echo esc_url( $base_url . '&action=add' ); ?>"
                   class="button button-primary">+ Add Table</a>
            </div>

            <?php if ( $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <div class="dd-res-table-wrap">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Capacity</th>
                            <th>Section</th>
                            <th>Sort</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="6" style="text-align:center;color:#6b7280;padding:24px;">
                                No tables yet. <a href="<?php echo esc_url( $base_url . '&action=add' ); ?>">Add one</a>.
                            </td></tr>
                        <?php else : foreach ( $rows as $r ) : ?>
                            <tr>
                                <td><?php echo esc_html( $r->name ); ?></td>
                                <td><?php echo esc_html( $r->capacity ); ?></td>
                                <td><?php echo esc_html( ucfirst( $r->section ) ); ?></td>
                                <td><?php echo esc_html( $r->sort_order ); ?></td>
                                <td><?php echo $r->is_active ? '✅' : '—'; ?></td>
                                <td>
                                    <div class="dd-res-actions">
                                        <a href="<?php echo esc_url( $base_url . '&action=edit&id=' . $r->id ); ?>"
                                           class="dd-res-action-btn dd-res-action-btn--confirm">Edit</a>
                                        <a href="<?php echo esc_url( wp_nonce_url(
                                            $base_url . '&action=delete&id=' . $r->id,
                                            'dd_table_delete_' . $r->id
                                        ) ); ?>"
                                           class="dd-res-action-btn dd-res-action-btn--cancel"
                                           onclick="return confirm('Delete this table?')">Delete</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div><!-- /dd-res-table-wrap -->

        </div><!-- /dd-page-wrap -->
        </div><!-- /wrap -->
        <?php
    }
}
