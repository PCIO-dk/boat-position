<?php
/**
 * REST endpoint: ingest a GPS position from the boat's router.
 *
 * POST /wp-json/boat-position/v1/ingest
 *
 * Fields: apikey, lat, lon, speed, course (optional), gps_time (ISO 8601)
 *
 * GET /wp-json/boat-position/v1/process
 *
 * Manually triggers the trip engine (useful for testing or cron calls).
 * Requires the same API key.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- High-frequency GPS ingest endpoint. Positions are written every minute; caching is not applicable to write operations.

class PCIO_BP_Rest_Ingest {

    public function register(): void {
        register_rest_route( PCIO_BP_REST_NS, '/ingest', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );

        register_rest_route( PCIO_BP_REST_NS, '/process', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'process' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );
    }

    // ── Permission callback ───────────────────────────────────────────────────

    public function check_api_key( WP_REST_Request $request ): bool|WP_Error {
        $expected = defined( 'PCIO_BOAT_POSITION_API_KEY' )
            ? PCIO_BOAT_POSITION_API_KEY
            : get_option( 'pcio_boat_position_api_key', '' );

        if ( $expected === '' ) {
            return new WP_Error( 'no_key', __( 'API key not configured on the server.', 'boat-position' ), [ 'status' => 500 ] );
        }

        $provided = trim( (string) ( $request->get_param( 'apikey' ) ?? '' ) );

        if ( ! hash_equals( $expected, $provided ) ) {
            return new WP_Error( 'unauthorized', __( 'Unauthorized.', 'boat-position' ), [ 'status' => 401 ] );
        }

        return true;
    }

    // ── Ingest handler ────────────────────────────────────────────────────────

    public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {

        // Validate and cast inputs
        $lat    = filter_var( $request->get_param( 'lat' ),   FILTER_VALIDATE_FLOAT );
        $lon    = filter_var( $request->get_param( 'lon' ),   FILTER_VALIDATE_FLOAT );
        $speed  = filter_var( $request->get_param( 'speed' ), FILTER_VALIDATE_FLOAT );
        $course = filter_var( $request->get_param( 'course' ) ?? 0.0, FILTER_VALIDATE_FLOAT );

        $gps_time_utc = false;
        $gps_time     = $request->get_param( 'gps_time' );
        if ( ! empty( $gps_time ) ) {
            $dt = DateTimeImmutable::createFromFormat( DateTimeInterface::ATOM, $gps_time )
               ?: DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s\Z', $gps_time )
               ?: DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $gps_time );
            if ( $dt !== false ) {
                $gps_time_utc = $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
            }
        }

        if ( $lat === false || $lon === false || $speed === false || $course === false || $gps_time_utc === false ) {
            return new WP_Error(
                'bad_request',
                __( 'Invalid or missing fields: lat, lon, speed (numeric) and gps_time (ISO 8601) are required.', 'boat-position' ),
                [ 'status' => 400 ]
            );
        }
        if ( $lat < -90 || $lat > 90 ) {
            return new WP_Error( 'bad_request', __( 'lat out of range (-90 to 90).', 'boat-position' ), [ 'status' => 400 ] );
        }
        if ( $lon < -180 || $lon > 180 ) {
            return new WP_Error( 'bad_request', __( 'lon out of range (-180 to 180).', 'boat-position' ), [ 'status' => 400 ] );
        }
        if ( $speed < 0 ) {
            return new WP_Error( 'bad_request', __( 'speed cannot be negative.', 'boat-position' ), [ 'status' => 400 ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'boat_positions';

        $wpdb->insert(
            $table,
            [
                'lat'          => $lat,
                'lon'          => $lon,
                'speed'        => $speed,
                'course'       => $course,
                'gps_time_utc' => $gps_time_utc,
            ],
            [ '%f', '%f', '%f', '%f', '%s' ]
        );

        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', __( 'Failed to store position.', 'boat-position' ), [ 'status' => 500 ] );
        }

        $insert_id = (int) $wpdb->insert_id;

        // Run the trip engine after every successful ingest
        ( new PCIO_BP_Trip_Engine() )->process();

        return new WP_REST_Response( [ 'status' => 'ok', 'id' => $insert_id ], 200 );
    }

    // ── Manual trip-engine trigger ────────────────────────────────────────────

    public function process(): WP_REST_Response {
        $n = ( new PCIO_BP_Trip_Engine() )->process();
        return new WP_REST_Response( [ 'processed' => $n ], 200 );
    }
}
