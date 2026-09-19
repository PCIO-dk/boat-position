/* global L, PCIO_BP_EDITOR */
/**
 * Boat-log location picker shown in the post editor "Boat position" meta box.
 *
 * - Click or drag to set the marker.
 * - "Use current boat position" fetches the boat's latest fix.
 * - When opened from the map ("Write blog here", ?bp_lat/&bp_lon) the location
 *   is pre-set and the Boat Log category is ticked.
 *
 * The publish-time default (current boat position when none is set) is applied
 * server-side, so this picker only writes coordinates on an explicit action.
 */
(function () {
    'use strict';

    if (typeof PCIO_BP_EDITOR === 'undefined' || typeof L === 'undefined') { return; }

    document.addEventListener('DOMContentLoaded', function () {
        var mapEl   = document.getElementById('pcio-bp-meta-map');
        var latEl   = document.getElementById('pcio-bp-meta-lat');
        var lonEl   = document.getElementById('pcio-bp-meta-lon');
        var readout = document.getElementById('pcio-bp-meta-readout');
        var useBtn  = document.getElementById('pcio-bp-use-current');
        var clrBtn  = document.getElementById('pcio-bp-clear-location');
        if (!mapEl || !latEl || !lonEl) { return; }

        var l10n   = PCIO_BP_EDITOR.l10n || {};
        var marker = null;

        var map = L.map(mapEl, { zoomControl: true }).setView([55.0, 12.0], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        // Leaflet mis-measures while the meta box is still settling.
        setTimeout(function () { map.invalidateSize(); }, 200);

        function setReadout(text) {
            if (readout) { readout.textContent = text; }
        }

        function setLocation(lat, lon, zoom) {
            lat = parseFloat(lat);
            lon = parseFloat(lon);
            if (isNaN(lat) || isNaN(lon)) { return; }

            latEl.value = lat.toFixed(6);
            lonEl.value = lon.toFixed(6);
            setReadout(lat.toFixed(6) + ', ' + lon.toFixed(6));

            if (!marker) {
                marker = L.marker([lat, lon], { draggable: true }).addTo(map);
                marker.on('dragend', function () {
                    var p = marker.getLatLng();
                    setLocation(p.lat, p.lng);
                });
            } else {
                marker.setLatLng([lat, lon]);
            }
            map.setView([lat, lon], zoom || map.getZoom());
        }

        function clearLocation() {
            latEl.value = '';
            lonEl.value = '';
            setReadout(l10n.noLocation || '');
            if (marker) { map.removeLayer(marker); marker = null; }
        }

        map.on('click', function (e) {
            setLocation(e.latlng.lat, e.latlng.lng);
        });

        if (useBtn) {
            useBtn.addEventListener('click', function () {
                setReadout(l10n.fetching || '');
                fetch(PCIO_BP_EDITOR.latestUrl + '?t=' + Date.now())
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (data && typeof data.lat === 'number') {
                            setLocation(data.lat, data.lon, 11);
                        } else {
                            setReadout(l10n.noFix || '');
                        }
                    })
                    .catch(function () { setReadout(l10n.noFix || ''); });
            });
        }

        if (clrBtn) {
            clrBtn.addEventListener('click', function (e) {
                e.preventDefault();
                clearLocation();
            });
        }

        // ── Initial state ────────────────────────────────────────────────────────
        var startLat = latEl.value, startLon = lonEl.value;
        if (startLat !== '' && startLon !== '') {
            // Editing a post that already has a location.
            setLocation(startLat, startLon, 11);
        } else if (PCIO_BP_EDITOR.urlLat !== '' && PCIO_BP_EDITOR.urlLon !== '') {
            // "Write blog here" flow from the map.
            setLocation(PCIO_BP_EDITOR.urlLat, PCIO_BP_EDITOR.urlLon, 12);
        } else {
            // New/empty post: centre on the boat for context, but don't set a pin.
            fetch(PCIO_BP_EDITOR.latestUrl + '?t=' + Date.now())
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data && typeof data.lat === 'number') {
                        map.setView([data.lat, data.lon], 9);
                    }
                })
                .catch(function () { /* leave default view */ });
        }
    });
})();
