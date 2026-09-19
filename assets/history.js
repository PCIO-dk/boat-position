/* PCIO_BP_API, PCIO_BP_NONCE, PCIO_BP_CAN_EDIT, PCIO_BP_MAP_URL constants are injected by
   wp_add_inline_script() in class-pcio-bp-plugin.php before this file. */
'use strict';

console.log('[history] script start');

// ── Leaflet map ──────────────────────────────────────────────────────────────
const pcio_bp_map = L.map('map').setView([55.5, 11.5], 8);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(pcio_bp_map);

L.tileLayer('https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png', {
    attribution: 'OpenSeaMap'
}).addTo(pcio_bp_map);

// ── App state ────────────────────────────────────────────────────────────────
let allTrips        = [];
let activeDates     = new Set();
let routeLayers     = [];

let selectedTripStartLL = null;
let selectedTripEndLL   = null;
let visibleTrips    = [];
let selectedTrips   = new Set();
let calYear, calMonth;

// Last successfully loaded legs/meta, forwarded to 3D view on demand
let _lastLegs = null;
let _lastMeta = null;
let _3dActive = false;

// ── Calendar ─────────────────────────────────────────────────────────────────
const MONTH_NAMES = Array.from( { length: 12 }, ( _, i ) =>
    new Intl.DateTimeFormat( PCIO_BP_L10N.locale, { month: 'long' } ).format( new Date( 2000, i, 1 ) )
);
const DAY_NAMES = Array.from( { length: 7 }, ( _, i ) =>
    new Intl.DateTimeFormat( PCIO_BP_L10N.locale, { weekday: 'short' } ).format( new Date( 2000, 0, 3 + i ) )
);

function renderCalendar() {
    document.getElementById('cal-title').textContent =
        `${MONTH_NAMES[calMonth]} ${calYear}`;

    const grid = document.getElementById('cal-grid');
    grid.innerHTML = DAY_NAMES.map(d => `<div class="cal-dn">${d}</div>`).join('');

    const firstDow  = new Date(calYear, calMonth, 1).getDay();
    const daysInMon = new Date(calYear, calMonth + 1, 0).getDate();
    const offset    = (firstDow + 6) % 7;

    const today = new Date();

    for (let i = 0; i < offset; i++) {
        grid.insertAdjacentHTML('beforeend', '<div class="cal-day other"></div>');
    }

    for (let d = 1; d <= daysInMon; d++) {
        const dateStr = `${calYear}-${pad(calMonth + 1)}-${pad(d)}`;
        const sail    = activeDates.has(dateStr);
        const isTod   = today.getFullYear() === calYear
                     && today.getMonth()    === calMonth
                     && today.getDate()     === d;

        let cls = 'cal-day';
        if (sail)  cls += ' active';
        if (isTod) cls += ' today';

        grid.insertAdjacentHTML('beforeend',
            `<div class="${cls}"${sail ? ` data-date="${dateStr}"` : ''}>${d}</div>`
        );
    }

    grid.querySelectorAll('.cal-day.active').forEach(el =>
        el.addEventListener('click', () => filterByDate(el.dataset.date, el))
    );
}

function filterByDate(dateStr, el) {
    document.querySelectorAll('.cal-day.selected').forEach(d => d.classList.remove('selected'));
    el.classList.add('selected');

    const day = allTrips.filter(t => t.started_at.startsWith(dateStr));
    document.getElementById('logbook-heading').textContent =
        day.length ? PCIO_BP_L10N.tripsOn.replace('{date}', fmtDate(dateStr))
                   : PCIO_BP_L10N.noTripsOn.replace('{date}', fmtDate(dateStr));
    renderTripList(day);
    if (day.length) { selectedTrips = new Set([day[0].id]); showSelected(); }
    visibleTrips = day;
}

document.getElementById('cal-prev').addEventListener('click', () => {
    if (--calMonth < 0) { calMonth = 11; calYear--; }
    renderCalendar();
});
document.getElementById('cal-next').addEventListener('click', () => {
    if (++calMonth > 11) { calMonth = 0; calYear++; }
    renderCalendar();
});

// ── Trip list ────────────────────────────────────────────────────────────────
function renderTripList(trips) {
    const el = document.getElementById('trip-list');

    if (!trips.length) {
        el.innerHTML = `<div class="empty-msg">${PCIO_BP_L10N.noTripsFound}</div>`;
        return;
    }

    el.innerHTML = trips.map(t => {
        const route = routeLabel(t);
        const dist  = t.distance_nm ? `${t.distance_nm} nm` : '—';
        const dur   = t.duration_min != null ? fmtDur(t.duration_min) : '—';
        const cls   = 'trip-card' + (selectedTrips.has(t.id) ? ' selected' : '');
        return `
        <div class="${cls}" data-id="${t.id}">
            <div class="tc-route">${route}</div>
            <div class="tc-dist">${dist}</div>
            <div class="tc-date">${fmtDate(t.started_at)}</div>
            <div class="tc-dur">${dur}</div>
        </div>`;
    }).join('');

    el.querySelectorAll('.trip-card').forEach(card => {
        card.addEventListener('click', e => {
            const id = parseInt(card.dataset.id);
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                selectedTrips.has(id) ? selectedTrips.delete(id) : selectedTrips.add(id);
            } else {
                selectedTrips = new Set([id]);
            }
            renderTripList(trips);
            showSelected();
        });
    });
}

// ── Draw selected trip(s) on map ─────────────────────────────────────────────
async function showSelected() {
    updateMergeBar();

    routeLayers.forEach(l => pcio_bp_map.removeLayer(l));
    routeLayers = [];
    selectedTripStartLL = null;
    selectedTripEndLL   = null;

    const ov     = document.getElementById('trip-overlay');
    const single = selectedTrips.size === 1;

    if (selectedTrips.size === 0) { ov.style.display = 'none'; return; }

    const palette = ['#0057b8', '#e07b00', '#2a9d2a', '#9d2a2a', '#7b2a9d', '#2a8c9d'];
    let   ci      = 0;
    const allLL   = [];
    const legsFor3d = [];   // accumulated across all selected trips
    let   firstTripMeta = null;
    let   lastTripMeta  = null;

    for (const tripId of selectedTrips) {
        const res = await fetch(`${PCIO_BP_API}/trips/${tripId}/points`);
        if (!res.ok) {
            continue;
        }
        const legs = await res.json();
        if (!Array.isArray(legs) || !legs.length) continue;

        const colour  = single ? '#0057b8' : palette[ci++ % palette.length];
        const firstPt = legs[0]?.points?.[0];
        const lastLeg = legs[legs.length - 1];
        const lastPt  = lastLeg?.points?.[lastLeg.points.length - 1];

        if (single) {
            selectedTripStartLL = firstPt ? [firstPt[0], firstPt[1]] : null;
            selectedTripEndLL   = lastPt  ? [lastPt[0],  lastPt[1]]  : null;
        }

        // Accumulate for the 3D view regardless of single/multi selection.
        const tripMeta = allTrips.find(t => t.id === tripId) || null;
        if (!firstTripMeta) firstTripMeta = tripMeta;
        lastTripMeta = tripMeta;
        legsFor3d.push(...legs);

        legs.forEach(leg => {
            if (!leg.points || leg.points.length < 2) return;
            const lls = leg.points.map(p => [p[0], p[1]]);
            allLL.push(...lls);
            routeLayers.push(L.polyline(lls, {
                color:     leg.is_estimated ? '#d33' : colour,
                weight:    leg.is_estimated ? 2 : 3,
                dashArray: leg.is_estimated ? '7 6' : null,
                opacity:   0.85,
            }).addTo(pcio_bp_map));
        });

        if (single) {
            if (firstPt) routeLayers.push(L.circleMarker([firstPt[0], firstPt[1]],
                { radius:7, color:'#2a7a2a', fillColor:'#4caf50', fillOpacity:1, weight:2 }
            ).bindTooltip(PCIO_BP_L10N.start).addTo(pcio_bp_map));
            if (lastPt)  routeLayers.push(L.circleMarker([lastPt[0],  lastPt[1]],
                { radius:7, color:'#a00',    fillColor:'#e53',    fillOpacity:1, weight:2 }
            ).bindTooltip(PCIO_BP_L10N.end).addTo(pcio_bp_map));
        } else {
            if (firstPt) routeLayers.push(L.circleMarker([firstPt[0], firstPt[1]],
                { radius:6, color:colour, fillColor:colour, fillOpacity:.7, weight:2 }).addTo(pcio_bp_map));
            if (lastPt)  routeLayers.push(L.circleMarker([lastPt[0],  lastPt[1]],
                { radius:6, color:colour, fillColor:'#fff',  fillOpacity:.9, weight:2 }).addTo(pcio_bp_map));
        }
    }

    if (allLL.length) pcio_bp_map.fitBounds(L.latLngBounds(allLL), { padding: [40, 40] });

    // Update cached legs and forward to 3D view.
    if (legsFor3d.length) {
        _lastLegs = legsFor3d;
        _lastMeta = single
            ? firstTripMeta
            : { harbour_start: firstTripMeta?.harbour_start, harbour_end: lastTripMeta?.harbour_end };
        if (_3dActive && window.pcio_bp_3d) window.pcio_bp_3d.loadTrip(_lastLegs, _lastMeta);
    } else {
        _lastLegs = null;
        _lastMeta = null;
    }

    const toggleBtn = document.getElementById('btn-3d-toggle');
    if (toggleBtn) toggleBtn.disabled = !_lastLegs;

    if (single) {
        const trip = allTrips.find(t => t.id === [...selectedTrips][0]);
        if (trip) {
            ov.style.display = 'block';
            document.getElementById('ov-route').textContent = routeLabel(trip);
            document.getElementById('ov-dist').textContent  = trip.distance_nm ? `${trip.distance_nm} nm` : '—';
            document.getElementById('ov-dur').textContent   = trip.duration_min != null ? fmtDur(trip.duration_min) : '—';
            document.getElementById('ov-date').textContent    = fmtDateTime(trip.started_at);
            document.getElementById('ov-date-to').textContent = trip.ended_at ? ` – ${fmtDateTime(trip.ended_at)}` : '';
            document.getElementById('edit-harbour-form').style.display = 'none';
            document.getElementById('btn-edit-harbour').style.display  = PCIO_BP_CAN_EDIT ? '' : 'none';
            document.getElementById('btn-delete-trip').style.display   = PCIO_BP_CAN_EDIT ? '' : 'none';
        }
    } else {
        const trips  = [...selectedTrips].map(id => allTrips.find(t => t.id === id)).filter(Boolean);
        const total  = trips.reduce((s, t) => s + (parseFloat(t.distance_nm) || 0), 0);
        const sorted = trips.sort((a, b) => a.started_at < b.started_at ? -1 : 1);
        ov.style.display = 'block';
        document.getElementById('ov-route').textContent = PCIO_BP_L10N.tripsSelected.replace('{count}', selectedTrips.size);
        document.getElementById('ov-dist').textContent  = total ? `${total.toFixed(1)} nm` : '—';
        document.getElementById('ov-dur').textContent   = '—';
        document.getElementById('ov-date').textContent    =
            sorted.length ? fmtDate(sorted[0].started_at) : '—';
        document.getElementById('ov-date-to').textContent =
            sorted.length ? ` – ${fmtDate(sorted[sorted.length-1].started_at)}` : '';
        document.getElementById('edit-harbour-form').style.display = 'none';
        document.getElementById('btn-edit-harbour').style.display  = 'none';
        document.getElementById('btn-delete-trip').style.display   = 'none';
    }
}

// ── Harbour edit ─────────────────────────────────────────────────────────────
let harbourListLoaded = false;
let harbourData       = [];

async function ensureHarbourList() {
    if (harbourListLoaded) return;
    harbourData = await fetch(`${PCIO_BP_API}/harbours`).then(r => r.json());
    const dl = document.getElementById('harbour-datalist');
    dl.innerHTML = harbourData.map(h => `<option value="${h.name.replace(/"/g,'&quot;')}">`).join('');
    harbourListLoaded = true;
}

function haversineM(lat1, lon1, lat2, lon2) {
    const R = 6371000, toRad = d => d * Math.PI / 180;
    const dLat = toRad(lat2 - lat1), dLon = toRad(lon2 - lon1);
    const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function nearestHarbour(lat, lon) {
    for (const h of harbourData) {
        if (haversineM(lat, lon, +h.lat, +h.lon) <= +h.radius_m) return h.name;
    }
    return null;
}

async function locateHarbour(inputId, btnEl) {
    const isStart = inputId === 'edit-start';
    const coords  = isStart ? selectedTripStartLL : selectedTripEndLL;
    if (!coords) { alert(PCIO_BP_L10N.noTripPosition); return; }
    btnEl.classList.add('working');
    btnEl.disabled = true;
    try {
        await ensureHarbourList();
        const name = nearestHarbour(coords[0], coords[1]);
        document.getElementById(inputId).value = name || '(unknown)';
    } catch (err) {
        alert(PCIO_BP_L10N.couldNotDetect + err.message);
    } finally {
        btnEl.classList.remove('working');
        btnEl.disabled = false;
    }
}

document.getElementById('btn-locate-start').addEventListener('click', function() {
    locateHarbour('edit-start', this);
});
document.getElementById('btn-locate-end').addEventListener('click', function() {
    locateHarbour('edit-end', this);
});

// ── Merge bar ─────────────────────────────────────────────────────────────────
function updateMergeBar() {
    const btn = document.getElementById('btn-merge-trip');
    if (PCIO_BP_CAN_EDIT && selectedTrips.size >= 2) {
        btn.style.display = 'block';
        btn.textContent   = PCIO_BP_L10N.mergeBtn.replace('{count}', selectedTrips.size);
    } else {
        btn.style.display = 'none';
    }
}

document.getElementById('btn-merge-trip').addEventListener('click', async function() {
    if (selectedTrips.size < 2) return;
    const sorted = [...selectedTrips]
        .map(id => allTrips.find(t => t.id === id)).filter(Boolean)
        .sort((a, b) => a.started_at < b.started_at ? -1 : 1);
    if (!confirm(
        PCIO_BP_L10N.mergeConfirmLine1.replace('{count}', sorted.length) + '\n\n' +
        `  ${PCIO_BP_L10N.mergeConfirmEarliest} ${fmtDateTime(sorted[0].started_at)} — ${routeLabel(sorted[0])}\n` +
        `  ${PCIO_BP_L10N.mergeConfirmLatest}   ${fmtDateTime(sorted[sorted.length-1].started_at)} — ${routeLabel(sorted[sorted.length-1])}\n\n` +
        PCIO_BP_L10N.mergeConfirmNote
    )) return;

    this.disabled = true;
    const keepId = sorted[0].id;

    for (let i = 1; i < sorted.length; i++) {
        const body = new FormData();
        body.append('keep_id', keepId);
        body.append('drop_id', sorted[i].id);
        const res  = await fetch(`${PCIO_BP_API}/trips/merge`, {
            method: 'POST',
            headers: { 'X-WP-Nonce': PCIO_BP_NONCE },
            body,
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            alert(data.message || data.error || PCIO_BP_L10N.mergeFailed);
            this.disabled = false;
            return;
        }
    }

    selectedTrips = new Set([keepId]);
    allTrips      = await fetch(`${PCIO_BP_API}/trips`).then(r => r.json());
    visibleTrips  = [];
    renderTripList(allTrips);
    document.getElementById('logbook-heading').textContent = PCIO_BP_L10N.allTrips;
    showSelected();
});

document.getElementById('btn-edit-harbour').addEventListener('click', async () => {
    await ensureHarbourList();
    const trip = allTrips.find(t => t.id === [...selectedTrips][0]);
    document.getElementById('edit-start').value = trip?.harbour_start || '';
    document.getElementById('edit-end').value   = trip?.harbour_end   || '';
    document.getElementById('edit-harbour-form').style.display = 'block';
});

document.getElementById('btn-delete-trip').addEventListener('click', async function() {
    if (!PCIO_BP_CAN_EDIT || selectedTrips.size !== 1) return;
    const trip = allTrips.find(t => t.id === [...selectedTrips][0]);
    if (!trip) return;

    // First confirmation: show which entry will be removed.
    if (!confirm(
        PCIO_BP_L10N.deleteTripConfirm1 + '\n\n' +
        `${routeLabel(trip)}\n${fmtDateTime(trip.started_at)}`
    )) return;

    // Second, stronger confirmation – this is rarely needed and irreversible.
    if (!confirm(PCIO_BP_L10N.deleteTripConfirm2)) return;

    this.disabled = true;
    try {
        const res = await fetch(`${PCIO_BP_API}/trips/${trip.id}`, {
            method:  'DELETE',
            headers: { 'X-WP-Nonce': PCIO_BP_NONCE },
        });
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            alert(data.message || data.error || PCIO_BP_L10N.deleteTripFailed);
            return;
        }
    } catch (err) {
        alert(PCIO_BP_L10N.deleteTripFailed);
        return;
    } finally {
        this.disabled = false;
    }

    // Reset selection and refresh trips, calendar highlights and the list.
    selectedTrips = new Set();
    document.getElementById('trip-overlay').style.display = 'none';
    const [datesRes, tripsRes] = await Promise.all([
        fetch(`${PCIO_BP_API}/trips/active-dates`),
        fetch(`${PCIO_BP_API}/trips`),
    ]);
    activeDates  = new Set(await datesRes.json());
    allTrips     = await tripsRes.json();
    visibleTrips = [];
    renderCalendar();
    renderTripList(allTrips);
    document.getElementById('logbook-heading').textContent = PCIO_BP_L10N.allTrips;
    showSelected();
});

document.getElementById('btn-cancel-harbour').addEventListener('click', () => {
    document.getElementById('edit-harbour-form').style.display = 'none';
});

document.getElementById('btn-save-harbour').addEventListener('click', async () => {
    const start     = document.getElementById('edit-start').value.trim();
    const end       = document.getElementById('edit-end').value.trim();
    const currentId = [...selectedTrips][0];

    const body = new FormData();
    body.append('harbour_start', start);
    body.append('harbour_end',   end);

    const res  = await fetch(`${PCIO_BP_API}/trips/${currentId}/harbours`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': PCIO_BP_NONCE },
        body,
    });
    const data = await res.json();

    if (res.ok && data.ok) {
        if (data.new_harbours && data.new_harbours.length) harbourListLoaded = false;
        const trip = allTrips.find(t => t.id === currentId);
        if (trip) {
            trip.harbour_start = start || null;
            trip.harbour_end   = end   || null;
        }
        document.getElementById('ov-route').textContent = routeLabel(trip);
        renderTripList(visibleTrips.length ? visibleTrips : allTrips);
        document.getElementById('edit-harbour-form').style.display = 'none';
    } else {
        alert(data.message || data.error || PCIO_BP_L10N.saveFailed);
    }
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function routeLabel(t) {
    return `${t.harbour_start || '?'} → ${t.harbour_end || '?'}`;
}

function pad(n) { return String(n).padStart(2, '0'); }

function fmtDate(dtStr) {
    const d = new Date(dtStr.slice(0, 10) + 'T00:00:00Z');
    return d.toLocaleDateString(PCIO_BP_L10N.locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
}

function fmtDateTime(dtStr) {
    // DB values are stored in UTC (appended 'Z'); render in the browser's local timezone.
    const d = new Date(dtStr.replace(' ', 'T') + 'Z');
    return d.toLocaleString(PCIO_BP_L10N.locale, {
        day: 'numeric', month: 'short',
        hour: '2-digit', minute: '2-digit'
    });
}

function fmtDur(minutes) {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h ? `${h}h ${String(m).padStart(2,'0')}m` : `${m}m`;
}

// ── Boot ──────────────────────────────────────────────────────────────────────
async function pcio_bp_history_init() {
    const now = new Date();
    calYear   = now.getFullYear();
    calMonth  = now.getMonth();

    const [datesRes, tripsRes] = await Promise.all([
        fetch(`${PCIO_BP_API}/trips/active-dates`),
        fetch(`${PCIO_BP_API}/trips`),
    ]);

    activeDates = new Set(await datesRes.json());
    allTrips    = await tripsRes.json();

    renderCalendar();
    document.getElementById('logbook-heading').textContent = PCIO_BP_L10N.allTrips;
    visibleTrips = [];
    renderTripList(allTrips);

    if (allTrips.length) {
        selectedTrips = new Set([allTrips[0].id]);
        showSelected();
    }
}

pcio_bp_history_init();

// ── 3D / 2D toggle ───────────────────────────────────────────────────────────
(function () {
    const btn = document.getElementById('btn-3d-toggle');
    console.log('[history] btn-3d-toggle element:', btn);
    if (!btn) {
        console.warn('[history] btn-3d-toggle not found in DOM — template may not be deployed');
        return;
    }

    btn.addEventListener('click', () => {
        console.log('[history] 3D toggle clicked, window.pcio_bp_3d:', window.pcio_bp_3d);
        if (!window.pcio_bp_3d) return;

        _3dActive = !_3dActive;

        if (_3dActive) {
            // Switch to 3D: hide legend + overlay, show 3D map
            const legend = document.getElementById('legend');
            if (legend) legend.style.display = 'none';
            window.pcio_bp_3d.show();
            if (_lastLegs) window.pcio_bp_3d.loadTrip(_lastLegs, _lastMeta);
            btn.textContent = '\u25A1\u00A02D Map';
            btn.classList.add('active');
        } else {
            // Switch back to 2D
            window.pcio_bp_3d.hide();
            const legend = document.getElementById('legend');
            if (legend) legend.style.display = '';
            btn.innerHTML = '&#9651;&nbsp;3D View';
            btn.classList.remove('active');
        }
    });
})();

// ── Mobile layout ────────────────────────────────────────────────────────────
// On narrow screens move the route-info overlay out of the map and into the
// bottom panel, so the route info and the trip list share one container.
(function () {
    const mq      = window.matchMedia('(max-width: 768px)');
    const sidebar = document.getElementById('sidebar');
    const mapWrap = document.getElementById('map-wrap');
    const overlay = document.getElementById('trip-overlay');
    if (!sidebar || !mapWrap || !overlay) return;

    function place(e) {
        if (e.matches) {
            // Mobile: route info to the right of the trip list (same panel).
            sidebar.appendChild(overlay);
        } else if (overlay.parentNode !== mapWrap) {
            // Desktop: route info floats over the map again.
            mapWrap.appendChild(overlay);
        }
    }
    place(mq);
    mq.addEventListener('change', place);
})();

