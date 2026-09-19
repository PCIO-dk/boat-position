<?php
/**
 * Boat Trip Engine
 *
 * Processes raw boat_positions (processed = 0) into pre-computed trips and
 * legs using a simple state machine.
 *
 * States
 * ──────
 *   IDLE     – no active trip; boat is stopped or has never moved
 *   SAILING  – active trip + active leg; boat is underway
 *   NO_DATA  – active trip, but a data gap was detected and bridged with an
 *              estimated (red dashed) leg
 *
 * Key thresholds (override by defining constants before this file is loaded)
 * ──────────────────────────────────────────────────────────────────────────
 *   PCIO_BP_SPEED_UNDERWAY_KN   – knots above which boat is considered moving
 *   PCIO_BP_STOP_CONFIRM_COUNT  – consecutive slow readings needed to close a trip
 *   PCIO_BP_NO_DATA_GAP_SECS    – gap (seconds) that triggers a NO_DATA/estimated leg
 *   PCIO_BP_TRIP_END_GAP_SECS   – gap (seconds) that closes the trip entirely (overnight)
 *   PCIO_BP_BATCH_SIZE          – max positions to process per call
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Trip state machine operates on custom tables with GPS data that changes every minute. Caching would produce incorrect trip/leg boundaries. All queries use $wpdb->prepare() or safe prefix-only interpolation.

// ── Tuning constants ──────────────────────────────────────────────────────────
if ( ! defined( 'PCIO_BP_SPEED_UNDERWAY_KN'  ) ) define( 'PCIO_BP_SPEED_UNDERWAY_KN',    1.5  );
if ( ! defined( 'PCIO_BP_STOP_CONFIRM_COUNT' ) ) define( 'PCIO_BP_STOP_CONFIRM_COUNT',     3  );
if ( ! defined( 'PCIO_BP_NO_DATA_GAP_SECS'   ) ) define( 'PCIO_BP_NO_DATA_GAP_SECS',     180  ); // 3 min
if ( ! defined( 'PCIO_BP_TRIP_END_GAP_SECS'  ) ) define( 'PCIO_BP_TRIP_END_GAP_SECS',  28800  ); // 8 h
if ( ! defined( 'PCIO_BP_BATCH_SIZE'         ) ) define( 'PCIO_BP_BATCH_SIZE',           500  );

// ─────────────────────────────────────────────────────────────────────────────

class PCIO_BP_Trip_Engine {

    private \wpdb  $db;
    private string $t_pos;
    private string $t_trips;
    private string $t_legs;
    private string $t_harbours;
    private string $t_planned_trips;
    private string $t_planned_waypoints;

    // ── Constructor ──────────────────────────────────────────────────────────

    public function __construct() {
        global $wpdb;
        $this->db                   = $wpdb;
        $this->t_pos                = $wpdb->prefix . 'boat_positions';
        $this->t_trips              = $wpdb->prefix . 'boat_trips';
        $this->t_legs               = $wpdb->prefix . 'boat_legs';
        $this->t_harbours           = $wpdb->prefix . 'boat_harbours';
        $this->t_planned_trips      = $wpdb->prefix . 'boat_planned_trips';
        $this->t_planned_waypoints  = $wpdb->prefix . 'boat_planned_waypoints';
        $this->ensure_schema();
    }

    // ── Schema bootstrap (idempotent) ────────────────────────────────────────

    public function ensure_schema(): void {
        $db = $this->db;

        // Table creation order matters for inline FK declarations:
        //   trips → legs (FK → trips) → positions (FK → legs) → harbours

        // Trips: one row per sailing trip
        $db->query(
            "CREATE TABLE IF NOT EXISTS `{$this->t_trips}` (
                `id`            BIGINT       NOT NULL AUTO_INCREMENT,
                `started_at`    DATETIME     NOT NULL,
                `ended_at`      DATETIME     NULL,
                `distance_nm`   DOUBLE       NOT NULL DEFAULT 0,
                `state`         ENUM('active','closed') NOT NULL DEFAULT 'active',
                `harbour_start` VARCHAR(128) NULL,
                `harbour_end`   VARCHAR(128) NULL,

                PRIMARY KEY (`id`),

                INDEX `idx_started` (`started_at`),
                INDEX `idx_state`   (`state`)

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Legs: contiguous segments within a trip
        //   is_estimated = 1  →  red dashed line (data was missing for this stretch)
        //   est_lat/lon 1-2   →  start/end coords for estimated legs (no positions rows)
       $db->query(
            "CREATE TABLE IF NOT EXISTS `{$this->t_legs}` (
                `id`           BIGINT       NOT NULL AUTO_INCREMENT,
                `trip_id`      BIGINT       NOT NULL,
                `started_at`   DATETIME     NOT NULL,
                `ended_at`     DATETIME     NULL,
                `distance_nm`  DOUBLE       NOT NULL DEFAULT 0,
                `is_estimated` TINYINT(1)   NOT NULL DEFAULT 0,
                `point_count`  INT          NOT NULL DEFAULT 0,
                `est_lat1`     DOUBLE       NULL,
                `est_lon1`     DOUBLE       NULL,
                `est_lat2`     DOUBLE       NULL,
                `est_lon2`     DOUBLE       NULL,

                PRIMARY KEY (`id`),

                INDEX `idx_trip_started` (`trip_id`, `started_at`),

                CONSTRAINT `pcio_bp_leg_trip`
                    FOREIGN KEY (`trip_id`)
                    REFERENCES `{$this->t_trips}`(`id`)
                    ON DELETE CASCADE
                    ON UPDATE RESTRICT

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Positions: raw GPS fixes, linked to a leg once processed
        $db->query(
            "CREATE TABLE IF NOT EXISTS `{$this->t_pos}` (
                `id`           BIGINT     NOT NULL AUTO_INCREMENT,
                `lat`          DOUBLE     NOT NULL,
                `lon`          DOUBLE     NOT NULL,
                `speed`        DOUBLE     NOT NULL,
                `course`       DOUBLE     NOT NULL DEFAULT 0,
                `created`      DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `gps_time_utc` DATETIME   NOT NULL,
                `leg_id`       BIGINT     NULL DEFAULT NULL,
                `processed`    TINYINT(1) NOT NULL DEFAULT 0,

                PRIMARY KEY (`id`),

                INDEX `idx_gps_time`  (`gps_time_utc`),
                INDEX `idx_leg_id`    (`leg_id`),
                INDEX `idx_processed` (`processed`),

                CONSTRAINT `pcio_bp_pos_leg`
                    FOREIGN KEY (`leg_id`)
                    REFERENCES `{$this->t_legs}`(`id`)
                    ON DELETE CASCADE
                    ON UPDATE RESTRICT

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        
        // Harbours: named positions; boat within radius_m → location name assigned
        $db->query(
            "CREATE TABLE IF NOT EXISTS `{$this->t_harbours}` (
                `id`       INT          NOT NULL AUTO_INCREMENT,
                `name`     VARCHAR(128) NOT NULL,
                `lat`      DOUBLE       NOT NULL,
                `lon`      DOUBLE       NOT NULL,
                `radius_m` INT          NOT NULL DEFAULT 300,

                PRIMARY KEY (`id`)

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Planned trips: a named future voyage plan
        $db->query(
            "CREATE TABLE IF NOT EXISTS `{$this->t_planned_trips}` (
                `id`         INT          NOT NULL AUTO_INCREMENT,
                `title`      VARCHAR(255) NOT NULL DEFAULT '',
                `notes`      TEXT         NULL,
                `visible`    TINYINT(1)   NOT NULL DEFAULT 1,
                `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`)

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Planned waypoints: ordered stops within a planned trip.
        //   harbour_id  – optional FK to wp_boat_harbours (NULL for custom waypoints)
        //   name        – display name; pre-filled from harbour when harbour_id is set
        //   eta_date    – expected arrival date (DATE, not datetime – day granularity is enough)
        $db->query(
            "CREATE TABLE IF NOT EXISTS `{$this->t_planned_waypoints}` (
                `id`         INT          NOT NULL AUTO_INCREMENT,
                `plan_id`    INT          NOT NULL,
                `sort_order` SMALLINT     NOT NULL DEFAULT 0,
                `harbour_id` INT          NULL DEFAULT NULL,
                `name`       VARCHAR(255) NOT NULL DEFAULT '',
                `lat`        DOUBLE       NOT NULL,
                `lon`        DOUBLE       NOT NULL,
                `eta_date`   DATE         NULL,

                PRIMARY KEY (`id`),

                INDEX `idx_plan_order` (`plan_id`, `sort_order`),

                CONSTRAINT `pcio_bp_wp_plan`
                    FOREIGN KEY (`plan_id`)
                    REFERENCES `{$this->t_planned_trips}`(`id`)
                    ON DELETE CASCADE
                    ON UPDATE RESTRICT,

                CONSTRAINT `pcio_bp_wp_harbour`
                    FOREIGN KEY (`harbour_id`)
                    REFERENCES `{$this->t_harbours}`(`id`)
                    ON DELETE SET NULL
                    ON UPDATE RESTRICT

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->seed_harbours();
    }

    // ── Harbour seed data ────────────────────────────────────────────────────

    /**
     * Populate wp_boat_harbours from the bundled SQL seed file.
     * Skipped entirely when the table already contains rows.
     */
    public function seed_harbours(): void {
        $count = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$this->t_harbours}`" );
        if ( $count > 0 ) {
            return;
        }

        $sql_file = PCIO_BP_PLUGIN_DIR . 'assets/harbour-data.sql';
        if ( ! file_exists( $sql_file ) ) {
            return;
        }

        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        $sql = $wp_filesystem ? (string) $wp_filesystem->get_contents( $sql_file ) : '';


        // Extract the multi-row INSERT VALUES block from the dump
        if ( ! preg_match( '/INSERT INTO `[^`]+` \([^)]+\) VALUES\s*([\s\S]+?);/m', $sql, $m ) ) {
            return;
        }

        // Build an INSERT IGNORE into the correct WP-prefixed table
        $insert = "INSERT IGNORE INTO `{$this->t_harbours}` (`id`, `name`, `lat`, `lon`, `radius_m`) VALUES "
                . rtrim( trim( $m[1] ), ',' ) . ';';

        $this->db->query( $insert ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    // ── Main processing loop ─────────────────────────────────────────────────

    /**
     * Process up to PCIO_BP_BATCH_SIZE unprocessed positions.
     * Returns the number of positions processed.
     */
    public function process(): int {

        $positions = $this->db->get_results(
            "SELECT id, lat, lon, speed, course, gps_time_utc
             FROM `{$this->t_pos}`
             WHERE processed = 0
             ORDER BY gps_time_utc ASC
             LIMIT " . PCIO_BP_BATCH_SIZE
        );

        if ( empty( $positions ) ) {
            return 0;
        }

        // Load current open trip/leg (persisted state)
        $trip = $this->db->get_row(
            "SELECT * FROM `{$this->t_trips}`
             WHERE state = 'active'
             ORDER BY started_at DESC LIMIT 1"
        );

        $leg = null;
        if ( $trip ) {
            $leg = $this->db->get_row( $this->db->prepare(
                "SELECT * FROM `{$this->t_legs}`
                 WHERE trip_id = %d AND ended_at IS NULL AND is_estimated = 0
                 ORDER BY started_at DESC LIMIT 1",
                $trip->id
            ) );
        }

        // Last processed position (for gap detection and distance accumulation)
        $prev = $this->db->get_row(
            "SELECT id, lat, lon, speed, gps_time_utc
             FROM `{$this->t_pos}`
             WHERE processed = 1
             ORDER BY gps_time_utc DESC LIMIT 1"
        );

        $slow_streak = $this->compute_slow_streak();
        $processed   = 0;

        foreach ( $positions as $p ) {

            $p_ts     = strtotime( $p->gps_time_utc . ' UTC' );
            $underway = (float) $p->speed >= PCIO_BP_SPEED_UNDERWAY_KN;

            // Leg id to record on this position when the leg is closed on the same
            // fix (confirmed stop); keeps the stopping point attached to the leg.
            $stop_leg_id = null;

            // ── Gap detection ─────────────────────────────────────────────
            if ( $prev !== null && $trip !== null ) {

                $gap = $p_ts - strtotime( $prev->gps_time_utc . ' UTC' );

                if ( $gap > PCIO_BP_NO_DATA_GAP_SECS ) {

                    if ( $leg ) {
                        $this->close_leg( $leg->id, $prev->gps_time_utc );
                        $leg = null;
                    }

                    if ( $gap >= PCIO_BP_TRIP_END_GAP_SECS ) {
                        // Overnight gap → end the trip
                        $this->close_trip(
                            (int) $trip->id,
                            $prev->gps_time_utc,
                            (float) $prev->lat,
                            (float) $prev->lon
                        );
                        $trip        = null;
                        $slow_streak = 0;
                    } else {
                        // Short gap → estimated leg bridging the gap
                        $est_dist = $this->haversine(
                            (float) $prev->lat, (float) $prev->lon,
                            (float) $p->lat,    (float) $p->lon
                        );
                        $est_id = $this->open_estimated_leg(
                            (int) $trip->id,
                            $prev->gps_time_utc,
                            (float) $prev->lat, (float) $prev->lon,
                            (float) $p->lat,    (float) $p->lon
                        );
                        $this->close_leg_full( $est_id, $p->gps_time_utc, $est_dist, 2 );
                        $this->add_trip_distance( (int) $trip->id, $est_dist );
                    }
                }
            }

            // ── State machine ─────────────────────────────────────────────

            if ( $trip === null ) {

                // IDLE: boat started moving → open trip
                if ( $underway ) {
                    $harbour_start = $prev
                        ? $this->find_harbour( (float) $prev->lat, (float) $prev->lon )
                        : null;

                    // Include the last stationary fix (the dock/departure point) so the
                    // leg track begins where the boat actually was, not one fix later.
                    // Only when it is recent enough to belong to this departure.
                    $include_prev = $prev !== null && isset( $prev->id )
                        && ( $p_ts - strtotime( $prev->gps_time_utc . ' UTC' ) ) <= PCIO_BP_NO_DATA_GAP_SECS;
                    $start_at = $include_prev ? $prev->gps_time_utc : $p->gps_time_utc;

                    $trip   = $this->open_trip( $start_at, $harbour_start );
                    $leg_id = $this->open_leg( (int) $trip->id, $start_at );
                    $leg    = (object) [ 'id' => $leg_id ];

                    if ( $include_prev ) {
                        $this->db->query( $this->db->prepare(
                            "UPDATE `{$this->t_pos}` SET leg_id = %d WHERE id = %d",
                            $leg_id, (int) $prev->id
                        ) );
                        $start_dist = $this->haversine(
                            (float) $prev->lat, (float) $prev->lon,
                            (float) $p->lat,    (float) $p->lon
                        );
                        $this->db->query( $this->db->prepare(
                            "UPDATE `{$this->t_legs}`
                             SET distance_nm = distance_nm + %f,
                                 point_count = point_count + 1
                             WHERE id = %d",
                            $start_dist, $leg_id
                        ) );
                        $this->add_trip_distance( (int) $trip->id, $start_dist );
                    }

                    $slow_streak = 0;
                }

            } else {

                // SAILING / NO_DATA
                if ( $underway ) {
                    $slow_streak = 0;
                    if ( ! $leg ) {
                        // Resume after a short data gap
                        $leg_id = $this->open_leg( (int) $trip->id, $p->gps_time_utc );
                        $leg    = (object) [ 'id' => $leg_id ];
                    }
                } else {
                    $slow_streak++;
                    if ( $slow_streak >= PCIO_BP_STOP_CONFIRM_COUNT ) {
                        // Confirmed stop → close leg and trip. Attach this final fix
                        // to the leg so the track reaches the actual stopping point.
                        if ( $leg ) {
                            if ( $prev !== null ) {
                                $stop_dist = $this->haversine(
                                    (float) $prev->lat, (float) $prev->lon,
                                    (float) $p->lat,    (float) $p->lon
                                );
                                if ( $stop_dist > 0 ) {
                                    $this->db->query( $this->db->prepare(
                                        "UPDATE `{$this->t_legs}`
                                         SET distance_nm = distance_nm + %f
                                         WHERE id = %d",
                                        $stop_dist, $leg->id
                                    ) );
                                    $this->add_trip_distance( (int) $trip->id, $stop_dist );
                                }
                            }
                            $stop_leg_id = (int) $leg->id;
                            $this->close_leg( $leg->id, $p->gps_time_utc );
                            $leg = null;
                        }
                        $this->close_trip(
                            (int) $trip->id,
                            $p->gps_time_utc,
                            (float) $p->lat,
                            (float) $p->lon
                        );
                        $trip        = null;
                        $slow_streak = 0;
                    }
                }

                // Accumulate distance on active leg
                if ( $leg && $prev !== null ) {
                    $dist = $this->haversine(
                        (float) $prev->lat, (float) $prev->lon,
                        (float) $p->lat,    (float) $p->lon
                    );
                    if ( $dist > 0 ) {
                        $this->db->query( $this->db->prepare(
                            "UPDATE `{$this->t_legs}`
                             SET distance_nm = distance_nm + %f,
                                 point_count = point_count + 1
                             WHERE id = %d",
                            $dist, $leg->id
                        ) );
                        $this->add_trip_distance( (int) $trip->id, $dist );
                    }
                }
            }

            // Mark position as processed
            // processed = 1, leg_id = NULL  → boat was stopped (no active leg)
            // processed = 1, leg_id = N     → belongs to leg N
            $mark_leg_id = $leg !== null ? (int) $leg->id : $stop_leg_id;
            if ( $mark_leg_id !== null ) {
                $this->db->query( $this->db->prepare(
                    "UPDATE `{$this->t_pos}` SET processed = 1, leg_id = %d WHERE id = %d",
                    $mark_leg_id, $p->id
                ) );
            } else {
                $this->db->query( $this->db->prepare(
                    "UPDATE `{$this->t_pos}` SET processed = 1, leg_id = NULL WHERE id = %d",
                    $p->id
                ) );
            }

            $prev = $p;
            $processed++;
        }

        return $processed;
    }

    // ── DB helpers ───────────────────────────────────────────────────────────

    private function open_trip( string $started_at, ?string $harbour ): object {
        $this->db->insert(
            $this->t_trips,
            [ 'started_at' => $started_at, 'state' => 'active', 'harbour_start' => $harbour ],
            [ '%s', '%s', '%s' ]
        );
        return (object) [ 'id' => (int) $this->db->insert_id, 'distance_nm' => 0.0 ];
    }

    private function close_trip( int $trip_id, string $ended_at, float $lat, float $lon ): void {
        $harbour = $this->find_harbour( $lat, $lon );
        $this->db->query( $this->db->prepare(
            "UPDATE `{$this->t_trips}` SET state = 'closed', ended_at = %s, harbour_end = %s WHERE id = %d",
            $ended_at, $harbour, $trip_id
        ) );
    }

    private function open_leg( int $trip_id, string $started_at ): int {
        $this->db->insert(
            $this->t_legs,
            [ 'trip_id' => $trip_id, 'started_at' => $started_at, 'is_estimated' => 0 ],
            [ '%d', '%s', '%d' ]
        );
        return (int) $this->db->insert_id;
    }

    private function open_estimated_leg(
        int    $trip_id,
        string $started_at,
        float  $lat1, float $lon1,
        float  $lat2, float $lon2
    ): int {
        $this->db->insert(
            $this->t_legs,
            [
                'trip_id'      => $trip_id,
                'started_at'   => $started_at,
                'is_estimated' => 1,
                'est_lat1'     => $lat1,
                'est_lon1'     => $lon1,
                'est_lat2'     => $lat2,
                'est_lon2'     => $lon2,
            ],
            [ '%d', '%s', '%d', '%f', '%f', '%f', '%f' ]
        );
        return (int) $this->db->insert_id;
    }

    private function close_leg( int $leg_id, string $ended_at ): void {
        $this->db->query( $this->db->prepare(
            "UPDATE `{$this->t_legs}` SET ended_at = %s WHERE id = %d",
            $ended_at, $leg_id
        ) );
    }

    private function close_leg_full( int $leg_id, string $ended_at, float $dist, int $points ): void {
        $this->db->query( $this->db->prepare(
            "UPDATE `{$this->t_legs}` SET ended_at = %s, distance_nm = %f, point_count = %d WHERE id = %d",
            $ended_at, $dist, $points, $leg_id
        ) );
    }

    private function add_trip_distance( int $trip_id, float $dist ): void {
        $this->db->query( $this->db->prepare(
            "UPDATE `{$this->t_trips}` SET distance_nm = distance_nm + %f WHERE id = %d",
            $dist, $trip_id
        ) );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function haversine( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
        $R    = 3440.065; // Earth radius in nautical miles
        $dLat = deg2rad( $lat2 - $lat1 );
        $dLon = deg2rad( $lon2 - $lon1 );
        $a    = sin( $dLat / 2 ) ** 2
              + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dLon / 2 ) ** 2;
        return $R * 2.0 * atan2( sqrt( $a ), sqrt( max( 0.0, 1.0 - $a ) ) );
    }

    private function find_harbour( float $lat, float $lon ): ?string {
        $harbours = $this->db->get_results(
            "SELECT name, lat, lon, radius_m FROM `{$this->t_harbours}`"
        );
        foreach ( $harbours ?? [] as $h ) {
            $dist_m = $this->haversine( $lat, $lon, (float) $h->lat, (float) $h->lon ) * 1852.0;
            if ( $dist_m <= (float) $h->radius_m ) {
                return $h->name;
            }
        }
        return null;
    }

    private function compute_slow_streak(): int {
        $recent = $this->db->get_results(
            "SELECT speed FROM `{$this->t_pos}`
             WHERE processed = 1
             ORDER BY gps_time_utc DESC
             LIMIT " . PCIO_BP_STOP_CONFIRM_COUNT
        );
        if ( empty( $recent ) ) {
            return 0;
        }
        $streak = 0;
        foreach ( $recent as $r ) {
            if ( (float) $r->speed < PCIO_BP_SPEED_UNDERWAY_KN ) {
                $streak++;
            } else {
                break;
            }
        }
        return $streak;
    }
}
