<?php
/**
 * Logbook & history template – served at /boat-position/history
 * WordPress is fully loaded when this file is included.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// REST nonce for authenticated write calls (X-WP-Nonce header)
$pcio_bp_nonce   = '';
$pcio_bp_can_edit = false;
if ( is_user_logged_in() && current_user_can( PCIO_BP_CAP ) ) {
    $pcio_bp_can_edit = true;
    $pcio_bp_nonce    = wp_create_nonce( 'wp_rest' );
}

$pcio_bp_rest_url = esc_js( rest_url( PCIO_BP_REST_NS ) );
$pcio_bp_map_url  = esc_url( home_url( 'boat-position/map' ) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Boat Logbook &amp; History', 'boat-position' ); ?></title>
    <?php wp_print_styles( 'leaflet' ); ?>
    <?php wp_print_scripts( 'leaflet' ); ?>
    <?php wp_print_styles( 'pcio-bp-history' ); ?>
    <?php wp_print_styles( 'pcio-bp-3d-css' ); ?>
    </head>

<body>

<!-- ── Sidebar ──────────────────────────────────────────────────────────── -->
<div id="sidebar">

    <div id="cal-section">
        <div class="cal-header">
            <button class="cal-nav" id="cal-prev">&#8249;</button>
            <span id="cal-title"></span>
            <button class="cal-nav" id="cal-next">&#8250;</button>
        </div>
        <div class="cal-grid" id="cal-grid"></div>
    </div>

    <div id="logbook">
        <div id="logbook-heading"><?php esc_html_e( 'All Trips', 'boat-position' ); ?></div>
        <div id="trip-list"></div>
        <button id="btn-merge-trip" style="display:none"><?php esc_html_e( '⇔ Merge selected trips', 'boat-position' ); ?></button>
    </div>

</div>

<!-- ── Map ──────────────────────────────────────────────────────────────── -->
<div id="map-wrap">
    <div id="map"></div>
    <!-- 3D map container – created and managed by boat-3d.js -->

    <div class="pcio-bp-nav">
        <a class="pcio-bp-nav-exit" href="<?php echo esc_url( home_url() ); ?>" title="<?php esc_attr_e( 'Back to website', 'boat-position' ); ?>">&#x2715;</a>
        <a class="pcio-bp-nav-switch" href="<?php echo esc_url( $pcio_bp_map_url ); ?>">&#9679;&nbsp;<?php esc_html_e( 'Go to live view', 'boat-position' ); ?></a>
        <button id="btn-3d-toggle" class="pcio-bp-3d-toggle" disabled>&#9651;&nbsp;<?php esc_html_e( '3D View', 'boat-position' ); ?></button>
    </div>

    <div class="map-pill" id="legend">
        <div><span class="leg-line" style="background:#0057b8"></span><?php esc_html_e( 'Actual route', 'boat-position' ); ?></div>
        <div>
            <span class="leg-line" style="background:none;border-top:2.5px dashed #d33;height:0;display:inline-block;width:22px;vertical-align:middle;margin-right:4px"></span>
            <?php esc_html_e( 'Estimated (no data)', 'boat-position' ); ?>
        </div>
    </div>

    <div class="map-pill" id="trip-overlay">
        <div class="lbl"><?php esc_html_e( 'Route', 'boat-position' ); ?></div>
        <div style="display:flex;align-items:center">
            <div class="val" id="ov-route">—</div>
            <button id="btn-edit-harbour" title="<?php esc_attr_e( 'Edit harbour names', 'boat-position' ); ?>">&#9998;</button>
            <button id="btn-delete-trip" title="<?php esc_attr_e( 'Delete trip', 'boat-position' ); ?>" style="display:none">&#128465;</button>
        </div>
        <div class="lbl"><?php esc_html_e( 'Date', 'boat-position' ); ?></div>
        <div class="val"><span id="ov-date">—</span><span id="ov-date-to"></span></div>
        <div class="ov-row">
            <div>
                <div class="lbl"><?php esc_html_e( 'Duration', 'boat-position' ); ?></div>
                <div class="val" id="ov-dur">—</div>
            </div>
            <div>
                <div class="lbl"><?php esc_html_e( 'Distance', 'boat-position' ); ?></div>
                <div class="val" id="ov-dist">—</div>
            </div>
        </div>

        <div id="edit-harbour-form" style="display:none">
            <datalist id="harbour-datalist"></datalist>
            <div class="lbl"><?php esc_html_e( 'Start harbour', 'boat-position' ); ?></div>
            <div class="harbour-field">
                <input type="text" id="edit-start" list="harbour-datalist" placeholder="<?php esc_attr_e( '(unknown)', 'boat-position' ); ?>" autocomplete="off">
                <button class="btn-locate" id="btn-locate-start" title="<?php esc_attr_e( 'Detect from current location', 'boat-position' ); ?>">&#x1F4CD;</button>
            </div>
            <div class="lbl"><?php esc_html_e( 'End harbour', 'boat-position' ); ?></div>
            <div class="harbour-field">
                <input type="text" id="edit-end" list="harbour-datalist" placeholder="<?php esc_attr_e( '(unknown)', 'boat-position' ); ?>" autocomplete="off">
                <button class="btn-locate" id="btn-locate-end" title="<?php esc_attr_e( 'Detect from current location', 'boat-position' ); ?>">&#x1F4CD;</button>
            </div>
            <div class="edit-btns">
                <button id="btn-save-harbour"><?php esc_html_e( 'Save', 'boat-position' ); ?></button>
                <button id="btn-cancel-harbour" type="button"><?php esc_html_e( 'Cancel', 'boat-position' ); ?></button>
            </div>
        </div>
    </div>
</div>

<?php wp_print_scripts( 'pcio-bp-history' ); ?>

</body>
</html>
