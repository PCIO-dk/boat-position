<?php
/**
 * Admin settings page for the Boat Position plugin.
 * Settings > Boat Position
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_BP_Settings {

    public function init(): void {
        add_action( 'admin_menu',             [ $this, 'add_pages'      ] );
        add_action( 'admin_init',             [ $this, 'register'       ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_pcio_bp_download_sendgps',   [ $this, 'download_sendgps'   ] );
        add_action( 'admin_post_pcio_bp_save_permissions',   [ $this, 'save_permissions'   ] );
        add_filter(
            'plugin_action_links_' . plugin_basename( PCIO_BP_PLUGIN_FILE ),
            [ $this, 'action_links' ]
        );
    }

    // ── Plugin list action links ───────────────────────────────────────────────

    public function action_links( array $links ): array {
        $extra = [
            '<a href="' . esc_url( admin_url( 'options-general.php?page=boat-position' ) ) . '">' . esc_html__( 'Settings', 'boat-position' ) . '</a>',
            '<a href="' . esc_url( admin_url( 'admin.php?page=boat-position-about' ) ) . '">' . esc_html__( 'About', 'boat-position' ) . '</a>',
        ];
        return array_merge( $extra, $links );
    }
    // ── Admin asset enqueue ──────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'boat-position-about' ) === false ) {
            return;
        }
        wp_enqueue_style(
            'pcio-bp-admin',
            plugins_url( 'assets/admin.css', PCIO_BP_PLUGIN_FILE ),
            [],
            '1.3.0'
        );
    }
    // ── Admin menu ────────────────────────────────────────────────────────────

    public function add_pages(): void {
        add_options_page(
            __( 'Boat Position Settings', 'boat-position' ),
            __( 'Boat Position', 'boat-position' ),
            'manage_options',
            'boat-position',
            [ $this, 'render_page' ]
        );

        // Registered but not shown in any menu (parent = null).
        // Accessible via the "About" action link on the plugins page.
        add_submenu_page(
            null,
            __( 'Boat Position: About', 'boat-position' ),
            __( 'Boat Position: About', 'boat-position' ),
            'manage_options',
            'boat-position-about',
            [ $this, 'render_about_page' ]
        );
    }

    // ── Settings registration ─────────────────────────────────────────────────

    public function register(): void {
        register_setting( 'pcio_bp_settings', 'pcio_boat_position_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        add_settings_section( 'pcio_bp_api', __( 'API', 'boat-position' ), '__return_false', 'boat-position' );

        add_settings_field(
            'pcio_boat_position_api_key',
            __( 'API Key', 'boat-position' ),
            [ $this, 'render_api_key_field' ],
            'boat-position',
            'pcio_bp_api'
        );
    }

    // ── Page render ───────────────────────────────────────────────────────────

    public function render_page(): void {
        $rest_base = rest_url( PCIO_BP_REST_NS );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Boat Position', 'boat-position' ); ?></h1>

            <?php if ( isset( $_GET['pcio_bp_updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e( 'Route editors updated.', 'boat-position' ); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'pcio_bp_settings' );
                do_settings_sections( 'boat-position' );
                submit_button( __( 'Save API Key', 'boat-position' ) );
                ?>
            </form>

            <hr>

            <?php $this->render_editors_section(); ?>
        </div>
        <?php
    }

    // ── Route editors permission dialog ───────────────────────────────────
    // Lets an admin grant the route-management capability to any user who can
    // already author posts (Author or higher). Administrators always have it
    // through their role, so they are shown checked and disabled.

    private function render_editors_section(): void {
        $users = get_users( [ 'capability' => 'edit_posts', 'orderby' => 'display_name' ] );
        ?>
        <h2><?php esc_html_e( 'Route editors', 'boat-position' ); ?></h2>
        <p class="description" style="max-width:640px">
            <?php esc_html_e( 'Grant permission to manage routes, harbours and voyage plans. Only users who can already author posts (Author or higher) are listed. Administrators always have access.', 'boat-position' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="pcio_bp_save_permissions">
            <?php wp_nonce_field( 'pcio_bp_save_permissions' ); ?>
            <table class="widefat striped" style="max-width:640px;margin-top:.5em">
                <thead><tr>
                    <th><?php esc_html_e( 'User', 'boat-position' ); ?></th>
                    <th><?php esc_html_e( 'Role', 'boat-position' ); ?></th>
                    <th style="text-align:center;width:8em"><?php esc_html_e( 'Manage routes', 'boat-position' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $users ) ) : ?>
                    <tr><td colspan="3"><?php esc_html_e( 'No eligible users found.', 'boat-position' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $users as $user ) :
                        $is_admin_user = in_array( 'administrator', (array) $user->roles, true );
                        $has           = $is_admin_user || user_can( $user, PCIO_BP_CAP );
                        ?>
                        <tr>
                            <td>
                                <?php echo esc_html( $user->display_name ); ?><br>
                                <span class="description"><?php echo esc_html( $user->user_email ); ?></span>
                            </td>
                            <td><?php echo esc_html( implode( ', ', (array) $user->roles ) ); ?></td>
                            <td style="text-align:center">
                                <input type="checkbox"
                                    <?php echo $is_admin_user ? 'disabled' : 'name="pcio_bp_editors[]"'; ?>
                                    value="<?php echo esc_attr( (string) $user->ID ); ?>"
                                    <?php checked( $has ); ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php submit_button( __( 'Save route editors', 'boat-position' ) ); ?>
        </form>
        <?php
    }

    // ── Save route editors ───────────────────────────────────────────

    public function save_permissions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'boat-position' ) );
        }
        check_admin_referer( 'pcio_bp_save_permissions' );

        $granted = isset( $_POST['pcio_bp_editors'] ) && is_array( $_POST['pcio_bp_editors'] )
            ? array_map( 'absint', wp_unslash( $_POST['pcio_bp_editors'] ) )
            : [];

        $users = get_users( [ 'capability' => 'edit_posts', 'fields' => [ 'ID' ] ] );
        foreach ( $users as $u ) {
            $user = new WP_User( $u->ID );
            // Administrators get the capability from their role; leave untouched.
            if ( in_array( 'administrator', (array) $user->roles, true ) ) {
                continue;
            }
            if ( in_array( $user->ID, $granted, true ) ) {
                $user->add_cap( PCIO_BP_CAP );
            } else {
                $user->remove_cap( PCIO_BP_CAP );
            }
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'boat-position', 'pcio_bp_updated' => '1' ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    // ── Field render ──────────────────────────────────────────────────────────

    public function render_api_key_field(): void {
        if ( defined( 'PCIO_BOAT_POSITION_API_KEY' ) ) {
            echo '<input type="text" value="' . esc_attr__( '(defined in wp-config.php)', 'boat-position' ) . '" class="regular-text" disabled>';
            echo '<p class="description">' . sprintf(
                /* translators: %1$s and %2$s are code constant/file names */
                esc_html__( 'Remove the %1$s constant from %2$s to manage the key here instead.', 'boat-position' ),
                '<code>PCIO_BOAT_POSITION_API_KEY</code>',
                '<code>wp-config.php</code>'
            ) . '</p>';
        } else {
            $value = get_option( 'pcio_boat_position_api_key', '' );
            echo '<input type="text" name="pcio_boat_position_api_key" value="'
               . esc_attr( $value ) . '" class="regular-text" autocomplete="off">';
            echo '<p class="description">' . sprintf(
                /* translators: %s is the field name */
                esc_html__( 'Secret key sent by the router in the %s field. Use a long random string.', 'boat-position' ),
                '<code>apikey</code>'
            ) . '</p>';
        }
    }

    // ── About page ────────────────────────────────────────────────────────────

    public function render_about_page(): void {
        $locale      = get_locale();
        $locale_file = PCIO_BP_PLUGIN_DIR . 'assets/about-' . $locale . '.html';
        $html_file   = file_exists( $locale_file )
            ? $locale_file
            : PCIO_BP_PLUGIN_DIR . 'assets/about.html';
        if ( ! file_exists( $html_file ) ) {
            wp_die( esc_html__( 'About page not found.', 'boat-position' ) );
        }
        $plugin_url = esc_url( plugins_url( '/', PCIO_BP_PLUGIN_FILE ) );
        $download_url = esc_url( wp_nonce_url(
            admin_url( 'admin-post.php?action=pcio_bp_download_sendgps' ),
            'pcio_bp_download_sendgps'
        ) );

        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        $raw  = $wp_filesystem ? (string) $wp_filesystem->get_contents( $html_file ) : '';
        $body = str_replace(
            [ '{{PLUGIN_URL}}', '{{DOWNLOAD_URL}}' ],
            [ $plugin_url, $download_url ],
            $raw
        );
        ?>
        <div class="wrap" id="pcio-bp-about">
            <p style="margin-bottom:1em">
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=boat-position' ) ); ?>">
                    &larr; <?php esc_html_e( 'Settings', 'boat-position' ); ?>
                </a>
            </p>
            <div id="pcio-bp-about-body">
                <?php
                echo wp_kses( $body, [
                    'p'      => [],
                    'h1'     => [ 'id' => [] ],
                    'h2'     => [ 'id' => [] ],
                    'h3'     => [ 'id' => [] ],
                    'table'  => [ 'class' => [] ],
                    'thead'  => [],
                    'tbody'  => [],
                    'tr'     => [],
                    'th'     => [ 'colspan' => [], 'rowspan' => [] ],
                    'td'     => [ 'colspan' => [], 'rowspan' => [] ],
                    'ul'     => [],
                    'ol'     => [],
                    'li'     => [],
                    'code'   => [],
                    'pre'    => [],
                    'strong' => [],
                    'em'     => [],
                    'a'      => [ 'href' => [], 'target' => [], 'rel' => [], 'class' => [], 'download' => [] ],
                    'img'    => [ 'src' => [], 'alt' => [], 'width' => [], 'height' => [] ],
                ] );
                ?>
            </div>
        </div>
        <?php
    }

    // ── Download a ready-to-use sendgps.sh ────────────────────────────────
    // Streams the bundled script template with the site's ingest URL and API
    // key substituted in, so the router script works out of the box.

    public function download_sendgps(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to download this file.', 'boat-position' ) );
        }
        check_admin_referer( 'pcio_bp_download_sendgps' );

        $script_file = PCIO_BP_PLUGIN_DIR . 'assets/sendgps.sh.txt';
        if ( ! file_exists( $script_file ) ) {
            wp_die( esc_html__( 'Script template not found.', 'boat-position' ) );
        }

        $ingest_url = rest_url( PCIO_BP_REST_NS . '/ingest' );
        $api_key    = defined( 'PCIO_BOAT_POSITION_API_KEY' )
            ? PCIO_BOAT_POSITION_API_KEY
            : (string) get_option( 'pcio_boat_position_api_key', '' );
        if ( $api_key === '' ) {
            $api_key = '<secret-key>';
        }

        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        $raw    = $wp_filesystem ? (string) $wp_filesystem->get_contents( $script_file ) : '';
        $script = str_replace(
            [ '{{INGEST_URL}}', '{{API_KEY}}' ],
            [ $ingest_url, $api_key ],
            $raw
        );
        // Router shells expect LF line endings.
        $script = str_replace( "\r\n", "\n", $script );

        nocache_headers();
        header( 'Content-Type: application/x-sh; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="sendgps.sh"' );
        header( 'Content-Length: ' . strlen( $script ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw shell script download
        echo $script;
        exit;
    }
}
