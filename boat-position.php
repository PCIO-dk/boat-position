<?php
/*
Plugin Name:  Boat Position
Plugin URI:   https://www.pcio.dk/
Description:  Tracks and displays the boat's GPS position. Receives positions from the boat's router, stores them in the database, and provides a live map and voyage logbook.
Version:      1.7.2
Author:       PCIO
Author URI:   https://www.pcio.dk
Requires at least: 6.2
Requires PHP: 8.1
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  boat-position
Domain Path:  /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PCIO_BP_PLUGIN_FILE', __FILE__ );
define( 'PCIO_BP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PCIO_BP_REST_NS',     'boat-position/v1' );
// Plugin-specific capability that lets a user manage routes, harbours and voyage
// plans (and, being authors already, write Boat Log posts). Granted to the
// administrator role on activation and to individual Author+ users via the
// Settings > Boat Position "Route editors" dialog.
define( 'PCIO_BP_CAP',         'pcio_bp_manage_routes' );

require_once PCIO_BP_PLUGIN_DIR . 'includes/class-pcio-bp-trip-engine.php';
require_once PCIO_BP_PLUGIN_DIR . 'includes/class-pcio-bp-rest-ingest.php';
require_once PCIO_BP_PLUGIN_DIR . 'includes/class-pcio-bp-rest-query.php';
require_once PCIO_BP_PLUGIN_DIR . 'includes/class-pcio-bp-settings.php';
require_once PCIO_BP_PLUGIN_DIR . 'includes/class-pcio-bp-blog.php';
require_once PCIO_BP_PLUGIN_DIR . 'includes/class-pcio-bp-plugin.php';

register_activation_hook(   __FILE__, [ 'PCIO_BP_Plugin', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'PCIO_BP_Plugin', 'deactivate' ] );
register_uninstall_hook(    __FILE__, [ 'PCIO_BP_Plugin', 'uninstall'  ] );

PCIO_BP_Plugin::instance();
