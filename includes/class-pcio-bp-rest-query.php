<?php
/**
 * REST read/write endpoints for trips, legs, harbours and live position.
 *
 * GET    /wp-json/boat-position/v1/latest
 * GET    /wp-json/boat-position/v1/trips
 * GET    /wp-json/boat-position/v1/trips/active-dates
 * GET    /wp-json/boat-position/v1/trips/{id}
 * GET    /wp-json/boat-position/v1/trips/{id}/points
 * POST   /wp-json/boat-position/v1/trips/{id}/harbours   (requires login + edit_posts)
 * POST   /wp-json/boat-position/v1/trips/merge           (requires login + edit_posts)
 * GET    /wp-json/boat-position/v1/legs/{id}/points
 * GET    /wp-json/boat-position/v1/harbours
 * POST   /wp-json/boat-position/v1/harbours              (requires login + edit_posts)
 * PATCH  /wp-json/boat-position/v1/harbours/{id}         (requires login + edit_posts)
 * DELETE /wp-json/boat-position/v1/harbours/{id}         (requires login + edit_posts)
 *
 * GET    /wp-json/boat-position/v1/planned-trips
 * POST   /wp-json/boat-position/v1/planned-trips         (requires login + edit_posts)
 * GET    /wp-json/boat-position/v1/planned-trips/{id}
 * PATCH  /wp-json/boat-position/v1/planned-trips/{id}    (requires login + edit_posts)
 * DELETE /wp-json/boat-position/v1/planned-trips/{id}    (requires login + edit_posts)
 * GET    /wp-json/boat-position/v1/planned-trips/{id}/waypoints
 * POST   /wp-json/boat-position/v1/planned-trips/{id}/waypoints   (requires login + edit_posts)
 * PATCH  /wp-json/boat-position/v1/planned-trips/{id}/waypoints/{wid}  (requires login + edit_posts)
 * DELETE /wp-json/boat-position/v1/planned-trips/{id}/waypoints/{wid}  (requires login + edit_posts)
 * POST   /wp-json/boat-position/v1/planned-trips/{id}/rebase      (requires login + edit_posts)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables with real-time GPS data. Caching live positions is counterproductive; write operations are not cacheable.

class PCIO_BP_Rest_Query {

    // Speed (knots) at or above which the boat is considered to be moving.
    // Must match the threshold used by the live-view front-end (boat-map.js).
    private const MOVING_SPEED_KN = 1.0;

    public function register(): void {

        register_rest_route( PCIO_BP_REST_NS, '/latest', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'latest' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/trips', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_trips' ],
            'permission_callback' => '__return_true',
        ] );

        // Static routes must be registered before the parameterised (?P<id>\d+) pattern
        register_rest_route( PCIO_BP_REST_NS, '/trips/active-dates', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'active_dates' ],
            'permission_callback' => '__return_true',
        ] );

        // Current (active) trip track, drawn live on the map
        register_rest_route( PCIO_BP_REST_NS, '/trips/active/points', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'active_track' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/trips/merge', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'merge_trips' ],
            'permission_callback' => [ $this, 'can_edit' ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/trips/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_trip' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/trips/(?P<id>\d+)/points', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'trip_points' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/trips/(?P<id>\d+)/harbours', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'update_harbours' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/trips/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_trip' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/legs/(?P<id>\d+)/points', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'leg_points' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/harbours', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_harbours' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/harbours', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_harbour' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [
                'name'     => [ 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'lat'      => [ 'type' => 'number',  'required' => true ],
                'lon'      => [ 'type' => 'number',  'required' => true ],
                'radius_m' => [ 'type' => 'integer', 'default'  => 300  ],
            ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/harbours/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'update_harbour' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [
                'id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                'name' => [ 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/harbours/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_harbour' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        ] );

        // ── Planned trips ─────────────────────────────────────────────────────

        register_rest_route( PCIO_BP_REST_NS, '/planned-trips', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_planned_trips' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/planned-trips', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_planned_trip' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [
                'title' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'notes' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
            ],
        ] );

        // Static sub-routes before parameterised (?P<id>\d+)
        register_rest_route( PCIO_BP_REST_NS, '/planned-trips/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_planned_trip' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/planned-trips/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'update_planned_trip' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [
                'id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                'title'   => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'notes'   => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field' ],
                'visible' => [ 'type' => 'integer' ],
            ],
        ] );

        // Logged-in users can toggle the visible flag; anonymous users use localStorage
        register_rest_route( PCIO_BP_REST_NS, '/planned-trips/(?P<id>\d+)/visible', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'set_plan_visible' ],
            'permission_callback' => static function () {
                return is_user_logged_in();
            },
            'args'                => [
                'id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                'visible' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/planned-trips/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_planned_trip' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/planned-trips/(?P<id>\d+)/waypoints', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_waypoints' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/planned-trips/(?P<id>\d+)/waypoints', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_waypoint' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [
                'id'         => [ 'type' => 'integer', 'minimum' => 1 ],
                'name'       => [ 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'lat'        => [ 'type' => 'number',  'required' => true ],
                'lon'        => [ 'type' => 'number',  'required' => true ],
                'eta_date'   => [ 'type' => 'string',  'default'  => null ],
                'harbour_id' => [ 'type' => 'integer', 'default'  => null ],
                'sort_order' => [ 'type' => 'integer', 'default'  => 0    ],
            ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/planned-trips/(?P<id>\d+)/waypoints/(?P<wid>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'update_waypoint' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [
                'id'         => [ 'type' => 'integer', 'minimum' => 1 ],
                'wid'        => [ 'type' => 'integer', 'minimum' => 1 ],
                'name'       => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'eta_date'   => [ 'type' => 'string'  ],
                'sort_order' => [ 'type' => 'integer' ],
            ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/planned-trips/(?P<id>\d+)/waypoints/(?P<wid>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_waypoint' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [
                'id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                'wid' => [ 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/planned-trips/(?P<id>\d+)/rebase', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rebase_plan' ],
            'permission_callback' => [ $this, 'can_edit' ],
            'args'                => [
                'id'         => [ 'type' => 'integer', 'minimum' => 1 ],
                // from_wid: shift this waypoint and all after it
                'from_wid'   => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                // delta_days: positive = later, negative = earlier
                'delta_days' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );
    }

    // ── Permission ────────────────────────────────────────────────────────────

    public function can_edit(): bool|WP_Error {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'unauthorized', __( 'You must be logged in.', 'boat-position' ), [ 'status' => 401 ] );
        }
        if ( ! current_user_can( PCIO_BP_CAP ) ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to edit trips.', 'boat-position' ), [ 'status' => 403 ] );
        }
        return true;
    }

    // ── Latest position ───────────────────────────────────────────────────────

    public function latest(): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'boat_positions';
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT lat, lon, speed, course, gps_time_utc FROM %i ORDER BY gps_time_utc DESC LIMIT 1', $table )
        );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'No position data available.', 'boat-position' ), [ 'status' => 404 ] );
        }

        // When the boat is not moving, work out when it actually stopped:
        // the first fix recorded after the most recent moving fix.
        $since = null;
        if ( (float) $row->speed < self::MOVING_SPEED_KN ) {
            $last_moving = $wpdb->get_var( $wpdb->prepare(
                'SELECT gps_time_utc FROM %i WHERE speed >= %f ORDER BY gps_time_utc DESC LIMIT 1',
                $table, self::MOVING_SPEED_KN
            ) );
            if ( $last_moving ) {
                $stop_start = $wpdb->get_var( $wpdb->prepare(
                    'SELECT MIN(gps_time_utc) FROM %i WHERE gps_time_utc > %s',
                    $table, $last_moving
                ) );
            } else {
                // No moving fix on record – count from the earliest known fix.
                $stop_start = $wpdb->get_var( $wpdb->prepare(
                    'SELECT MIN(gps_time_utc) FROM %i', $table
                ) );
            }
            if ( $stop_start ) {
                $since = strtotime( $stop_start . ' UTC' );
            }
        }

        return new WP_REST_Response( [
            'lat'    => (float) $row->lat,
            'lon'    => (float) $row->lon,
            'speed'  => (float) $row->speed,
            'course' => (float) $row->course,
            'time'   => strtotime( $row->gps_time_utc . ' UTC' ),
            'since'  => $since,
        ], 200 );
    }

    // ── Trip list ─────────────────────────────────────────────────────────────

    public function list_trips(): WP_REST_Response {
        global $wpdb;
        $t_trips = $wpdb->prefix . 'boat_trips';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t_trips ) ) ) ) {
            return new WP_REST_Response( [], 200 );
        }
        $trips = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, started_at, ended_at, distance_nm, harbour_start, harbour_end FROM %i WHERE state = 'closed' ORDER BY started_at DESC",
                $t_trips
            )
        );
        return new WP_REST_Response( array_map( [ $this, 'format_trip' ], $trips ?: [] ), 200 );
    }

    // ── Active dates (calendar highlighting) ─────────────────────────────────

    public function active_dates(): WP_REST_Response {
        global $wpdb;
        $t_trips = $wpdb->prefix . 'boat_trips';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t_trips ) ) ) ) {
            return new WP_REST_Response( [], 200 );
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT DISTINCT DATE(started_at) AS d FROM %i WHERE state = 'closed' ORDER BY d DESC", $t_trips )
        );
        return new WP_REST_Response( array_column( $rows ?: [], 'd' ), 200 );
    }

    // ── Single trip + leg summaries ───────────────────────────────────────────

    public function get_trip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t_trips = $wpdb->prefix . 'boat_trips';
        $t_legs  = $wpdb->prefix . 'boat_legs';
        $id      = (int) $request->get_param( 'id' );

        $trip = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t_trips, $id ) );
        if ( ! $trip ) {
            return new WP_Error( 'not_found', __( 'Trip not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        $legs = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, started_at, ended_at, distance_nm, is_estimated, point_count FROM %i WHERE trip_id = %d ORDER BY started_at ASC',
            $t_legs, $id
        ) );

        return new WP_REST_Response( [
            'trip' => $this->format_trip( $trip ),
            'legs' => array_map( [ $this, 'format_leg_summary' ], $legs ?: [] ),
        ], 200 );
    }

    // ── All waypoints for a trip, grouped by leg ──────────────────────────────

    public function trip_points( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param( 'id' );
        return new WP_REST_Response( $this->build_trip_points( $id ), 200 );
    }

    // ── Current (active) trip track for the live map ──────────────────────────

    public function active_track(): WP_REST_Response {
        global $wpdb;
        $t_trips = $wpdb->prefix . 'boat_trips';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t_trips ) ) ) ) {
            return new WP_REST_Response( [], 200 );
        }
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM %i WHERE state = 'active' ORDER BY started_at DESC LIMIT 1",
            $t_trips
        ) );
        return new WP_REST_Response( $id ? $this->build_trip_points( $id ) : [], 200 );
    }

    /**
     * Build the per-leg point structure for a trip. Real legs carry their GPS
     * points; estimated legs carry their two end coordinates. Shared by
     * trip_points() and active_track().
     */
    private function build_trip_points( int $trip_id ): array {
        global $wpdb;
        $t_pos  = $wpdb->prefix . 'boat_positions';
        $t_legs = $wpdb->prefix . 'boat_legs';

        $legs = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, is_estimated, est_lat1, est_lon1, est_lat2, est_lon2 FROM %i WHERE trip_id = %d ORDER BY started_at ASC',
            $t_legs, $trip_id
        ) );

        $result = [];
        foreach ( $legs ?: [] as $leg ) {
            if ( (int) $leg->is_estimated ) {
                $pts = [];
                if ( $leg->est_lat1 !== null ) $pts[] = [ (float) $leg->est_lat1, (float) $leg->est_lon1 ];
                if ( $leg->est_lat2 !== null ) $pts[] = [ (float) $leg->est_lat2, (float) $leg->est_lon2 ];
                $result[] = [ 'leg_id' => (int) $leg->id, 'is_estimated' => true, 'points' => $pts ];
            } else {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    'SELECT lat, lon, speed, course, gps_time_utc AS t FROM %i WHERE leg_id = %d ORDER BY gps_time_utc ASC',
                    $t_pos, (int) $leg->id
                ) );
                $result[] = [
                    'leg_id'       => (int) $leg->id,
                    'is_estimated' => false,
                    'points'       => array_map( static fn( $p ) => [
                        (float) $p->lat,
                        (float) $p->lon,
                        round( (float) $p->speed,  1 ),
                        round( (float) $p->course, 0 ),
                        strtotime( $p->t . ' UTC' ),
                    ], $rows ?: [] ),
                ];
            }
        }

        return $result;
    }

    // ── Waypoints for a single leg ────────────────────────────────────────────

    public function leg_points( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $t_pos = $wpdb->prefix . 'boat_positions';
        $id    = (int) $request->get_param( 'id' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT lat, lon, speed, course, gps_time_utc AS t FROM %i WHERE leg_id = %d ORDER BY gps_time_utc ASC',
            $t_pos, $id
        ) );
        return new WP_REST_Response( array_map( static fn( $p ) => [
            'lat'    => (float) $p->lat,
            'lon'    => (float) $p->lon,
            'speed'  => round( (float) $p->speed,  1 ),
            'course' => round( (float) $p->course, 0 ),
            't'      => strtotime( $p->t . ' UTC' ),
        ], $rows ?: [] ), 200 );
    }

    // ── Harbour list ──────────────────────────────────────────────────────────

    public function list_harbours(): WP_REST_Response {
        global $wpdb;
        $t_harbours = $wpdb->prefix . 'boat_harbours';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t_harbours ) ) ) ) {
            return new WP_REST_Response( [], 200 );
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT id, name, lat, lon, radius_m FROM %i WHERE lat IS NOT NULL ORDER BY name ASC', $t_harbours )
        );
        return new WP_REST_Response( $rows ?: [], 200 );
    }

    // ── Create harbour ─────────────────────────────────────────────────────────

    public function create_harbour( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t        = $wpdb->prefix . 'boat_harbours';
        $name     = sanitize_text_field( $request->get_param( 'name' ) );
        $lat      = (float) $request->get_param( 'lat' );
        $lon      = (float) $request->get_param( 'lon' );
        $radius_m = (int) ( $request->get_param( 'radius_m' ) ?? 300 );

        if ( $name === '' ) {
            return new WP_Error( 'bad_request', __( 'Name is required.', 'boat-position' ), [ 'status' => 400 ] );
        }
        if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
            return new WP_Error( 'bad_request', __( 'Invalid coordinates.', 'boat-position' ), [ 'status' => 400 ] );
        }

        $wpdb->insert(
            $t,
            [ 'name' => $name, 'lat' => $lat, 'lon' => $lon, 'radius_m' => $radius_m ],
            [ '%s', '%f', '%f', '%d' ]
        );

        return new WP_REST_Response( [
            'id'       => $wpdb->insert_id,
            'name'     => $name,
            'lat'      => $lat,
            'lon'      => $lon,
            'radius_m' => $radius_m,
        ], 201 );
    }

    // ── Rename harbour ────────────────────────────────────────────────────────

    public function update_harbour( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t    = $wpdb->prefix . 'boat_harbours';
        $id   = (int) $request->get_param( 'id' );
        $name = sanitize_text_field( $request->get_param( 'name' ) ?? '' );

        if ( $name === '' ) {
            return new WP_Error( 'bad_request', __( 'Name is required.', 'boat-position' ), [ 'status' => 400 ] );
        }

        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, $id ) );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Harbour not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        $wpdb->update( $t, [ 'name' => $name ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );

        return new WP_REST_Response( [
            'id'       => $id,
            'name'     => $name,
            'lat'      => (float) $row->lat,
            'lon'      => (float) $row->lon,
            'radius_m' => (int)   $row->radius_m,
        ], 200 );
    }

    // ── Delete harbour ────────────────────────────────────────────────────────

    public function delete_harbour( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t  = $wpdb->prefix . 'boat_harbours';
        $id = (int) $request->get_param( 'id' );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $t, $id ) );
        if ( ! $exists ) {
            return new WP_Error( 'not_found', __( 'Harbour not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    // ── Delete a trip (logbook entry) ─────────────────────────────────────────
    // Authentication is handled by can_edit() + WP cookie/nonce (X-WP-Nonce header).
    // The InnoDB FK cascade removes the trip's legs and their GPS positions.

    public function delete_trip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t  = $wpdb->prefix . 'boat_trips';
        $id = (int) $request->get_param( 'id' );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $t, $id ) );
        if ( ! $exists ) {
            return new WP_Error( 'not_found', __( 'Trip not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    // ── Update harbour names on a trip ────────────────────────────────────────
    // Authentication is handled by can_edit() + WP cookie/nonce (X-WP-Nonce header).

    public function update_harbours( WP_REST_Request $request ): WP_REST_Response {        global $wpdb;
        $t_trips    = $wpdb->prefix . 'boat_trips';
        $t_pos      = $wpdb->prefix . 'boat_positions';
        $t_legs     = $wpdb->prefix . 'boat_legs';
        $t_harbours = $wpdb->prefix . 'boat_harbours';

        $id        = (int) $request->get_param( 'id' );
        $start_raw = $request->get_param( 'harbour_start' );
        $end_raw   = $request->get_param( 'harbour_end' );

        $start = ( $start_raw !== null && trim( $start_raw ) !== '' ) ? sanitize_text_field( $start_raw ) : null;
        $end   = ( $end_raw   !== null && trim( $end_raw )   !== '' ) ? sanitize_text_field( $end_raw )   : null;

        $ok = $wpdb->update(
            $t_trips,
            [ 'harbour_start' => $start, 'harbour_end' => $end ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        // Persist any newly named harbours to wp_boat_harbours
        $new_harbours = [];
        foreach ( [ 'start' => $start, 'end' => $end ] as $which => $name ) {
            if ( $name === null ) continue;
            $exists = $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM %i WHERE name = %s LIMIT 1', $t_harbours, $name
            ) );
            if ( $exists ) continue;

            if( 'start' === $which ){
                $pt = $wpdb->get_row( $wpdb->prepare(
                    'SELECT p.lat, p.lon FROM %i p INNER JOIN %i l ON l.id = p.leg_id WHERE l.trip_id = %d AND l.is_estimated = 0 ORDER BY p.gps_time_utc ASC LIMIT 1',
                    $t_pos, $t_legs, $id
                  )
                );
            } else {
                $pt = $wpdb->get_row( $wpdb->prepare(
                    'SELECT p.lat, p.lon FROM %i p INNER JOIN %i l ON l.id = p.leg_id WHERE l.trip_id = %d AND l.is_estimated = 0 ORDER BY p.gps_time_utc DESC LIMIT 1',
                    $t_pos, $t_legs, $id
                  )
                );
            }
            
            if ( ! $pt ) continue;

            $wpdb->insert(
                $t_harbours,
                [ 'name' => $name, 'lat' => (float) $pt->lat, 'lon' => (float) $pt->lon, 'radius_m' => 300 ],
                [ '%s', '%f', '%f', '%d' ]
            );
            $new_harbours[] = $name;
        }

        return new WP_REST_Response( [ 'ok' => $ok !== false, 'new_harbours' => $new_harbours ], 200 );
    }

    // ── Merge two trips into one ──────────────────────────────────────────────

    public function merge_trips( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t_trips = $wpdb->prefix . 'boat_trips';
        $t_legs  = $wpdb->prefix . 'boat_legs';

        $keep_id = (int) ( $request->get_param( 'keep_id' ) ?? 0 );
        $drop_id = (int) ( $request->get_param( 'drop_id' ) ?? 0 );

        if ( ! $keep_id || ! $drop_id || $keep_id === $drop_id ) {
            return new WP_Error( 'bad_request', __( 'Invalid trip ids.', 'boat-position' ), [ 'status' => 400 ] );
        }

        $keep = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t_trips, $keep_id ) );
        $drop = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t_trips, $drop_id ) );

        if ( ! $keep || ! $drop ) {
            return new WP_Error( 'not_found', __( 'Trip not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        // Reassign all legs from the dropped trip to the kept trip
        $wpdb->update( $t_legs, [ 'trip_id' => $keep_id ], [ 'trip_id' => $drop_id ], [ '%d' ], [ '%d' ] );

        // Recalculate total distance
        $total_nm = (float) $wpdb->get_var( $wpdb->prepare(
            'SELECT SUM(distance_nm) FROM %i WHERE trip_id = %d', $t_legs, $keep_id
        ) );

        // Extend the kept trip to cover the dropped trip's end
        $wpdb->update(
            $t_trips,
            [
                'ended_at'    => $drop->ended_at,
                'distance_nm' => round( $total_nm, 3 ),
                'harbour_end' => $drop->harbour_end,
                'state'       => $drop->state,
            ],
            [ 'id' => $keep_id ],
            [ '%s', '%f', '%s', '%s' ],
            [ '%d' ]
        );

        // Remove the now-empty dropped trip
        $wpdb->delete( $t_trips, [ 'id' => $drop_id ], [ '%d' ] );

        return new WP_REST_Response( [ 'ok' => true, 'kept_id' => $keep_id ], 200 );
    }

    // ── Response formatters ───────────────────────────────────────────────────

    private function format_trip( object $t ): array {
        $start = $t->started_at ?? null;
        $end   = $t->ended_at   ?? null;
        $dur   = null;
        if ( $start && $end ) {
            $dur = (int) round( ( strtotime( $end ) - strtotime( $start ) ) / 60 );
        }
        return [
            'id'            => (int)   $t->id,
            'started_at'    => $start,
            'ended_at'      => $end,
            'distance_nm'   => $t->distance_nm !== null ? round( (float) $t->distance_nm, 1 ) : null,
            'harbour_start' => $t->harbour_start ?? null,
            'harbour_end'   => $t->harbour_end   ?? null,
            'duration_min'  => $dur,
        ];
    }

    private function format_leg_summary( object $l ): array {
        return [
            'id'           => (int)  $l->id,
            'started_at'   => $l->started_at,
            'ended_at'     => $l->ended_at,
            'distance_nm'  => $l->distance_nm !== null ? round( (float) $l->distance_nm, 1 ) : null,
            'is_estimated' => (bool) $l->is_estimated,
            'point_count'  => (int)  $l->point_count,
        ];
    }

    // ── Planned trips ─────────────────────────────────────────────────────────

    public function list_planned_trips(): WP_REST_Response {
        global $wpdb;
        $t = $wpdb->prefix . 'boat_planned_trips';
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT id, title, notes, visible, created_at, updated_at FROM %i ORDER BY id DESC', $t )
        );
        return new WP_REST_Response(
            array_map( [ $this, 'format_planned_trip' ], $rows ?: [] ),
            200
        );
    }

    public function get_planned_trip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t  = $wpdb->prefix . 'boat_planned_trips';
        $id = (int) $request->get_param( 'id' );

        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, $id ) );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Plan not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        return new WP_REST_Response( $this->format_planned_trip( $row ), 200 );
    }

    public function create_planned_trip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t     = $wpdb->prefix . 'boat_planned_trips';
        $title = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
        $notes = sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' );

        $wpdb->insert(
            $t,
            [ 'title' => $title, 'notes' => $notes, 'visible' => 1 ],
            [ '%s', '%s', '%d' ]
        );

        if ( $wpdb->last_error || ! $wpdb->insert_id ) {
            return new WP_Error( 'db_error', __( 'Failed to create plan.', 'boat-position' ), [ 'status' => 500 ] );
        }

        $id  = $wpdb->insert_id;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, $id ) );

        if ( ! $row ) {
            return new WP_Error( 'db_error', __( 'Failed to create plan.', 'boat-position' ), [ 'status' => 500 ] );
        }

        return new WP_REST_Response( $this->format_planned_trip( $row ), 201 );
    }

    public function set_plan_visible( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t   = $wpdb->prefix . 'boat_planned_trips';
        $id  = (int) $request->get_param( 'id' );
        $vis = (int) (bool) $request->get_param( 'visible' );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $t, $id ) );
        if ( ! $exists ) {
            return new WP_Error( 'not_found', __( 'Plan not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        $wpdb->update( $t, [ 'visible' => $vis ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        $updated = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, $id ) );
        return new WP_REST_Response( $this->format_planned_trip( $updated ), 200 );
    }

    public function update_planned_trip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t   = $wpdb->prefix . 'boat_planned_trips';
        $id  = (int) $request->get_param( 'id' );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, $id ) );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Plan not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        $fields = [];
        $fmts   = [];
        $title_raw   = $request->get_param( 'title' );
        $notes_raw   = $request->get_param( 'notes' );
        $visible_raw = $request->get_param( 'visible' );
        if ( $title_raw   !== null ) { $fields['title']   = sanitize_text_field( $title_raw );     $fmts[] = '%s'; }
        if ( $notes_raw   !== null ) { $fields['notes']   = sanitize_textarea_field( $notes_raw ); $fmts[] = '%s'; }
        if ( $visible_raw !== null ) { $fields['visible'] = (int) (bool) $visible_raw;             $fmts[] = '%d'; }

        if ( $fields ) {
            $wpdb->update( $t, $fields, [ 'id' => $id ], $fmts, [ '%d' ] );
        }

        $updated = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, $id ) );
        return new WP_REST_Response( $this->format_planned_trip( $updated ), 200 );
    }

    public function delete_planned_trip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $t  = $wpdb->prefix . 'boat_planned_trips';
        $id = (int) $request->get_param( 'id' );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $t, $id ) );
        if ( ! $exists ) {
            return new WP_Error( 'not_found', __( 'Plan not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        // Cascades to boat_planned_waypoints via FK
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    // ── Planned waypoints ─────────────────────────────────────────────────────

    public function list_waypoints( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $tp  = $wpdb->prefix . 'boat_planned_trips';
        $tw  = $wpdb->prefix . 'boat_planned_waypoints';
        $id  = (int) $request->get_param( 'id' );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $tp, $id ) );
        if ( ! $exists ) {
            return new WP_Error( 'not_found', __( 'Plan not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, plan_id, sort_order, harbour_id, name, lat, lon, eta_date
             FROM %i WHERE plan_id = %d ORDER BY sort_order ASC, id ASC',
            $tw, $id
        ) );

        return new WP_REST_Response(
            array_map( [ $this, 'format_waypoint' ], $rows ?: [] ),
            200
        );
    }

    public function create_waypoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $tp         = $wpdb->prefix . 'boat_planned_trips';
        $tw         = $wpdb->prefix . 'boat_planned_waypoints';
        $plan_id    = (int) $request->get_param( 'id' );
        $name       = sanitize_text_field( $request->get_param( 'name' ) ?? '' );
        $lat        = (float) $request->get_param( 'lat' );
        $lon        = (float) $request->get_param( 'lon' );
        $eta_raw    = $request->get_param( 'eta_date' );
        $harbour_id = $request->get_param( 'harbour_id' );
        $sort_order = (int) ( $request->get_param( 'sort_order' ) ?? 0 );

        if ( $name === '' ) {
            return new WP_Error( 'bad_request', __( 'Name is required.', 'boat-position' ), [ 'status' => 400 ] );
        }
        if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
            return new WP_Error( 'bad_request', __( 'Invalid coordinates.', 'boat-position' ), [ 'status' => 400 ] );
        }

        $plan_exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $tp, $plan_id ) );
        if ( ! $plan_exists ) {
            return new WP_Error( 'not_found', __( 'Plan not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        $eta_date = $this->parse_eta( $eta_raw );

        $wpdb->insert(
            $tw,
            [
                'plan_id'    => $plan_id,
                'sort_order' => $sort_order,
                'harbour_id' => $harbour_id ? (int) $harbour_id : null,
                'name'       => $name,
                'lat'        => $lat,
                'lon'        => $lon,
                'eta_date'   => $eta_date,
            ],
            [ '%d', '%d', $harbour_id ? '%d' : 'NULL', '%s', '%f', '%f', $eta_date ? '%s' : 'NULL' ]
        );

        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d', $tw, $wpdb->insert_id
        ) );

        return new WP_REST_Response( $this->format_waypoint( $row ), 201 );
    }

    public function update_waypoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $tw      = $wpdb->prefix . 'boat_planned_waypoints';
        $plan_id = (int) $request->get_param( 'id' );
        $wid     = (int) $request->get_param( 'wid' );

        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND plan_id = %d', $tw, $wid, $plan_id
        ) );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Waypoint not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        $fields = [];
        $fmts   = [];
        $name_raw = $request->get_param( 'name' );
        $eta_raw  = $request->get_param( 'eta_date' );

        if ( $name_raw !== null ) {
            $name = sanitize_text_field( $name_raw );
            if ( $name === '' ) {
                return new WP_Error( 'bad_request', __( 'Name cannot be empty.', 'boat-position' ), [ 'status' => 400 ] );
            }
            $fields['name'] = $name;
            $fmts[]         = '%s';
        }

        // eta_date accepts a YYYY-MM-DD string or null/empty to clear it
        if ( array_key_exists( 'eta_date', $request->get_params() ) ) {
            $fields['eta_date'] = $this->parse_eta( $eta_raw );
            $fmts[]             = $fields['eta_date'] ? '%s' : 'NULL';
        }

        $sort_raw = $request->get_param( 'sort_order' );
        if ( $sort_raw !== null ) {
            $fields['sort_order'] = max( 0, (int) $sort_raw );
            $fmts[]               = '%d';
        }

        if ( $fields ) {
            $wpdb->update( $tw, $fields, [ 'id' => $wid ], $fmts, [ '%d' ] );
        }

        $updated = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $tw, $wid ) );
        return new WP_REST_Response( $this->format_waypoint( $updated ), 200 );
    }

    public function delete_waypoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $tw      = $wpdb->prefix . 'boat_planned_waypoints';
        $plan_id = (int) $request->get_param( 'id' );
        $wid     = (int) $request->get_param( 'wid' );

        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND plan_id = %d', $tw, $wid, $plan_id
        ) );
        if ( ! $exists ) {
            return new WP_Error( 'not_found', 'Waypoint not found.', [ 'status' => 404 ] );
        }

        $wpdb->delete( $tw, [ 'id' => $wid ], [ '%d' ] );
        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    // ── Rebase: shift ETAs from a waypoint onwards by delta_days ─────────────

    public function rebase_plan( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $tp         = $wpdb->prefix . 'boat_planned_trips';
        $tw         = $wpdb->prefix . 'boat_planned_waypoints';
        $plan_id    = (int) $request->get_param( 'id' );
        $from_wid   = (int) $request->get_param( 'from_wid' );
        $delta_days = (int) $request->get_param( 'delta_days' );

        $plan_exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $tp, $plan_id ) );
        if ( ! $plan_exists ) {
            return new WP_Error( 'not_found', __( 'Plan not found.', 'boat-position' ), [ 'status' => 404 ] );
        }

        // Verify the anchor waypoint belongs to this plan
        $anchor = $wpdb->get_row( $wpdb->prepare(
            'SELECT id, sort_order FROM %i WHERE id = %d AND plan_id = %d', $tw, $from_wid, $plan_id
        ) );
        if ( ! $anchor ) {
            return new WP_Error( 'not_found', 'Waypoint not found in this plan.', [ 'status' => 404 ] );
        }

        // Fetch all waypoints at or after the anchor's sort_order that have an eta_date
        $to_update = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, eta_date FROM %i
             WHERE plan_id = %d AND sort_order >= %d AND eta_date IS NOT NULL
             ORDER BY sort_order ASC, id ASC',
            $tw, $plan_id, (int) $anchor->sort_order
        ) );

        $updated = [];
        foreach ( $to_update as $wp_row ) {
            $new_date = gmdate( 'Y-m-d', strtotime( $wp_row->eta_date ) + $delta_days * DAY_IN_SECONDS );
            $wpdb->update( $tw, [ 'eta_date' => $new_date ], [ 'id' => (int) $wp_row->id ], [ '%s' ], [ '%d' ] );
            $updated[] = [ 'id' => (int) $wp_row->id, 'eta_date' => $new_date ];
        }

        return new WP_REST_Response( [ 'updated' => $updated ], 200 );
    }

    // ── Formatters ────────────────────────────────────────────────────────────

    private function format_planned_trip( object $r ): array {
        return [
            'id'         => (int)  $r->id,
            'title'      =>        $r->title,
            'notes'      =>        $r->notes,
            'visible'    => (bool) ( $r->visible ?? 1 ),
            'created_at' =>        $r->created_at,
            'updated_at' =>        $r->updated_at,
        ];
    }

    private function format_waypoint( object $r ): array {
        return [
            'id'         => (int)    $r->id,
            'plan_id'    => (int)    $r->plan_id,
            'sort_order' => (int)    $r->sort_order,
            'harbour_id' => $r->harbour_id !== null ? (int) $r->harbour_id : null,
            'name'       =>          $r->name,
            'lat'        => (float)  $r->lat,
            'lon'        => (float)  $r->lon,
            'eta_date'   =>          $r->eta_date,
        ];
    }

    /** Validate and normalise a YYYY-MM-DD string; returns null if blank/invalid. */
    private function parse_eta( mixed $raw ): ?string {
        if ( $raw === null || trim( (string) $raw ) === '' ) {
            return null;
        }
        $d = \DateTime::createFromFormat( 'Y-m-d', trim( (string) $raw ) );
        return ( $d && $d->format( 'Y-m-d' ) === trim( (string) $raw ) ) ? $d->format( 'Y-m-d' ) : null;
    }
}
