<?php
/**
 * DD_Sections_Admin
 * WP Admin → Dish Dash → Seating Sections
 * Manages the list of reservation seating areas (Indoor, Outdoor, etc).
 *
 * @package DishDash
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Sections_Admin {

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_submenu(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Seating Sections', 'dish-dash' ),
            __( 'Seating Sections', 'dish-dash' ),
            'dd_manage_reservations',
            'dd-sections',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ): void {
        if ( strpos( $hook, 'dd-sections' ) === false ) return;
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style(
            'dd-reservations-admin',
            plugin_dir_url( __FILE__ ) . '../../assets/css/reservations-admin.css',
            [ 'dashicons' ],
            DD_VERSION
        );
    }

    private function page_tabs(): void {
        $page_tabs = [
            'dd-reservations' => [ 'label' => 'Reservations',    'icon' => 'dashicons-calendar-alt' ],
            'dd-tables'       => [ 'label' => 'Tables',           'icon' => 'dashicons-grid-view'    ],
            'dd-sections'     => [ 'label' => 'Seating Sections', 'icon' => 'dashicons-layout'       ],
        ];
        $current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'dd-sections';
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
        $notice = '';

        // Load current sections
        $sections = DD_Reservations_Module::get_sections();

        // ── Handle save ───────────────────────────────────────────────────
        if (
            isset( $_POST['dd_sections_save'], $_POST['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dd_sections_save' )
        ) {
            $names  = isset( $_POST['section_name'] ) ? (array) $_POST['section_name'] : [];
            $active = isset( $_POST['section_active'] ) ? (array) $_POST['section_active'] : [];

            $new = [];
            foreach ( $names as $i => $raw_name ) {
                $name = sanitize_text_field( wp_unslash( $raw_name ) );
                if ( $name === '' ) {
                    continue;
                }
                $new[] = [
                    'name'   => $name,
                    'active' => isset( $active[ $i ] ) ? true : false,
                ];
            }

            update_option( 'dd_reservation_sections', wp_json_encode( $new ) );
            $sections = $new;
            $notice   = 'Sections saved.';
        }
        ?>
        <div class="wrap dd-admin-wrap">
        <div class="dd-page-wrap">

            <div class="dd-res-header">
                <h1>
                    <span class="dashicons dashicons-layout"
                          style="font-size:26px;width:26px;height:26px;margin-right:8px;vertical-align:middle;"></span>
                    Seating Sections
                </h1>
                <p>Configure seating areas for reservation booking</p>
            </div>

            <?php $this->page_tabs(); ?>

            <?php if ( $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <div class="dd-card" style="max-width:640px;background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                <p style="margin:0 0 16px;color:#6b7280;font-size:13px;">
                    These are the seating areas customers can choose from when booking a table
                    (e.g. Indoor, Outdoor, Rooftop, Private Room). Only active sections appear
                    in the reservation form. Staff assign the actual table when guests arrive.
                </p>

                <form method="post">
                    <?php wp_nonce_field( 'dd_sections_save' ); ?>
                    <input type="hidden" name="dd_sections_save" value="1">

                    <div class="dd-res-table-wrap">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width:40px">#</th>
                                    <th>Section Name</th>
                                    <th style="width:90px;text-align:center">Active</th>
                                </tr>
                            </thead>
                            <tbody id="dd-sections-rows">
                                <?php
                                $i = 0;
                                foreach ( $sections as $s ) :
                                    $i++;
                                ?>
                                    <tr>
                                        <td><?php echo esc_html( $i ); ?></td>
                                        <td>
                                            <input type="text" name="section_name[]"
                                                value="<?php echo esc_attr( $s['name'] ); ?>"
                                                class="regular-text" placeholder="e.g. Rooftop">
                                        </td>
                                        <td style="text-align:center">
                                            <input type="checkbox" name="section_active[<?php echo esc_attr( $i - 1 ); ?>]"
                                                value="1" <?php checked( ! empty( $s['active'] ) ); ?>>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php /* Three spare blank rows for adding new sections */ ?>
                                <?php for ( $b = 0; $b < 3; $b++ ) : $idx = count( $sections ) + $b; ?>
                                    <tr>
                                        <td><?php echo esc_html( count( $sections ) + $b + 1 ); ?></td>
                                        <td>
                                            <input type="text" name="section_name[]"
                                                value="" class="regular-text" placeholder="Add a new section…">
                                        </td>
                                        <td style="text-align:center">
                                            <input type="checkbox" name="section_active[<?php echo esc_attr( $idx ); ?>]"
                                                value="1" checked>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div><!-- /dd-res-table-wrap -->

                    <p style="color:#6b7280;font-size:12px;margin:10px 0 16px;">
                        To remove a section, clear its name and save. Empty rows are ignored.
                    </p>

                    <button type="submit" class="button button-primary">Save Sections</button>
                </form>
            </div><!-- /dd-card -->

        </div><!-- /dd-page-wrap -->
        </div><!-- /wrap -->
        <?php
    }
}
