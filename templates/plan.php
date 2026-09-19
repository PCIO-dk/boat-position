<?php
/**
 * Voyage plan template – served at /boat-position/plans
 * WordPress is fully loaded when this file is included.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_bp_map_url     = esc_url( home_url( 'boat-position/map' ) );
$pcio_bp_history_url = esc_url( home_url( 'boat-position/history' ) );
$pcio_bp_can_edit    = is_user_logged_in() && current_user_can( PCIO_BP_CAP );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Voyage Plans', 'boat-position' ); ?></title>
    <?php wp_print_styles( 'pcio-bp-plan' ); ?>
</head>
<body>

<div class="pcio-bp-nav">
    <a class="pcio-bp-nav-exit" href="<?php echo esc_url( home_url() ); ?>" title="<?php esc_attr_e( 'Back to website', 'boat-position' ); ?>">&#x2715;</a>
    <a class="pcio-bp-nav-switch" href="<?php echo esc_url($pcio_bp_map_url); ?>">&#9679;&nbsp;<?php esc_html_e( 'Live map', 'boat-position' ); ?></a>
    <a class="pcio-bp-nav-switch" href="<?php echo esc_url($pcio_bp_history_url); ?>">&#9776;&nbsp;<?php esc_html_e( 'Logbook', 'boat-position' ); ?></a>
</div>

<div id="plan-wrap">

    <!-- ═══════════════════════════════════════════════════════════════
         PLAN LIST VIEW
    ═══════════════════════════════════════════════════════════════════ -->
    <div id="plan-list-view">

        <!-- List header -->
        <div id="plan-list-header">
            <h2 class="view-title"><?php esc_html_e( 'Voyage Plans', 'boat-position' ); ?></h2>
            <?php if ( $pcio_bp_can_edit ) : ?>
            <button id="btn-show-new-plan" class="btn-text"><?php esc_html_e( '+ New plan', 'boat-position' ); ?></button>
            <?php endif; ?>
        </div>

        <!-- New plan form -->
        <div id="new-plan-form" class="plan-form-box" style="display:none">
            <h3><?php esc_html_e( 'New voyage plan', 'boat-position' ); ?></h3>
            <label class="form-label"><?php esc_html_e( 'Title', 'boat-position' ); ?>
                <input type="text" id="new-plan-title" maxlength="255" placeholder="<?php esc_attr_e( 'e.g. Summer 2026 – Denmark to Norway', 'boat-position' ); ?>">
            </label>
            <label class="form-label"><?php esc_html_e( 'Notes', 'boat-position' ); ?>
                <textarea id="new-plan-notes" rows="2" placeholder="<?php esc_attr_e( 'Optional notes', 'boat-position' ); ?>"></textarea>
            </label>
            <div class="form-btns">
                <button id="btn-create-plan" class="pcio-bp-btn-primary"><?php esc_html_e( 'Create', 'boat-position' ); ?></button>
                <button id="btn-cancel-new-plan" class="pcio-bp-btn-secondary"><?php esc_html_e( 'Cancel', 'boat-position' ); ?></button>
            </div>
        </div>

        <!-- Plan rows (rendered by JS) -->
        <div id="plan-list-items"></div>

        <!-- Empty state -->
        <div id="plan-empty" style="display:none">
            <p class="plan-empty-msg"><?php esc_html_e( 'No voyage plans yet.', 'boat-position' ); ?></p>
        </div>

    </div><!-- #plan-list-view -->

    <!-- ═══════════════════════════════════════════════════════════════
         WAYPOINT VIEW  (opened when user clicks Edit on a plan)
    ═══════════════════════════════════════════════════════════════════ -->
    <div id="plan-wp-view" style="display:none">

        <!-- Header: back + editable plan title -->
        <div id="wp-view-header" class="plan-form-box">
            <div id="wp-view-header-inner">

                <button id="btn-back-to-plans" class="btn-back">&#8592; <?php esc_html_e( 'Plans', 'boat-position' ); ?></button>

                <div id="plan-title-area">
                    <!-- Display mode -->
                    <div id="plan-title-display">
                        <span id="plan-title-text" class="plan-title-text"></span>
                        <?php if ( $pcio_bp_can_edit ) : ?>
                        <button id="btn-edit-plan-title" class="btn-icon btn-edit-title" title="Edit plan title">&#9998;</button>
                        <?php endif; ?>
                    </div>
                    <!-- Edit mode -->
                    <div id="plan-title-edit" style="display:none">
                        <input type="text" id="plan-title-input" maxlength="255">
                        <label class="form-label" style="margin-top:10px"><?php esc_html_e( 'Notes', 'boat-position' ); ?>
                            <textarea id="plan-notes-input" rows="2"></textarea>
                        </label>
                        <div class="form-btns" style="margin-top:8px">
                            <button id="btn-save-plan-title" class="pcio-bp-btn-primary btn-sm"><?php esc_html_e( 'Save', 'boat-position' ); ?></button>
                            <button id="btn-cancel-title-edit" class="pcio-bp-btn-secondary btn-sm"><?php esc_html_e( 'Cancel', 'boat-position' ); ?></button>
                        </div>
                    </div>
                </div>

            </div>
            <p id="plan-notes" class="plan-notes-text"></p>
        </div><!-- #wp-view-header -->

        <!-- Waypoints table -->
        <div id="wp-section">
            <table id="wp-table">
                <thead>
                    <tr>
                        <th class="col-order">#</th>
                        <th class="col-name"><?php esc_html_e( 'Waypoint', 'boat-position' ); ?></th>
                        <th class="col-eta"><?php esc_html_e( 'ETA', 'boat-position' ); ?></th>
                        <th class="col-days"><?php esc_html_e( 'Days', 'boat-position' ); ?></th>
                        <th class="col-dist"><?php esc_html_e( 'Distance', 'boat-position' ); ?></th>
                        <th class="col-acts" style="display:none"></th>
                    </tr>
                </thead>
                <tbody id="wp-tbody"></tbody>
            </table>
            <p id="wp-total-dist" class="wp-total-dist"></p>
            <p id="wp-empty-msg" class="plan-empty-msg" style="display:none"><?php esc_html_e( 'No waypoints in this plan yet.', 'boat-position' ); ?></p>

            <!-- Add waypoint (editors only) -->
            <div id="add-wp-area" style="display:none">
                <button id="btn-show-add-wp" class="pcio-bp-btn-secondary"><?php esc_html_e( '+ Add waypoint', 'boat-position' ); ?></button>
                <div id="add-wp-form" style="display:none">
                    <div class="add-wp-search-row">
                        <label class="form-label"><?php esc_html_e( 'Pick existing harbour or waypoint', 'boat-position' ); ?>
                            <input type="text" id="add-wp-search" list="add-wp-location-list"
                                   placeholder="<?php esc_attr_e( 'Type to search…', 'boat-position' ); ?>" autocomplete="off">
                            <datalist id="add-wp-location-list"></datalist>
                        </label>
                    </div>
                    <div class="add-wp-grid">
                        <label class="form-label"><?php esc_html_e( 'Name', 'boat-position' ); ?>
                            <input type="text" id="add-wp-name" maxlength="255" placeholder="<?php esc_attr_e( 'Harbour or waypoint name', 'boat-position' ); ?>">
                        </label>
                        <label class="form-label"><?php esc_html_e( 'ETA', 'boat-position' ); ?>
                            <input type="date" id="add-wp-eta">
                        </label>
                        <label class="form-label"><?php esc_html_e( 'Latitude', 'boat-position' ); ?>
                            <input type="number" id="add-wp-lat" step="0.000001" placeholder="55.0000">
                        </label>
                        <label class="form-label"><?php esc_html_e( 'Longitude', 'boat-position' ); ?>
                            <input type="number" id="add-wp-lon" step="0.000001" placeholder="12.0000">
                        </label>
                    </div>
                    <div class="form-btns">
                        <button id="btn-add-wp" class="pcio-bp-btn-primary"><?php esc_html_e( 'Add', 'boat-position' ); ?></button>
                        <button id="btn-cancel-add-wp" class="pcio-bp-btn-secondary"><?php esc_html_e( 'Cancel', 'boat-position' ); ?></button>
                    </div>
                </div>
            </div>
        </div><!-- #wp-section -->

    </div><!-- #plan-wp-view -->

</div><!-- #plan-wrap -->

<?php wp_print_scripts( 'pcio-bp-plan' ); ?>
</body>
</html>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voyage Plan</title>
    <?php wp_print_styles( 'pcio-bp-plan' ); ?>
</head>
<body>

<div class="pcio-bp-nav">
    <a class="pcio-bp-nav-exit" href="<?php echo esc_url( home_url() ); ?>" title="Back to website">&#x2715;</a>
    <a class="pcio-bp-nav-switch" href="<?php echo esc_url( $pcio_bp_map_url ); ?>">&#9679;&nbsp;<?php esc_html_e( 'Live map', 'boat-position' ); ?></a>
    <a class="pcio-bp-nav-switch" href="<?php echo esc_url( $pcio_bp_history_url ); ?>">&#9776;&nbsp;<?php esc_html_e( 'Logbook', 'boat-position' ); ?></a>
</div>

<div id="plan-wrap">

    <!-- ── Plan picker bar ──────────────────────────────────────────── -->
    <div id="plan-bar" style="display:none">
        <div id="plan-bar-inner">
            <span class="bar-label">Plan</span>
            <select id="plan-select"></select>
            <button id="btn-edit-plan-meta" class="btn-icon" style="display:none" title="Edit plan details">&#9998;</button>
            <button id="btn-delete-plan"    class="btn-icon btn-danger" style="display:none" title="Delete plan">&#x2715;</button>
            <button id="btn-show-new-plan"  class="btn-text" style="display:none">+ New plan</button>
        </div>
        <p id="plan-notes" class="plan-notes-text"></p>
    </div>

    <!-- ── Empty state ──────────────────────────────────────────────── -->
    <div id="plan-empty" style="display:none">
        <p class="plan-empty-msg">No voyage plans found.</p>
        <button id="btn-create-first" class="pcio-bp-btn-primary" style="display:none">+ Create first plan</button>
    </div>

    <!-- ── New plan form ────────────────────────────────────────────── -->
    <div id="new-plan-form" class="plan-form-box" style="display:none">
        <h3>New voyage plan</h3>
        <label class="form-label">Title
            <input type="text" id="new-plan-title" maxlength="255" placeholder="e.g. Summer 2026 – Denmark to Norway">
        </label>
        <label class="form-label">Notes
            <textarea id="new-plan-notes" rows="2" placeholder="Optional notes"></textarea>
        </label>
        <div class="form-btns">
            <button id="btn-create-plan" class="pcio-bp-btn-primary">Create</button>
            <button id="btn-cancel-new-plan" class="pcio-bp-btn-secondary">Cancel</button>
        </div>
    </div>

    <!-- ── Edit plan meta form ──────────────────────────────────────── -->
    <div id="edit-plan-form" class="plan-form-box" style="display:none">
        <h3>Edit plan</h3>
        <label class="form-label">Title
            <input type="text" id="edit-plan-title" maxlength="255">
        </label>
        <label class="form-label">Notes
            <textarea id="edit-plan-notes" rows="2"></textarea>
        </label>
        <div class="form-btns">
            <button id="btn-save-plan-meta" class="pcio-bp-btn-primary">Save</button>
            <button id="btn-cancel-edit-plan" class="pcio-bp-btn-secondary">Cancel</button>
        </div>
    </div>

    <!-- ── Waypoints table ──────────────────────────────────────────── -->
    <div id="wp-section" style="display:none">
        <table id="wp-table">
            <thead>
                <tr>
                    <th class="col-order">#</th>
                    <th class="col-name">Waypoint</th>
                    <th class="col-eta">ETA</th>
                    <th class="col-days">Days</th>
                    <th class="col-acts" style="display:none"></th>
                </tr>
            </thead>
            <tbody id="wp-tbody"></tbody>
        </table>
        <p id="wp-empty-msg" class="plan-empty-msg" style="display:none">No waypoints in this plan yet.</p>

        <!-- Add waypoint (editors only) -->
        <div id="add-wp-area" style="display:none">
            <button id="btn-show-add-wp" class="pcio-bp-btn-secondary">+ Add waypoint</button>
            <div id="add-wp-form" style="display:none">
                <!-- Quick-pick from existing harbours / plan waypoints -->
                <div class="add-wp-search-row">
                    <label class="form-label">Pick existing harbour or waypoint
                        <input type="text" id="add-wp-search" list="add-wp-location-list"
                               placeholder="Type to search…" autocomplete="off">
                        <datalist id="add-wp-location-list"></datalist>
                    </label>
                </div>
                <div class="add-wp-grid">
                    <label class="form-label">Name
                        <input type="text" id="add-wp-name" maxlength="255" placeholder="Harbour or waypoint name">
                    </label>
                    <label class="form-label">ETA
                        <input type="date" id="add-wp-eta">
                    </label>
                    <label class="form-label">Latitude
                        <input type="number" id="add-wp-lat" step="0.000001" placeholder="55.0000">
                    </label>
                    <label class="form-label">Longitude
                        <input type="number" id="add-wp-lon" step="0.000001" placeholder="12.0000">
                    </label>
                </div>
                <div class="form-btns">
                    <button id="btn-add-wp" class="pcio-bp-btn-primary">Add</button>
                    <button id="btn-cancel-add-wp" class="pcio-bp-btn-secondary">Cancel</button>
                </div>
            </div>
        </div>
    </div>

</div><!-- #plan-wrap -->

<?php wp_print_scripts( 'pcio-bp-plan' ); ?>
</body>
</html>
