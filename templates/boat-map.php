<?php
/**
 * Live map template – served at /boat-position/map
 * WordPress is fully loaded when this file is included.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$pcio_bp_history_url = home_url( 'boat-position/history' );
$pcio_bp_plan_url    = home_url( 'boat-position/plans' );
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php esc_html_e( 'Boat Tracker', 'boat-position' ); ?></title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php wp_print_styles( 'leaflet' ); ?>
    <?php wp_print_scripts( 'leaflet' ); ?>
    <?php wp_print_styles('pcio-bp-boat-map'); ?>
</head>

<body>

<div id="map"></div>

<div class="pcio-bp-nav">
    <a class="pcio-bp-nav-exit" href="<?php echo esc_url( home_url() ); ?>" title="<?php esc_attr_e( 'Back to website', 'boat-position' ); ?>">&#x2715;</a>
    <a class="pcio-bp-nav-switch" href="<?php echo esc_url( $pcio_bp_history_url ); ?>">&#9776;&nbsp;<?php esc_html_e( 'Logbook', 'boat-position' ); ?></a>
    <a class="pcio-bp-nav-switch" href="<?php echo esc_url( $pcio_bp_plan_url ); ?>">&#x2690;&nbsp;<?php esc_html_e( 'Plans', 'boat-position' ); ?></a>
    <button id="pcio-bp-toggle-harbours" class="pcio-bp-nav-harbour is-on">&#x2693;&nbsp;<?php esc_html_e( 'Harbours', 'boat-position' ); ?></button>
    <button id="pcio-bp-toggle-blog" class="pcio-bp-nav-blog is-on">&#9998;&nbsp;<?php esc_html_e( 'Blog', 'boat-position' ); ?></button>
</div>

<div id="boat-info">
    <div id="info-sailing">
        <div class="label"><?php esc_html_e( 'Speed', 'boat-position' ); ?></div>
        <div class="value" id="info-speed">—</div>
    </div>
    <div id="info-idle" style="display:none">
        <div class="label"><?php esc_html_e( 'State', 'boat-position' ); ?></div>
        <div class="value" id="info-state">—</div>
        <div class="label"><?php esc_html_e( 'Since', 'boat-position' ); ?></div>
        <div class="value" id="info-since">—</div>
        <div class="label"><?php esc_html_e( 'Duration', 'boat-position' ); ?></div>
        <div class="value" id="info-duration">—</div>
    </div>
</div>

<?php wp_print_scripts( 'pcio-bp-boat-map' ); ?>


</body>
</html>
