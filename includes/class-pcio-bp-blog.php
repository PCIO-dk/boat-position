<?php
/**
 * Boat-log blog integration.
 *
 * Reuses ordinary WordPress posts (so the theme / Divi handles all writing and
 * display). A post placed in the "Boat Log" category and given coordinates is
 * shown as a marker on the live map. From the post, the [boat_log_map_link]
 * shortcode links back to the map and animates the location.
 *
 * GET /wp-json/boat-position/v1/blog-posts   (public)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_BP_Blog {

    /** Category slug used to flag a post as a boat-log entry. */
    public const CAT_SLUG = 'boat-log';

    /** Post-meta keys holding the marker coordinates. */
    public const META_LAT = '_bp_lat';
    public const META_LON = '_bp_lon';

    public function __construct() {
        add_action( 'init',                  [ $this, 'register' ] );
        add_action( 'rest_api_init',         [ $this, 'register_rest' ] );
        add_action( 'add_meta_boxes',        [ $this, 'add_meta_box' ] );
        add_action( 'save_post',             [ $this, 'save_location' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor' ] );
        // New posts default to the Boat Log category instead of Uncategorized.
        // Works regardless of the editor (Divi, block or classic) because the
        // default is applied server-side when the post is saved.
        add_filter( 'option_default_category', [ $this, 'default_category' ] );
    }

    /**
     * Filter the site's default post category so new posts land in "Boat Log".
     * Returns the original value unchanged if the category does not exist yet.
     *
     * @param mixed $value Stored default category term id.
     * @return mixed
     */
    public function default_category( $value ) {
        static $cat_id = 0;
        if ( ! $cat_id ) {
            $term   = get_term_by( 'slug', self::CAT_SLUG, 'category' );
            $cat_id = ( $term instanceof WP_Term ) ? (int) $term->term_id : 0;
        }
        return $cat_id ?: $value;
    }

    // ── Setup ──────────────────────────────────────────────────────────────────

    public function register(): void {
        self::ensure_category();
        add_shortcode( 'boat_log_map_link', [ $this, 'shortcode_map_link' ] );
    }

    /**
     * Make sure the "Boat Log" category exists. Returns its term_id (or 0).
     * Safe to call repeatedly (idempotent).
     */
    public static function ensure_category(): int {
        $term = get_term_by( 'slug', self::CAT_SLUG, 'category' );
        if ( $term instanceof WP_Term ) {
            return (int) $term->term_id;
        }
        $res = wp_insert_term(
            __( 'Boat Log', 'boat-position' ),
            'category',
            [ 'slug' => self::CAT_SLUG ]
        );
        return is_wp_error( $res ) ? 0 : (int) $res['term_id'];
    }

    // ── REST: markers feed ──────────────────────────────────────────────────────

    public function register_rest(): void {
        register_rest_route( PCIO_BP_REST_NS, '/blog-posts', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_blog_posts' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function list_blog_posts(): WP_REST_Response {
        $cat_id = self::ensure_category();
        if ( ! $cat_id ) {
            return new WP_REST_Response( [], 200 );
        }

        $query = new WP_Query( [
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'cat'                 => $cat_id,
            'posts_per_page'      => 500,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ] );

        $out = [];
        foreach ( $query->posts as $post ) {
            $lat = get_post_meta( $post->ID, self::META_LAT, true );
            $lon = get_post_meta( $post->ID, self::META_LON, true );
            if ( $lat === '' || $lon === '' ) {
                continue;
            }
            $thumb = get_the_post_thumbnail_url( $post->ID, 'medium' );
            $out[] = [
                'id'      => (int) $post->ID,
                'title'   => get_the_title( $post ),
                'url'     => get_permalink( $post ),
                'date'    => get_the_date( 'c', $post ),
                'lat'     => (float) $lat,
                'lon'     => (float) $lon,
                'thumb'   => $thumb ?: null,
                'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ),
            ];
        }

        return new WP_REST_Response( $out, 200 );
    }

    // ── Editor: location meta box ───────────────────────────────────────────────

    public function add_meta_box(): void {
        add_meta_box(
            'pcio-bp-location',
            __( 'Boat position', 'boat-position' ),
            [ $this, 'render_meta_box' ],
            'post',
            'side',
            'default'
        );
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'pcio_bp_save_location', 'pcio_bp_location_nonce' );
        $lat = get_post_meta( $post->ID, self::META_LAT, true );
        $lon = get_post_meta( $post->ID, self::META_LON, true );
        ?>
        <div id="pcio-bp-meta">
            <div id="pcio-bp-meta-map"></div>
            <p class="pcio-bp-meta-coords">
                <span id="pcio-bp-meta-readout">
                    <?php echo $lat !== '' && $lon !== ''
                        ? esc_html( $lat . ', ' . $lon )
                        : esc_html__( 'No location set', 'boat-position' ); ?>
                </span>
            </p>
            <p>
                <button type="button" class="button" id="pcio-bp-use-current">
                    <?php esc_html_e( 'Use current boat position', 'boat-position' ); ?>
                </button>
                <button type="button" class="button-link" id="pcio-bp-clear-location">
                    <?php esc_html_e( 'Clear', 'boat-position' ); ?>
                </button>
            </p>
            <input type="hidden" id="pcio-bp-meta-lat" name="pcio_bp_lat" value="<?php echo esc_attr( $lat ); ?>">
            <input type="hidden" id="pcio-bp-meta-lon" name="pcio_bp_lon" value="<?php echo esc_attr( $lon ); ?>">
        </div>
        <?php
    }

    /**
     * Persist the coordinates. When the post is in the Boat Log category and no
     * coordinates were supplied, default to the boat's latest known position.
     */
    public function save_location( int $post_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_type !== 'post' ) {
            return;
        }
        if ( ! isset( $_POST['pcio_bp_location_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcio_bp_location_nonce'] ) ), 'pcio_bp_save_location' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $lat = isset( $_POST['pcio_bp_lat'] ) ? sanitize_text_field( wp_unslash( $_POST['pcio_bp_lat'] ) ) : '';
        $lon = isset( $_POST['pcio_bp_lon'] ) ? sanitize_text_field( wp_unslash( $_POST['pcio_bp_lon'] ) ) : '';

        // Default to the latest boat position for boat-log posts with no location.
        if ( ( $lat === '' || $lon === '' ) && self::post_is_boat_log( $post_id ) ) {
            $latest = self::latest_position();
            if ( $latest ) {
                $lat = (string) $latest['lat'];
                $lon = (string) $latest['lon'];
            }
        }

        if ( $lat === '' || $lon === '' ) {
            delete_post_meta( $post_id, self::META_LAT );
            delete_post_meta( $post_id, self::META_LON );
            return;
        }

        $lat_f = filter_var( $lat, FILTER_VALIDATE_FLOAT );
        $lon_f = filter_var( $lon, FILTER_VALIDATE_FLOAT );
        if ( $lat_f === false || $lon_f === false
            || $lat_f < -90 || $lat_f > 90 || $lon_f < -180 || $lon_f > 180 ) {
            return;
        }

        update_post_meta( $post_id, self::META_LAT, $lat_f );
        update_post_meta( $post_id, self::META_LON, $lon_f );
    }

    public function enqueue_editor( ?string $hook ): void {
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'post' ) {
            return;
        }

        $vendor = plugins_url( 'assets/vendor/leaflet/', PCIO_BP_PLUGIN_FILE );
        $assets = plugins_url( 'assets/',                PCIO_BP_PLUGIN_FILE );

        wp_enqueue_style(  'leaflet',            $vendor . 'leaflet.css',     [],          '1.9.4' );
        wp_enqueue_script( 'leaflet',            $vendor . 'leaflet.js',      [],          '1.9.4', true );
        wp_enqueue_style(  'pcio-bp-blog-editor', $assets . 'blog-editor.css', [ 'leaflet' ], '1.3.0' );
        wp_enqueue_script( 'pcio-bp-blog-editor', $assets . 'blog-editor.js',  [ 'leaflet' ], '1.3.0', true );

        wp_localize_script( 'pcio-bp-blog-editor', 'PCIO_BP_EDITOR', [
            'latestUrl' => rest_url( PCIO_BP_REST_NS . '/latest' ),
            'catId'     => self::ensure_category(),
            // Read-only map prefill from the "Write blog here" link; no form to nonce-verify.
            'urlLat'    => isset( $_GET['bp_lat'] ) ? sanitize_text_field( wp_unslash( $_GET['bp_lat'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'urlLon'    => isset( $_GET['bp_lon'] ) ? sanitize_text_field( wp_unslash( $_GET['bp_lon'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'l10n'      => [
                'noLocation' => __( 'No location set', 'boat-position' ),
                'fetching'   => __( 'Fetching current position…', 'boat-position' ),
                'noFix'      => __( 'No boat position available yet.', 'boat-position' ),
            ],
        ] );
    }

    // ── Shortcode: back-to-map link ─────────────────────────────────────────────

    /**
     * [boat_log_map_link text="View on map" class="pcio-bp-maplink"]
     * Renders nothing when the current post has no stored location.
     */
    public function shortcode_map_link( $atts ): string {
        $atts = shortcode_atts( [
            'text'  => __( 'View on map', 'boat-position' ),
            'class' => 'pcio-bp-maplink',
            'id'    => 0,
        ], $atts, 'boat_log_map_link' );

        $post_id = (int) $atts['id'] ?: (int) get_the_ID();
        if ( ! $post_id ) {
            return '';
        }

        $lat = get_post_meta( $post_id, self::META_LAT, true );
        $lon = get_post_meta( $post_id, self::META_LON, true );
        if ( $lat === '' || $lon === '' ) {
            return '';
        }

        $url = add_query_arg(
            [ 'post' => $post_id, 'lat' => $lat, 'lon' => $lon ],
            home_url( 'boat-position/map' )
        );

        return sprintf(
            '<a class="%s" href="%s">%s</a>',
            esc_attr( $atts['class'] ),
            esc_url( $url ),
            esc_html( $atts['text'] )
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private static function post_is_boat_log( int $post_id ): bool {
        return has_term( self::CAT_SLUG, 'category', $post_id );
    }

    /** Latest stored boat fix, or null. */
    private static function latest_position(): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'boat_positions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT lat, lon FROM %i ORDER BY gps_time_utc DESC LIMIT 1', $table )
        );
        if ( ! $row ) {
            return null;
        }
        return [ 'lat' => (float) $row->lat, 'lon' => (float) $row->lon ];
    }
}
