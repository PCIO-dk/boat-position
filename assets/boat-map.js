/* PCIO_BP_REST, PCIO_BP_CAN_EDIT, PCIO_BP_IS_LOGGED_IN, PCIO_BP_NONCE, PCIO_BP_PLAN_URL are injected by wp_add_inline_script() before this file. */

// ── Local visibility overrides (mirrors plan.js logic) ───────────────────────
const PCIO_BP_VIS_KEY = 'pcio_bp_plan_vis';

function pcio_bp_localVisGet() {
    try { return JSON.parse( localStorage.getItem( PCIO_BP_VIS_KEY ) || '{}' ); } catch { return {}; }
}

function pcio_bp_effectiveVisible( plan ) {
    if ( PCIO_BP_IS_LOGGED_IN ) return plan.visible;
    const store = pcio_bp_localVisGet();
    return ( plan.id in store ) ? store[ plan.id ] : plan.visible;
}


const pcio_bp_map = L.map('map').setView([55.0, 12.0], 7);

L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    { attribution: '&copy; OpenStreetMap contributors' }
).addTo(pcio_bp_map);

L.tileLayer(
    'https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png',
    { attribution: 'OpenSeaMap' }
).addTo(pcio_bp_map);

let marker = null;

function pcio_bp_makeBoatIcon(course, speed) {
    const underway = speed >= 1;
    const svg = underway
        ? `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="-14 -14 28 28"
               style="transform:rotate(${course}deg);overflow:visible">
             <polygon points="0,-12 8,8 0,3 -8,8"
                      fill="#0057b8" stroke="white" stroke-width="1.5" stroke-linejoin="round"/>
           </svg>`
        : `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="-8 -8 16 16">
             <circle r="7" fill="#0057b8" stroke="white" stroke-width="1.5"/>
           </svg>`;
    return L.divIcon({
        html:       svg,
        className:  '',
        iconSize:   [28, 28],
        iconAnchor: [14, 14],
        popupAnchor:[0, -14],
    });
}

async function pcio_bp_updateBoat() {
    try {
        const response = await fetch(PCIO_BP_REST + '/latest?t=' + Date.now());
        const data     = await response.json();

        const lat  = data.lat;
        const lon  = data.lon;
        const icon = pcio_bp_makeBoatIcon(data.course, data.speed);

        if (!marker) {
            marker = L.marker([lat, lon], { icon }).addTo(pcio_bp_map);
            pcio_bp_map.setView([lat, lon], 10);
        } else {
            marker.setLatLng([lat, lon]);
            marker.setIcon(icon);
        }

        document.getElementById('info-speed').textContent =
            data.speed != null ? data.speed.toFixed(1) + ' kn' : '—';
        pcio_bp_updateOverlay(data);

    } catch (err) {
        console.error(err);
    }
}

// ── Overlay state tracking ────────────────────────────────────────────────────
let currentState   = null;
let stateStartTime = null;

const NO_DATA_THRESHOLD_SECS = 300;

function pcio_bp_updateOverlay(data) {
    const dataAgeSecs = (Date.now() / 1000) - data.time;
    const newState    = dataAgeSecs >= NO_DATA_THRESHOLD_SECS ? 'nodata'
                      : data.speed >= 1.0                     ? 'sailing'
                      :                                         'stopped';

    currentState = newState;
    if (newState === 'nodata') {
        // No recent data – count from the last fix we received.
        stateStartTime = new Date(data.time * 1000);
    } else if (newState === 'stopped') {
        // Count from when the boat actually stopped moving (server-computed),
        // falling back to the latest fix if it is unavailable.
        stateStartTime = new Date((data.since || data.time) * 1000);
    } else {
        stateStartTime = null;
    }
    pcio_bp_renderOverlay();
}

function pcio_bp_renderOverlay() {
    if (!currentState) return;
    const sailing = currentState === 'sailing';
    document.getElementById('info-sailing').style.display = sailing ? '' : 'none';
    document.getElementById('info-idle').style.display    = sailing ? 'none' : '';

    if (!sailing && stateStartTime) {
        document.getElementById('info-state').textContent =
            currentState === 'nodata' ? PCIO_BP_L10N.noData : PCIO_BP_L10N.stopped;

        document.getElementById('info-since').textContent =
            stateStartTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        const mins = Math.floor((Date.now() - stateStartTime) / 60000);
        const h    = Math.floor(mins / 60);
        const m    = mins % 60;
        document.getElementById('info-duration').textContent =
            h ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
    }
}

// ── Current (active) trip track ───────────────────────────────────────────────

const pcio_bp_trackLayer = L.layerGroup().addTo(pcio_bp_map);

async function pcio_bp_renderTrack() {
    try {
        const legs = await fetch(PCIO_BP_REST + '/trips/active/points?t=' + Date.now())
            .then(r => r.json());
        pcio_bp_trackLayer.clearLayers();
        if (!Array.isArray(legs) || !legs.length) return;
        legs.forEach(leg => {
            if (!leg.points || leg.points.length < 2) return;
            const lls = leg.points.map(p => [p[0], p[1]]);
            L.polyline(lls, {
                color:     leg.is_estimated ? '#d33' : '#0057b8',
                weight:    leg.is_estimated ? 2 : 3,
                opacity:   0.85,
                dashArray: leg.is_estimated ? '7 6' : null,
            }).addTo(pcio_bp_trackLayer);
        });
    } catch (err) {
        console.error(err);
    }
}

// ── Harbour layer ─────────────────────────────────────────────────────────────

const pcio_bp_harbourLayer = L.layerGroup().addTo(pcio_bp_map);

function pcio_bp_harbourIcon() {
    return L.divIcon({
        html:        '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22">'
                   + '<circle cx="11" cy="11" r="10" fill="#c97a00" stroke="white" stroke-width="1.5"/>'
                   + '<text x="11" y="15.5" text-anchor="middle" font-size="13" fill="white" font-family="sans-serif">\u2693</text>'
                   + '</svg>',
        className:   'pcio-bp-harbour-icon',
        iconSize:    [22, 22],
        iconAnchor:  [11, 11],
        popupAnchor: [0, -13],
    });
}

function pcio_bp_escHtml(s) {
    const txt = document.createElement('textarea');
    txt.innerHTML = s;
    return txt.value;
}

function pcio_bp_escAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function pcio_bp_buildHarbourEditPopup(id, name) {
    return '<div class="pcio-bp-hpopup">'
         + '<label>' + PCIO_BP_L10N.nameLabel + '<br>'
         + '<input id="pcio-bp-hn-' + id + '" type="text" value="' + pcio_bp_escAttr(name) + '" maxlength="128">'
         + '</label>'
         + '<div class="pcio-bp-hpopup-btns">'
         + '<button id="pcio-bp-hs-' + id + '" class="pcio-bp-btn-save">' + PCIO_BP_L10N.save + '</button>'
         + '<button id="pcio-bp-hd-' + id + '" class="pcio-bp-btn-del">' + PCIO_BP_L10N.deleteBtn + '</button>'
         + '</div></div>';
}

function pcio_bp_addHarbourMarker(h) {
    const m = L.marker([parseFloat(h.lat), parseFloat(h.lon)], {
        icon:  pcio_bp_harbourIcon(),
        title: h.name,
    });

    if (PCIO_BP_CAN_EDIT) {
        m.bindPopup(() => pcio_bp_buildHarbourEditPopup(h.id, h.name));
        m.on('popupopen', () => {
            const btnSave = document.getElementById('pcio-bp-hs-' + h.id);
            const btnDel  = document.getElementById('pcio-bp-hd-' + h.id);
            if (btnSave) btnSave.onclick = async () => {
                const input = document.getElementById('pcio-bp-hn-' + h.id);
                const name  = input ? input.value.trim() : '';
                if (!name) return;
                try {
                    const res = await fetch(PCIO_BP_REST + '/harbours/' + h.id, {
                        method:  'PATCH',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PCIO_BP_NONCE },
                        body:    JSON.stringify({ name }),
                    });
                    if (res.ok) {
                        const updated = await res.json();
                        h.name = updated.name;
                        m.options.title = updated.name;
                        m.closePopup();
                    }
                } catch (err) { console.error(err); }
            };
            if (btnDel) btnDel.onclick = async () => {
                if (!confirm(PCIO_BP_L10N.deleteHarbourConfirm.replace('{name}', h.name))) return;
                try {
                    const res = await fetch(PCIO_BP_REST + '/harbours/' + h.id, {
                        method:  'DELETE',
                        headers: { 'X-WP-Nonce': PCIO_BP_NONCE },
                    });
                    if (res.ok) pcio_bp_harbourLayer.removeLayer(m);
                } catch (err) { console.error(err); }
            };
        });
    } else {
        m.bindPopup('<strong class="pcio-bp-hname">' + pcio_bp_escHtml(h.name) + '</strong>');
    }

    m.addTo(pcio_bp_harbourLayer);
}

async function pcio_bp_loadHarbours() {
    try {
        const res  = await fetch(PCIO_BP_REST + '/harbours');
        const list = await res.json();
        pcio_bp_harbourLayer.clearLayers();
        list.forEach(pcio_bp_addHarbourMarker);
    } catch (err) { console.error(err); }
}

// ── Plan layer: flag markers + dashed route polyline ────────────────────────

const pcio_bp_planLayer = L.layerGroup().addTo(pcio_bp_map);
let   pcio_bp_planPolylines = [];   // one polyline per visible plan
let   pcio_bp_activePlanId  = null; // first visible plan (used for map-click "add waypoint")

function pcio_bp_flagIcon(isPast) {
    const colour = isPast ? '#aaa' : '#2a9d2a';
    return L.divIcon({
        html:        `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="28" viewBox="0 0 20 28">`
                   + `<line x1="3" y1="2" x2="3" y2="27" stroke="${colour}" stroke-width="2"/>`
                   + `<polygon points="3,2 18,7 3,12" fill="${colour}"/>`
                   + `</svg>`,
        className:   'pcio-bp-plan-icon',
        iconSize:    [20, 28],
        iconAnchor:  [3, 27],
        popupAnchor: [8, -26],
    });
}

function pcio_bp_fmtEta(eta) {
    if (!eta) return '—';
    return new Date(eta + 'T12:00:00').toLocaleDateString(undefined, {
        day: 'numeric', month: 'short', year: 'numeric',
    });
}

function pcio_bp_addPlanMarker(wp, planId) {
    const today  = new Date().toISOString().slice(0, 10);
    const isPast = wp.eta_date && wp.eta_date < today;
    const lat    = parseFloat(wp.lat);
    const lon    = parseFloat(wp.lon);
    const m = L.marker([lat, lon], {
        icon:  pcio_bp_flagIcon(isPast),
        title: wp.name,
        zIndexOffset: -100,
    });

    const buildPopup = () => {
        const etaLine  = wp.eta_date
            ? `<div class="pcio-bp-plan-eta">${pcio_bp_fmtEta(wp.eta_date)}</div>`
            : '';
        const coords   = `<div class="pcio-bp-popup-coords">${lat.toFixed(5)},&nbsp;${lon.toFixed(5)}</div>`;
        const editBtn  = PCIO_BP_CAN_EDIT
            ? `<div class="pcio-bp-plan-popup-btns">`
            + `<a  class="pcio-bp-ppbtn pcio-bp-ppbtn-edit"   href="${PCIO_BP_PLAN_URL}#plan-${planId}" target="_blank">&#9998;&nbsp;Edit plan</a>`
            + `<button class="pcio-bp-ppbtn pcio-bp-ppbtn-del" id="pcio-bp-wpdel-${wp.id}">&#x2715;&nbsp;Delete</button>`
            + `</div>`
            : '';
        return `<div class="pcio-bp-plan-popup">`
             + `<strong>${pcio_bp_escHtml(wp.name)}</strong>`
             + etaLine + coords + editBtn
             + `</div>`;
    };

    m.bindPopup(buildPopup);

    if (PCIO_BP_CAN_EDIT) {
        m.on('popupopen', () => {
            const btn = document.getElementById('pcio-bp-wpdel-' + wp.id);
            if (!btn) return;
            btn.onclick = async () => {
                if (!confirm('Delete waypoint "' + wp.name + '"?')) return;
                // Use wp._planId (set per-plan in pcio_bp_loadPlan) or fall back to activePlanId
                const planId = wp._planId || pcio_bp_activePlanId;
                try {
                    const res = await fetch(
                        PCIO_BP_REST + '/planned-trips/' + planId + '/waypoints/' + wp.id,
                        { method: 'DELETE', headers: { 'X-WP-Nonce': PCIO_BP_NONCE } }
                    );
                    if (res.ok) {
                        m.closePopup();
                        pcio_bp_loadPlan(); // Redraw all visible plans
                    }
                } catch (err) { console.error(err); }
            };
        });
    }

    m.addTo(pcio_bp_planLayer);
}

async function pcio_bp_loadPlan() {
    // Clear previous state
    pcio_bp_planLayer.clearLayers();
    pcio_bp_planPolylines.forEach(pl => pcio_bp_map.removeLayer(pl));
    pcio_bp_planPolylines = [];
    pcio_bp_activePlanId  = null;

    try {
        const plans = await fetch(PCIO_BP_REST + '/planned-trips').then(r => r.json());
        if (!plans || !plans.length) return;

        const visiblePlans = plans.filter(p => pcio_bp_effectiveVisible(p));
        if (!visiblePlans.length) return;

        // First visible plan is the target for the map-click "add waypoint" action
        pcio_bp_activePlanId = visiblePlans[0].id;

        for (const plan of visiblePlans) {
            const waypoints = await fetch(
                PCIO_BP_REST + '/planned-trips/' + plan.id + '/waypoints'
            ).then(r => r.json());

            if (!waypoints || !waypoints.length) continue;

            // Store the plan id on each waypoint so the delete popup targets the right plan
            waypoints.forEach(wp => {
                wp._planId = plan.id;
                pcio_bp_addPlanMarker(wp, plan.id);
            });

            const latlngs = waypoints.map(wp => [parseFloat(wp.lat), parseFloat(wp.lon)]);
            const polyline = L.polyline(latlngs, {
                color:     '#2a9d2a',
                weight:    2,
                opacity:   0.7,
                dashArray: '6 7',
            }).addTo(pcio_bp_map);
            pcio_bp_planPolylines.push(polyline);
        }

    } catch (err) { console.error(err); }
}

// ── Map click → context popup (editors only) ─────────────────────────────────
// Offers: Add harbour | Add to plan (if a plan is loaded)

if (PCIO_BP_CAN_EDIT) {
    pcio_bp_map.on('click', function (e) {
        const lat = e.latlng.lat;
        const lon = e.latlng.lng;

        const hasPlan = pcio_bp_activePlanId !== null;

        // Build content: always show harbour form; show plan option only when a plan exists
        const planSection = hasPlan
            ? '<hr class="pcio-bp-popup-sep">'
            + '<label class="pcio-bp-popup-section-lbl">Add to voyage plan</label>'
            + '<label>Waypoint name<br>'
            + '<input id="pcio-bp-new-wpname" type="text" maxlength="255" placeholder="Waypoint name">'
            + '</label>'
            + '<label>ETA<br><input id="pcio-bp-new-wpeta" type="date"></label>'
            + '<div class="pcio-bp-hpopup-btns">'
            + '<button id="pcio-bp-new-wpadd" class="pcio-bp-btn-save">Add waypoint</button>'
            + '</div>'
            : '';

        const coordTitle = '<div class="pcio-bp-popup-coords">'
            + lat.toFixed(5) + ',&nbsp;' + lon.toFixed(5)
            + '</div>';

        const blogSection = (typeof PCIO_BP_NEW_POST_URL === 'string' && PCIO_BP_NEW_POST_URL)
            ? '<hr class="pcio-bp-popup-sep">'
            + '<a class="pcio-bp-btn-save pcio-bp-blog-here" target="_blank" rel="noopener" href="'
            + PCIO_BP_NEW_POST_URL
            + (PCIO_BP_NEW_POST_URL.indexOf('?') === -1 ? '?' : '&')
            + 'bp_lat=' + lat.toFixed(6) + '&bp_lon=' + lon.toFixed(6) + '">'
            + pcio_bp_escHtml(PCIO_BP_L10N.writeBlogHere || 'Write blog here') + '</a>'
            : '';

        const content =
            '<div class="pcio-bp-hpopup">'
          + coordTitle
          + '<label class="pcio-bp-popup-section-lbl">Add harbour</label>'
          + '<label>Name<br>'
          + '<input id="pcio-bp-new-hname" type="text" maxlength="128" placeholder="Harbour name">'
          + '</label>'
          + '<div class="pcio-bp-hpopup-btns">'
          + '<button id="pcio-bp-new-hadd" class="pcio-bp-btn-save">Add harbour</button>'
          + '</div>'
          + planSection
          + blogSection
          + '</div>';

        const popup = L.popup({ closeButton: true })
            .setLatLng(e.latlng)
            .setContent(content);

        function onPopupOpen(ev) {
            if (ev.popup !== popup) return;
            pcio_bp_map.off('popupopen', onPopupOpen);

            // --- Add harbour ---
            const hInput = document.getElementById('pcio-bp-new-hname');
            const hBtn   = document.getElementById('pcio-bp-new-hadd');
            if (hInput) hInput.focus();
            if (hBtn) hBtn.onclick = async () => {
                const name = hInput ? hInput.value.trim() : '';
                if (!name) { hInput?.focus(); return; }
                try {
                    const res = await fetch(PCIO_BP_REST + '/harbours', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PCIO_BP_NONCE },
                        body:    JSON.stringify({ name, lat, lon, radius_m: 300 }),
                    });
                    if (res.ok) {
                        const h = await res.json();
                        pcio_bp_map.closePopup();
                        pcio_bp_addHarbourMarker(h);
                    }
                } catch (err) { console.error(err); }
            };

            // --- Add to plan ---
            if (!hasPlan) return;
            const wpNameEl = document.getElementById('pcio-bp-new-wpname');
            const wpEtaEl  = document.getElementById('pcio-bp-new-wpeta');
            const wpBtn    = document.getElementById('pcio-bp-new-wpadd');
            if (wpBtn) wpBtn.onclick = async () => {
                const name = wpNameEl ? wpNameEl.value.trim() : '';
                let   eta  = wpEtaEl  ? wpEtaEl.value || null : null;
                if (!name) { wpNameEl?.focus(); return; }
                try {
                    const existing = await fetch(
                        PCIO_BP_REST + '/planned-trips/' + pcio_bp_activePlanId + '/waypoints'
                    ).then(r => r.json());
                    const sortOrder = existing ? existing.length : 0;
                    if (!eta && existing && existing.length) {
                        const lastEta = [ ...existing ].reverse().find(wp => wp.eta_date);
                        if (lastEta) eta = lastEta.eta_date;
                    }
                    const res = await fetch(
                        PCIO_BP_REST + '/planned-trips/' + pcio_bp_activePlanId + '/waypoints', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PCIO_BP_NONCE },
                        body:    JSON.stringify({ name, lat, lon, eta_date: eta, sort_order: sortOrder }),
                    });
                    if (res.ok) {
                        pcio_bp_map.closePopup();
                        pcio_bp_loadPlan(); // Redraw all visible plans with new waypoint
                    }
                } catch (err) { console.error(err); }
            };
        }

        pcio_bp_map.on('popupopen', onPopupOpen);
        popup.openOn(pcio_bp_map);
    });
}

// ── Blog layer ────────────────────────────────────────────────────────────────

const pcio_bp_blogLayer   = L.layerGroup().addTo(pcio_bp_map);
const pcio_bp_blogMarkers = {};
let   pcio_bp_focusDone   = false;

function pcio_bp_blogIcon() {
    return L.divIcon({
        html:
            '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="32" viewBox="0 0 24 32">'
          + '<path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 20 12 20s12-11 12-20C24 5.4 18.6 0 12 0z" '
          + 'fill="#8e44ad" stroke="white" stroke-width="1.5"/>'
          + '<text x="12" y="17" text-anchor="middle" font-size="13" fill="white" font-family="sans-serif">&#9998;</text>'
          + '</svg>',
        className:  'pcio-bp-blog-icon',
        iconSize:   [24, 32],
        iconAnchor: [12, 32],
        popupAnchor:[0, -30],
    });
}

function pcio_bp_blogPopup(p) {
    const thumb = p.thumb
        ? '<img class="pcio-bp-blog-thumb" src="' + p.thumb + '" alt="">'
        : '';
    return '<div class="pcio-bp-blog-popup">'
        + thumb
        + '<strong>' + pcio_bp_escHtml(p.title) + '</strong>'
        + '<a class="pcio-bp-blog-read" href="' + p.url + '">'
        + pcio_bp_escHtml(PCIO_BP_L10N.readPost || 'Read') + '</a>'
        + '</div>';
}

async function pcio_bp_loadBlog() {
    try {
        const res  = await fetch(PCIO_BP_REST + '/blog-posts');
        const list = await res.json();
        pcio_bp_blogLayer.clearLayers();
        Object.keys(pcio_bp_blogMarkers).forEach(k => delete pcio_bp_blogMarkers[k]);
        (list || []).forEach(p => {
            const m = L.marker([p.lat, p.lon], { icon: pcio_bp_blogIcon() })
                .bindPopup(pcio_bp_blogPopup(p));
            m.addTo(pcio_bp_blogLayer);
            pcio_bp_blogMarkers[p.id] = m;
        });
    } catch (err) {
        console.error(err);
    } finally {
        pcio_bp_focusFromQuery();
    }
}

// Pan/zoom to a location passed in the URL (?lat=&lon=&post=) and pulse it.
function pcio_bp_focusFromQuery() {
    if (pcio_bp_focusDone) return;
    const params = new URLSearchParams(window.location.search);
    const lat    = parseFloat(params.get('lat'));
    const lon    = parseFloat(params.get('lon'));
    const postId = params.get('post');
    if (isNaN(lat) || isNaN(lon)) return;
    pcio_bp_focusDone = true;

    pcio_bp_map.flyTo([lat, lon], 13, { duration: 1.5 });
    pcio_bp_pulse(lat, lon);
    if (postId && pcio_bp_blogMarkers[postId]) {
        setTimeout(() => pcio_bp_blogMarkers[postId].openPopup(), 1600);
    }
}

function pcio_bp_pulse(lat, lon) {
    const icon = L.divIcon({
        html:       '<div class="pcio-bp-pulse"></div>',
        className:  '',
        iconSize:   [30, 30],
        iconAnchor: [15, 15],
    });
    const m = L.marker([lat, lon], { icon, interactive: false, zIndexOffset: 1000 })
        .addTo(pcio_bp_map);
    setTimeout(() => pcio_bp_map.removeLayer(m), 4500);
}

pcio_bp_loadHarbours();
pcio_bp_loadPlan();
pcio_bp_loadBlog();

// ── Harbour layer toggle ──────────────────────────────────────────────────────
const PCIO_BP_HARBOUR_VIS_KEY = 'pcio_bp_harbour_visible';

(function () {
    const stored = localStorage.getItem(PCIO_BP_HARBOUR_VIS_KEY);
    if (stored === 'false') {
        pcio_bp_map.removeLayer(pcio_bp_harbourLayer);
        const btn = document.getElementById('pcio-bp-toggle-harbours');
        if (btn) { btn.classList.replace('is-on', 'is-off'); }
    }
})();

document.getElementById('pcio-bp-toggle-harbours').addEventListener('click', function () {
    if (pcio_bp_map.hasLayer(pcio_bp_harbourLayer)) {
        pcio_bp_map.removeLayer(pcio_bp_harbourLayer);
        this.classList.replace('is-on', 'is-off');
        localStorage.setItem(PCIO_BP_HARBOUR_VIS_KEY, 'false');
    } else {
        pcio_bp_harbourLayer.addTo(pcio_bp_map);
        this.classList.replace('is-off', 'is-on');
        localStorage.setItem(PCIO_BP_HARBOUR_VIS_KEY, 'true');
    }
});

// ── Blog layer toggle ─────────────────────────────────────────────────────────
const PCIO_BP_BLOG_VIS_KEY = 'pcio_bp_blog_visible';

(function () {
    const stored = localStorage.getItem(PCIO_BP_BLOG_VIS_KEY);
    if (stored === 'false') {
        pcio_bp_map.removeLayer(pcio_bp_blogLayer);
        const btn = document.getElementById('pcio-bp-toggle-blog');
        if (btn) { btn.classList.replace('is-on', 'is-off'); }
    }
})();

const pcio_bp_blogToggleBtn = document.getElementById('pcio-bp-toggle-blog');
if (pcio_bp_blogToggleBtn) {
    pcio_bp_blogToggleBtn.addEventListener('click', function () {
        if (pcio_bp_map.hasLayer(pcio_bp_blogLayer)) {
            pcio_bp_map.removeLayer(pcio_bp_blogLayer);
            this.classList.replace('is-on', 'is-off');
            localStorage.setItem(PCIO_BP_BLOG_VIS_KEY, 'false');
        } else {
            pcio_bp_blogLayer.addTo(pcio_bp_map);
            this.classList.replace('is-off', 'is-on');
            localStorage.setItem(PCIO_BP_BLOG_VIS_KEY, 'true');
        }
    });
}

// Escape key closes any open popup
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') pcio_bp_map.closePopup();
});

pcio_bp_updateBoat();
setInterval(pcio_bp_updateBoat,     30000);
setInterval(pcio_bp_renderOverlay,  60000);

pcio_bp_renderTrack();
setInterval(pcio_bp_renderTrack,    30000);
