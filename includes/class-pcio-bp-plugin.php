<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_BP_Plugin {

    private static ?PCIO_BP_Plugin $_instance = null;

    public static function instance(): PCIO_BP_Plugin {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct() {
        add_action( 'rest_api_init',      [ $this, 'register_rest_routes' ] );
        add_action( 'init',               [ $this, 'register_rewrites'    ] );
        add_action( 'template_redirect',  [ $this, 'serve_templates'      ] );
        new PCIO_BP_Blog();
        if ( is_admin() ) {
            ( new PCIO_BP_Settings() )->init();
        }
    }

    public function register_rest_routes(): void {
        ( new PCIO_BP_Trip_Engine() )->ensure_schema();
        ( new PCIO_BP_Rest_Ingest() )->register();
        ( new PCIO_BP_Rest_Query()  )->register();
    }

    public function register_rewrites(): void {
        add_rewrite_rule( '^boat-position/map/?$',     'index.php?pcio_bp_page=boat-map', 'top' );
        add_rewrite_rule( '^boat-position/history/?$', 'index.php?pcio_bp_page=history',  'top' );
        add_rewrite_rule( '^boat-position/plans/?$',   'index.php?pcio_bp_page=plan',     'top' );
        add_rewrite_rule( '^boat-position/plan/?$',    'index.php?pcio_bp_page=plan',     'top' ); // backward compat
        add_rewrite_tag( '%pcio_bp_page%', '([^&]+)' );
    }

    public function serve_templates(): void {
        $page = get_query_var( 'pcio_bp_page' );
        if ( ! $page ) {
            return;
        }
        // Restrict to known pages to prevent path traversal
        $allowed = [ 'boat-map', 'history', 'plan' ];
        if ( ! in_array( $page, $allowed, true ) ) {
            return;
        }
        $template = PCIO_BP_PLUGIN_DIR . 'templates/' . $page . '.php';
        if ( file_exists( $template ) ) {
            $vendor = plugins_url( 'assets/vendor/leaflet/', PCIO_BP_PLUGIN_FILE );
            $assets = plugins_url( 'assets/',               PCIO_BP_PLUGIN_FILE );

            wp_enqueue_style(  'leaflet', $vendor . 'leaflet.css', [],          '1.9.4' );
            wp_enqueue_script( 'leaflet', $vendor . 'leaflet.js',  [],          '1.9.4', false );

            $can_edit = is_user_logged_in() && current_user_can( PCIO_BP_CAP );

            // Shared translated strings injected into every front-end script.
            $l10n_json = wp_json_encode( [
                'locale'               => str_replace( '_', '-', get_locale() ),
                // history.js
                'allTrips'             => __( 'All Trips',                                             'boat-position' ),
                'tripsOn'              => __( 'Trips on {date}',                                       'boat-position' ),
                'noTripsOn'            => __( 'No trips on {date}',                                    'boat-position' ),
                'noTripsFound'         => __( 'No trips found',                                        'boat-position' ),
                'start'                => __( 'Start',                                                 'boat-position' ),
                'end'                  => __( 'End',                                                   'boat-position' ),
                'tripsSelected'        => __( '{count} trips selected',                                'boat-position' ),
                'noTripPosition'       => __( 'No trip position available.',                           'boat-position' ),
                'couldNotDetect'       => __( 'Could not detect harbour: ',                            'boat-position' ),
                'mergeBtn'             => __( '⇔ Merge {count} selected trips',                       'boat-position' ),
                'mergeConfirmLine1'    => __( 'Merge {count} trips into one?',                        'boat-position' ),
                'mergeConfirmEarliest' => __( 'Earliest:',                                            'boat-position' ),
                'mergeConfirmLatest'   => __( 'Latest:',                                              'boat-position' ),
                'mergeConfirmNote'     => __( 'The earliest trip absorbs the others. This cannot be undone.', 'boat-position' ),
                'mergeFailed'          => __( 'Merge failed.',                                        'boat-position' ),
                'saveFailed'           => __( 'Save failed.',                                         'boat-position' ),
                'deleteTripTitle'      => __( 'Delete trip',                                          'boat-position' ),
                'deleteTripConfirm1'   => __( 'Delete this logbook entry?',                           'boat-position' ),
                'deleteTripConfirm2'   => __( 'Are you absolutely sure? This cannot be undone.',      'boat-position' ),
                'deleteTripFailed'     => __( 'Delete failed.',                                       'boat-position' ),
                // boat-map.js
                'noData'               => __( 'No data',                                              'boat-position' ),
                'stopped'              => __( 'Stopped',                                              'boat-position' ),
                'nameLabel'            => __( 'Name',                                                 'boat-position' ),
                'save'                 => __( 'Save',                                                 'boat-position' ),
                'deleteBtn'            => __( 'Delete',                                               'boat-position' ),
                'deleteHarbourConfirm' => __( 'Delete "{name}"?',                                     'boat-position' ),
                'readPost'             => __( 'Read',                                                 'boat-position' ),
                'writeBlogHere'        => __( 'Write blog here',                                      'boat-position' ),
                // plan.js
                'visibleOnMap'         => __( 'Visible on map – click to hide',                       'boat-position' ),
                'hiddenFromMap'        => __( 'Hidden from map – click to show',                      'boat-position' ),
                'deletePlanTitle'      => __( 'Delete plan',                                          'boat-position' ),
                'editWaypoints'        => __( 'Edit waypoints',                                       'boat-position' ),
                'viewWaypoints'        => __( 'View waypoints',                                       'boat-position' ),
                'untitled'             => __( '(Untitled)',                                            'boat-position' ),
                'deletePlanConfirm'    => __( 'Delete plan "{title}" and all its waypoints?',         'boat-position' ),
                'today'                => __( 'Today',                                                'boat-position' ),
                'editTitle'            => __( 'Edit',                                                 'boat-position' ),
                'rebaseFromHere'       => __( 'Rebase from here',                                     'boat-position' ),
                'dragToReorder'        => __( 'Drag to reorder',                                      'boat-position' ),
                'cancel'               => __( 'Cancel',                                               'boat-position' ),
                'rebaseShift'          => __( 'Shift',                                                'boat-position' ),
                'rebaseAndAll'         => __( 'and all following waypoints by',                       'boat-position' ),
                'rebaseDays'           => __( 'days',                                                 'boat-position' ),
                'apply'                => __( 'Apply',                                                'boat-position' ),
                'rebaseHint'           => __( '(positive = later, negative = earlier)',               'boat-position' ),
                'deleteWpConfirm'      => __( 'Delete waypoint "{name}"?',                            'boat-position' ),
                'planSuffix'           => __( '(plan)',                                               'boat-position' ),
                'totalDistLabel'       => __( 'Total distance',                                       'boat-position' ),
            ] );
            wp_enqueue_style(  'pcio-bp-boat-map', $assets . 'boat-map.css', [ 'leaflet' ], '3.0' );
            wp_enqueue_script( 'pcio-bp-boat-map', $assets . 'boat-map.js',  [ 'leaflet' ], '1.3.0', false );
            wp_add_inline_script( 'pcio-bp-boat-map', 'const PCIO_BP_L10N = ' . $l10n_json . ';', 'before' );
            wp_add_inline_script(
                'pcio-bp-boat-map',
                'const PCIO_BP_REST          = ' . wp_json_encode( rest_url( PCIO_BP_REST_NS ) )                              . ";\n" .
                'const PCIO_BP_CAN_EDIT      = ' . ( $can_edit ? 'true' : 'false' )                                           . ";\n" .
                'const PCIO_BP_IS_LOGGED_IN  = ' . ( is_user_logged_in() ? 'true' : 'false' )                                 . ";\n" .
                'const PCIO_BP_NONCE         = ' . wp_json_encode( $can_edit ? wp_create_nonce( 'wp_rest' ) : '' )            . ";\n" .
                'const PCIO_BP_NEW_POST_URL  = ' . wp_json_encode( $can_edit ? admin_url( 'post-new.php' ) : '' )            . ";\n" .
                'const PCIO_BP_PLAN_URL      = ' . wp_json_encode( home_url( 'boat-position/plans' ) )                         . ';',
                'before'
            );
            // MapLibre CSS (self-hosted, no JS dependency)
            wp_enqueue_style( 'pcio-bp-maplibre', $assets . 'vendor/maplibre/maplibre-gl.css', [], '4.7.1' );

            // Classic IIFE/UMD builds — expose maplibregl and THREE as globals
            wp_enqueue_script( 'pcio-bp-maplibre-js', $assets . 'vendor/maplibre/maplibre-gl.js', [],         '4.7.1', true );
            wp_enqueue_script( 'pcio-bp-three',        $assets . 'vendor/three/three.min.js',       [],        '0.148.0', true );

            // boat-3d.js is a classic IIFE; depends on both globals above
            wp_enqueue_style(  'pcio-bp-3d-css', $assets . 'boat-3d.css', [], '1.2.0' );
            wp_enqueue_script( 'pcio-bp-3d',     $assets . 'boat-3d.js',  [ 'pcio-bp-maplibre-js', 'pcio-bp-three' ], '1.7.0', true );

            wp_enqueue_style(  'pcio-bp-history', $assets . 'history.css', [ 'leaflet', 'pcio-bp-maplibre' ], '1.4.1' );
            wp_enqueue_script( 'pcio-bp-history', $assets . 'history.js',  [ 'leaflet', 'pcio-bp-3d' ], '1.5.0', false );
            wp_add_inline_script( 'pcio-bp-history', 'const PCIO_BP_L10N = ' . $l10n_json . ';', 'before' );
            wp_add_inline_script(
                'pcio-bp-history',
                'const PCIO_BP_API        = ' . wp_json_encode( rest_url( PCIO_BP_REST_NS ) )           . ";\n" .
                'const PCIO_BP_MAP_URL = ' . wp_json_encode( home_url( 'boat-position/map' ) ) . ";\n" .
                'const PCIO_BP_CAN_EDIT   = ' . ( $can_edit ? 'true' : 'false' )                  . ";\n" .
                'const PCIO_BP_NONCE   = ' . wp_json_encode( $can_edit ? wp_create_nonce( 'wp_rest' ) : '' ) . ';',
                'before'
            );

            wp_enqueue_style(  'pcio-bp-plan', $assets . 'plan.css', [], '1.3.0' );
            wp_enqueue_script( 'pcio-bp-plan', $assets . 'plan.js',  [], '1.3.0', false );
            wp_add_inline_script( 'pcio-bp-plan', 'const PCIO_BP_L10N = ' . $l10n_json . ';', 'before' );
            wp_add_inline_script(
                'pcio-bp-plan',
                'const PCIO_BP_API           = ' . wp_json_encode( rest_url( PCIO_BP_REST_NS ) )                   . ";\n" .
                'const PCIO_BP_CAN_EDIT      = ' . ( $can_edit ? 'true' : 'false' )                                . ";\n" .
                'const PCIO_BP_IS_LOGGED_IN  = ' . ( is_user_logged_in() ? 'true' : 'false' )                     . ";\n" .
                'const PCIO_BP_NONCE         = ' . wp_json_encode( $can_edit ? wp_create_nonce( 'wp_rest' ) : ( is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '' ) ) . ';',
                'before'
            );

            require $template;
            exit;
        }
    }

    // ── Activation / deactivation ─────────────────────────────────────────────

    public static function activate(): void {
        // Bootstrap the schema and seed harbour data (both idempotent)
        $engine = new PCIO_BP_Trip_Engine();
        $engine->seed_harbours();
        // Ensure the Boat Log category used by the blog integration exists
        PCIO_BP_Blog::ensure_category();
        // Give administrators the route-management capability out of the box.
        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( PCIO_BP_CAP ) ) {
            $admin->add_cap( PCIO_BP_CAP );
        }
        // Register rewrites then flush so pages are immediately reachable
        add_rewrite_rule( '^boat-position/map/?$',     'index.php?pcio_bp_page=boat-map', 'top' );
        add_rewrite_rule( '^boat-position/history/?$', 'index.php?pcio_bp_page=history',  'top' );
        add_rewrite_rule( '^boat-position/plans/?$',   'index.php?pcio_bp_page=plan',     'top' );
        add_rewrite_rule( '^boat-position/plan/?$',    'index.php?pcio_bp_page=plan',     'top' ); // backward compat
        add_rewrite_tag( '%pcio_bp_page%', '([^&]+)' );
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    // ── Uninstall ─────────────────────────────────────────────────────────────
    // Remove the custom capability from the administrator role and from any
    // individual users it was granted to, so nothing is left behind.
    public static function uninstall(): void {
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->remove_cap( PCIO_BP_CAP );
        }
        $users = get_users( [ 'capability' => PCIO_BP_CAP, 'fields' => [ 'ID' ] ] );
        foreach ( $users as $u ) {
            ( new WP_User( $u->ID ) )->remove_cap( PCIO_BP_CAP );
        }
    }
}
