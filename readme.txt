=== Boat Position ===
Contributors:      pcio
Author:            PCIO
Author URI:        https://www.pcio.dk
Plugin URI:        https://www.pcio.dk/boat-position-plugin/
Donate link:       https://www.paypal.com/donate/?hosted_button_id=DFM2JV8JKTTM6
Tags:              boat, gps, tracking, map, logbook
Requires at least: 6.2
Tested up to:      7.1
Requires PHP:      8.1
Stable tag:        1.7.2
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatic sailing logbook — track your boat's live position and full route history using your onboard GPS router.

== Description ==

Boat Position turns your WordPress site into a live tracking and logbook service for your boat.

The plugin is designed around the Teltonika RUTX50 router (*), a compact Linux-based 5G router with built-in GPS. 
A shell script on the router sends a position to your site every minute. The plugin stores each position, runs a state machine to group positions into trips and legs, and serves three public pages:

* **Live map** (`/boat-position/map`) — shows the current position on an OpenStreetMap/OpenSeaMap map with a rotating arrow icon when underway and an idle indicator when stopped. Visible voyage plans are drawn as dashed green routes with flag markers.
* **Logbook** (`/boat-position/history`) — calendar sidebar with trip history. Click any day to see the full route on the map. Logged-in editors can label harbour names and merge incorrectly split trips.
* **Voyage plans** (`/boat-position/plans`) — plan future voyages as ordered lists of waypoints with optional ETAs. Multiple plans are supported; each plan can be toggled visible/hidden on the map independently.

The plugin also adds a **boat log** built on ordinary WordPress posts: place a post in the *Boat Log* category, give it coordinates (or default to the boat's latest known position), and it appears as a marker on the live map. Your theme handles all writing and display, and the `[boat_log_map_link]` shortcode links a post back to the map and animates to its location.

Over 150 Danish harbours are included as seed data so harbour names are detected automatically from GPS coordinates.

No third-party services or API keys are required beyond your own WordPress site. Maps are rendered using the free Leaflet.js library with OpenStreetMap and OpenSeaMap tiles.

(*) There are other alternatives to using the RTUTX50 router, any device that has access to a GPS and the internet can be configured as the source of position data.
    E.g. a linux machine like Rasberry PI connected to either its own GPS or the boat NMEA data.
 
== Installation ==

1. Upload the `boat-position` directory to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins** in the WordPress admin.
3. Go to **Settings → Boat Position** to set a secret API key and find the router configuration instructions.
4. Follow the router setup guide in **Plugins → Boat Position → About** to configure your router's `sendgps.sh` script and cron job.
5. Send a test position from a command prompt to verify the endpoint is working before installing on the boat.

After activation, if `/boat-position/map` returns a 404, go to **Settings → Permalinks** and click **Save Changes** to flush the rewrite rules.

== Frequently Asked Questions ==

= How do I use this plugin? =

Install and activate the plugin, then go to **Settings → Boat Position**. Set a secret API key, then follow the router configuration guide in the About page to set up your router's cron job.

= Does it only work with the RUTX50 router? =

No. Any device that can send an HTTP POST request with `lat`, `lon`, `speed`, `course`, and `gps_time` fields to the REST endpoint will work. The About page documents the exact format.

= Where are the public pages? =

The plugin registers three pages automatically — no WordPress pages or shortcodes are needed:

* Live map: `https://yoursite.com/boat-position/map`
* Logbook: `https://yoursite.com/boat-position/history`
* Voyage plans: `https://yoursite.com/boat-position/plans`

= Can I avoid storing the secret key in the database? =

Yes. Define the key as a constant in `wp-config.php`:

  define( 'PCIO_BOAT_POSITION_API_KEY', 'your-secret-key-here' );

When this constant is present, the database option is ignored and the settings field is shown as read-only.

= The logbook is empty — no trips appear. =

Check that the router is sending data by looking at your database's `wp_boat_positions` table. If rows are present but no trips appear, the trip engine may not have processed them yet — visit the ingest endpoint directly or check the About page for the manual trigger curl command.

== Upgrade Notice ==

= 1.7 =
Timestamp changes in shell script `sendgps.sh`. Re-download the script from the About page and install it in the router.

= 1.6 =
Trip legs now include the departure and arrival points, so routes are drawn all the way to the dock. The bundled `sendgps.sh` router script also validates the GPS fix more reliably. Re-download the script from the About page if you use it.

= 1.5 =
Fixes a fatal error triggered by third-party plugins (e.g. Event Tickets) that call `admin_enqueue_scripts` without a hook argument. Update immediately if you use any such plugin alongside Boat Position.

= Does this cost anything? =

The plugin is free. If you like my work, you are welcome to support the further development:

[Donate link](https://www.paypal.com/donate/?hosted_button_id=DFM2JV8JKTTM6)

= How do I uninstall? =

Deactivate and delete the plugin from the WordPress admin. To also remove the data, open your database admin tool and drop these tables (replace `wp_` with your actual table prefix):

* `wp_boat_harbours`
* `wp_boat_legs`
* `wp_boat_positions`
* `wp_boat_trips`

== Screenshots ==

1. Live map — current boat position with directional arrow on OpenSeaMap.
2. Logbook — calendar sidebar, trip list, and route drawn on the map.
3. Voyage plans — plan list with visibility toggles, edit and delete controls.
4. Waypoint view — ordered waypoint table with ETA, drag-to-reorder, and inline editing.

== Changelog ==

= 1.7 =
* **Shell script improvement** — No longer relying on routers internal clock for timestamp but instead using the gps utc timestamp.
* **3D replay improved** - Added local time to 3D replay.

= 1.6 =
* **Complete route legs** — trip legs now include the stationary departure and arrival fixes, so each leg is drawn all the way to and from the dock instead of starting and ending once the boat was already moving. Previously these points were dropped because the boat's speed was zero.
* **More reliable GPS fix check** — the downloadable `sendgps.sh` router script now validates the receiver's live `fix_status` and `fix_quality` (and confirms coordinates are present) instead of relying on `fix_curr_mode`, which could report a stale value and send invalid positions.

= 1.5 =
* **3D sailing replay** — the logbook history page now offers a 3D view that animates your trip in a cinematic flyover using MapLibre GL and Three.js. A scrubber, play/pause button and speed selector let you relive any logged voyage in seconds.
* **Bug fix** — fixed a fatal `TypeError` on PHP 8 when a third-party plugin (e.g. Event Tickets) triggers `admin_enqueue_scripts` via `iframe_header()` without passing a hook argument.

= 1.4 =
* **Local time in the logbook** — trip start and end times on the logbook page are now shown in the visitor's local browser time zone instead of UTC. Positions are still stored in UTC in the database and converted on display.
* **Resilient GPS script** — the downloadable `sendgps.sh` router script now queues each position to disk and flushes the queue oldest-first, so positions recorded while the boat is offline are sent once the connection returns instead of being lost.

= 1.3 =
* **Live current track** — the live map now draws the boat's ongoing trip as it happens (solid blue line, with red dashed segments where GPS data was missing).
* **Route editors** — a dedicated *Manage routes* capability replaces the generic editing permission. Grant it to any Author, Editor or Administrator from **Settings → Boat Position → Route editors**. Administrators get it automatically; the capability is cleaned up on uninstall.
* **Boat Log by default** — new posts now default to the *Boat Log* category automatically (works in the block editor, classic editor and Divi), so journal entries land on the map without changing the category by hand.
* **Mobile logbook** — on phones the logbook view stacks into a compact bottom panel: the calendar and legend are hidden, the trip list is condensed, and the selected route's details sit beside it. The desktop layout is unchanged.

= 1.2 =
* **Boat log** — write voyage journal entries as ordinary WordPress posts (so your theme / Divi handles all writing and display).
  * Posts placed in the **Boat Log** category appear as markers on the live map.
  * Location meta box in the post editor with a mini map: set coordinates manually or click **Use current boat position**.
  * Boat-log posts with no location default automatically to the boat's latest known position on save.
  * New `[boat_log_map_link]` shortcode links a post back to the live map and animates to its location.
  * Public REST feed `GET /wp-json/boat-position/v1/blog-posts` exposes the boat-log markers.
  * **Blog** toggle on the live map to show or hide boat-log markers.
  * The *Boat Log* category is created automatically on activation.

= 1.1 =
* **Voyage plans** (`/boat-position/plans`) — new page for planning future voyages.
  * Create and manage multiple named plans, each with an optional description.
  * Add waypoints with names, coordinates and optional ETAs (expected arrival dates).
  * Drag-and-drop to reorder waypoints; inline editing of name and ETA per row.
  * Rebase tool: shift all ETAs from a chosen waypoint onwards by a number of days.
  * Delete individual waypoints or entire plans.
  * Editable plan title and notes directly from the waypoint view.
  * Eye icon toggle per plan: **visible** (blue) or **hidden** (grey).
    Logged-in users save their choice to the database; anonymous visitors store it in browser localStorage.
  * All visible plans are drawn on the live map as dashed green routes with flag markers.
  * Clicking empty water on the map allows editors to add a waypoint directly to the active plan.
  * Quick-pick datalist when adding waypoints: suggests all known harbours and existing plan waypoints.
* Harbour management on the live map: all known harbours are shown as markers. Logged-in editors can click any harbour to rename or delete it, and can click empty water to add a new harbour at that location.

= 1.0 =
* Initial release with tracking and history logbook.


