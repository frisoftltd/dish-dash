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
        add_action( 'admin_menu', [ $this, 'register_submenu' ] );
    }

    public function register_submenu(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Seating Sections', 'dish-dash' ),
            __( 'Seating Sections', 'dish-dash' ),
            'manage_options',
            'dd-sections',
            [ $this, 'render_page' ]
        );
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
                    continue; // skip empty rows
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
        <div class="wrap">
            <h1>Seating Sections</h1>
            <p class="description" style="max-width:640px">
                These are the seating areas customers can choose from when booking a table
                (e.g. Indoor, Outdoor, Rooftop, Private Room). Only active sections appear
                in the reservation form. Staff assign the actual table when guests arrive.
            </p>

            <?php if ( $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'dd_sections_save' ); ?>
                <input type="hidden" name="dd_sections_save" value="1">

                <table class="wp-list-table widefat fixed striped" style="max-width:640px;margin-top:12px;">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th>Section Name</th>
                            <th style="width:90px">Active</th>
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

                <p class="description" style="max-width:640px;margin-top:8px;">
                    To remove a section, clear its name and save. Empty rows are ignored.
                </p>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save Sections</button>
                </p>
            </form>
        </div>
        <?php
    }
}
