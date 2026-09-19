/**
 * 3D sailing replay for the boat-position history page.
 *
 * Classic IIFE script. Requires maplibregl and THREE globals loaded beforehand
 * via wp_enqueue_script (maplibre-gl.js v4 IIFE + three.min.js r148 UMD).
 *
 * Exposes: window.pcio_bp_3d = { show, hide, loadTrip, play, pause }
 */
/* global maplibregl, THREE */
'use strict';

console.log('[boat-3d] classic script loaded');

(function () {

    var BOAT_SIZE_M = 200;

    var _map      = null;
    var _mapDiv   = null;
    var _mapReady = false;

    var _boatLon    = 12.0;
    var _boatLat    = 56.0;
    var _boatCourse = 0;

    var _allPoints     = [];
    var _currentIdx    = 0;
    var _tripStartMs   = 0;
    var _tripElapsedMs = 0;

    var _playing     = false;
    var _speedMult   = 30;
    var _rafId       = null;
    var _lastFrameMs = null;

    var _pendingLegs = null;
    var _pendingMeta = null;

    var _mapZoom  = 9;

    var _btnPlay  = null;
    var _scrubber = null;
    var _labelEl  = null;
    var _timeEl   = null;

    // Three.js custom layer
    var _boatLayer = {
        id: 'pcio-boat-3d',
        type: 'custom',
        renderingMode: '3d',

        onAdd: function (map, gl) {
            this._renderer = new THREE.WebGLRenderer({
                canvas: map.getCanvas(),
                context: gl,
                antialias: true,
            });
            this._renderer.autoClear = false;
            this._scene  = new THREE.Scene();
            this._camera = new THREE.Camera();

            var sun = new THREE.DirectionalLight(0xfff8e0, 1.2);
            sun.position.set(1, -1, 2).normalize();
            this._scene.add(sun);
            var fill = new THREE.DirectionalLight(0xb0d0ff, 0.5);
            fill.position.set(-1, 1, 0.5).normalize();
            this._scene.add(fill);
            this._scene.add(new THREE.AmbientLight(0xffffff, 0.3));
            this._boatGroup = _buildBoat();
            this._scene.add(this._boatGroup);
        },

        render: function (gl, args) {
            // MapLibre v4+ passes args.defaultProjectionData.mainMatrix
            var rawMatrix = (args && args.defaultProjectionData)
                ? args.defaultProjectionData.mainMatrix
                : args;
            var mc    = maplibregl.MercatorCoordinate.fromLngLat([_boatLon, _boatLat], 0);
            var scale = mc.meterInMercatorCoordinateUnits() * BOAT_SIZE_M;
            var l = new THREE.Matrix4()
                .makeTranslation(mc.x, mc.y, mc.z)
                .scale(new THREE.Vector3(scale, -scale, scale))
                .multiply(new THREE.Matrix4().makeRotationZ(-_boatCourse * Math.PI / 180));
            this._camera.projectionMatrix = new THREE.Matrix4()
                .fromArray(rawMatrix)
                .multiply(l);
            this._renderer.resetState();
            this._renderer.render(this._scene, this._camera);
        },
    };

    function _buildBoat() {
        var g       = new THREE.Group();
        var hullMat = new THREE.MeshLambertMaterial({ color: 0x1a2e4a });
        var deckMat = new THREE.MeshLambertMaterial({ color: 0xd4a96a });
        var sailMat = new THREE.MeshLambertMaterial({ color: 0xf5f2e0, side: THREE.DoubleSide });
        var mastMat = new THREE.MeshLambertMaterial({ color: 0x888888 });

        g.add(new THREE.Mesh(new THREE.BoxGeometry(0.22, 0.80, 0.10), hullMat));

        var bow = new THREE.Mesh(new THREE.ConeGeometry(0.11, 0.36, 8), hullMat);
        bow.position.y = 0.58;
        bow.scale.z    = 0.55;
        g.add(bow);

        var deck = new THREE.Mesh(new THREE.BoxGeometry(0.16, 0.78, 0.02), deckMat);
        deck.position.z = 0.06;
        g.add(deck);

        var mast = new THREE.Mesh(new THREE.CylinderGeometry(0.022, 0.030, 1.30, 8), mastMat);
        mast.rotation.x = Math.PI / 2;
        mast.position.set(0, 0.08, 0.71);
        g.add(mast);

        var boom = new THREE.Mesh(new THREE.CylinderGeometry(0.012, 0.012, 0.55, 6), mastMat);
        boom.rotation.z = Math.PI / 2;
        boom.position.set(0.04, -0.15, 0.10);
        g.add(boom);

        var msGeo = new THREE.BufferGeometry();
        msGeo.setAttribute('position', new THREE.Float32BufferAttribute(
            [0, 0.08, 1.36, 0, 0.08, 0.06, 0.09, -0.38, 0.10], 3));
        msGeo.computeVertexNormals();
        g.add(new THREE.Mesh(msGeo, sailMat));

        var jbGeo = new THREE.BufferGeometry();
        jbGeo.setAttribute('position', new THREE.Float32BufferAttribute(
            [0, 0.08, 1.36, 0.04, 0.55, 0.06, 0, 0.08, 0.06], 3));
        jbGeo.computeVertexNormals();
        g.add(new THREE.Mesh(jbGeo, sailMat));

        return g;
    }

    function _emptyFC() {
        return { type: 'FeatureCollection', features: [] };
    }

    function _initMap(center) {
        _map = new maplibregl.Map({
            container: _mapDiv,
            style: {
                version: 8,
                sources: {
                    osm: {
                        type: 'raster',
                        tiles: [
                            'https://a.tile.openstreetmap.org/{z}/{x}/{y}.png',
                            'https://b.tile.openstreetmap.org/{z}/{x}/{y}.png',
                            'https://c.tile.openstreetmap.org/{z}/{x}/{y}.png',
                        ],
                        tileSize: 256,
                        attribution: '\u00a9 <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                        maxzoom: 19,
                    },
                    seamark: {
                        type: 'raster',
                        tiles: ['https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png'],
                        tileSize: 256,
                        attribution: 'OpenSeaMap',
                        maxzoom: 18,
                    },
                },
                layers: [
                    { id: 'osm-tiles', type: 'raster', source: 'osm' },
                    { id: 'sea-tiles', type: 'raster', source: 'seamark',
                      paint: { 'raster-opacity': 0.65 } },
                ],
            },
            center: center || [12.0, 56.0],
            zoom: 9,
            pitch: 50,
            bearing: 0,
            canvasContextAttributes: { antialias: true },
        });
        // Take over zoom so jumpTo (called every frame) never cancels user-initiated changes.
        _map.scrollZoom.disable();

        _map.on('load', function () {
            _mapReady = true;
            _mapZoom = _map.getZoom();
            // Sync after fitBounds or any camera operation when idle.
            _map.on('zoomend', function () { if (!_playing) _mapZoom = _map.getZoom(); });
            console.log('[boat-3d] MapLibre map ready');
            _map.addSource('pcio-route-real', { type: 'geojson', data: _emptyFC() });
            _map.addSource('pcio-route-est',  { type: 'geojson', data: _emptyFC() });
            _map.addLayer({ id: 'pcio-route-real', type: 'line', source: 'pcio-route-real',
                paint: { 'line-color': '#0057b8', 'line-width': 3, 'line-opacity': 0.9 } });
            _map.addLayer({ id: 'pcio-route-est', type: 'line', source: 'pcio-route-est',
                paint: { 'line-color': '#d33', 'line-width': 2, 'line-opacity': 0.85,
                         'line-dasharray': [7, 6] } });
            _map.addLayer(_boatLayer);
            if (_pendingLegs !== null) {
                _applyLegData(_pendingLegs, _pendingMeta);
                if (_allPoints.length) _positionBoat(_allPoints[0]);
                _pendingLegs = null;
                _pendingMeta = null;
            }
        });
    }

    function _applyLegData(legs, meta) {
        var real = [], est = [];
        legs.forEach(function (leg) {
            if (!leg.points || leg.points.length < 2) return;
            var coords = leg.points.map(function (p) { return [p[1], p[0]]; });
            (leg.is_estimated ? est : real).push({
                type: 'Feature', geometry: { type: 'LineString', coordinates: coords }, properties: {},
            });
        });
        if (_map && _map.getSource('pcio-route-real')) {
            _map.getSource('pcio-route-real').setData({ type: 'FeatureCollection', features: real });
            _map.getSource('pcio-route-est').setData( { type: 'FeatureCollection', features: est  });
        }
        var allCoords = real.concat(est).reduce(function (a, f) {
            return a.concat(f.geometry.coordinates);
        }, []);
        if (allCoords.length >= 2) {
            var lons = allCoords.map(function (c) { return c[0]; });
            var lats = allCoords.map(function (c) { return c[1]; });
            _map.fitBounds(
                [[Math.min.apply(null, lons), Math.min.apply(null, lats)],
                 [Math.max.apply(null, lons), Math.max.apply(null, lats)]],
                { padding: 80, pitch: 50, duration: 900 }
            );
        }
        if (_labelEl && meta) {
            _labelEl.textContent = (meta.harbour_start || '?') + ' \u2192 ' + (meta.harbour_end || '?');
        }
    }

    function _positionBoat(pt) {
        _boatLon    = pt.lon;
        _boatLat    = pt.lat;
        _boatCourse = pt.course || 0;
        if (_map) {
            _map.jumpTo({ center: [_boatLon, _boatLat], bearing: _boatCourse, zoom: _mapZoom });
        }
        _updateScrubber();
    }

    function _buildControls() {
        var ctrl = document.createElement('div');
        ctrl.className = 'pcio-bp-3d-controls';
        ctrl.innerHTML =
            '<button id="pcio-bp-3d-play"    class="pcio-bp-3d-btn" title="Play / Pause">&#9654;</button>' +
            '<input  id="pcio-bp-3d-scrub" type="range" min="0" max="100" value="0" class="pcio-bp-3d-scrub">' +
            '<select id="pcio-bp-3d-speed" class="pcio-bp-3d-speed">' +
                '<option value="10">&times;10</option>' +
                '<option value="30" selected>&times;30</option>' +
                '<option value="60">&times;60</option>' +
            '</select>' +
            '<button id="pcio-bp-3d-zoomin"  class="pcio-bp-3d-btn" title="Zoom in">+</button>' +
            '<button id="pcio-bp-3d-zoomout" class="pcio-bp-3d-btn" title="Zoom out">&minus;</button>' +
            '<span id="pcio-bp-3d-time"  class="pcio-bp-3d-time">&mdash;</span>' +
            '<span id="pcio-bp-3d-label" class="pcio-bp-3d-label">&mdash;</span>';
        _mapDiv.appendChild(ctrl);
        _btnPlay  = document.getElementById('pcio-bp-3d-play');
        _scrubber = document.getElementById('pcio-bp-3d-scrub');
        _labelEl  = document.getElementById('pcio-bp-3d-label');
        _timeEl   = document.getElementById('pcio-bp-3d-time');
        _btnPlay.addEventListener('click', function () { _playing ? pause() : play(); });
        _scrubber.addEventListener('input', function () {
            var idx = Math.min(Math.round((_scrubber.value / 100) * (_allPoints.length - 1)), _allPoints.length - 1);
            _currentIdx    = idx;
            _tripElapsedMs = _allPoints.length ? (_allPoints[idx].t - _allPoints[0].t) * 1000 : 0;
            if (_allPoints[idx]) _positionBoat(_allPoints[idx]);
        });
        document.getElementById('pcio-bp-3d-speed').addEventListener('change', function () {
            _speedMult = parseInt(this.value, 10);
        });
        document.getElementById('pcio-bp-3d-zoomin').addEventListener('click', function () {
            _mapZoom = Math.min(22, _mapZoom + 1);
            if (_map && !_playing) _map.jumpTo({ zoom: _mapZoom });
        });
        document.getElementById('pcio-bp-3d-zoomout').addEventListener('click', function () {
            _mapZoom = Math.max(1, _mapZoom - 1);
            if (_map && !_playing) _map.jumpTo({ zoom: _mapZoom });
        });
        // Wheel zoom: update _mapZoom so the next jumpTo (or immediate apply) picks it up.
        _mapDiv.addEventListener('wheel', function (e) {
            e.preventDefault();
            _mapZoom = Math.max(1, Math.min(22, _mapZoom + (e.deltaY < 0 ? 0.5 : -0.5)));
            if (_map && !_playing) _map.jumpTo({ zoom: _mapZoom });
        }, { passive: false });
    }

    function _updateScrubber() {
        if (!_scrubber || !_allPoints.length) return;
        _scrubber.value = (_currentIdx / Math.max(_allPoints.length - 1, 1)) * 100;
        _updateClock();
    }

    // Show the replay's current moment as the viewer's local date/time.
    function _updateClock() {
        if (!_timeEl || !_allPoints.length) return;
        var pt = _allPoints[_currentIdx];
        if (!pt || !pt.t) { _timeEl.textContent = '\u2014'; return; }
        _timeEl.textContent = new Date(pt.t * 1000).toLocaleString([], {
            month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    function _updatePlayBtn() {
        if (_btnPlay) _btnPlay.innerHTML = _playing ? '&#9646;&#9646;' : '&#9654;';
    }

    function _animFrame(nowMs) {
        if (!_playing) return;
        var wallDelta   = _lastFrameMs !== null ? nowMs - _lastFrameMs : 0;
        _lastFrameMs    = nowMs;
        _tripElapsedMs += wallDelta * _speedMult;
        var targetMs    = _tripStartMs + _tripElapsedMs;

        // Advance index to the segment containing targetMs.
        while (_currentIdx < _allPoints.length - 1 &&
               _allPoints[_currentIdx + 1].t * 1000 <= targetMs) {
            _currentIdx++;
        }

        // Interpolate position/course between log points for smooth movement.
        if (_currentIdx < _allPoints.length - 1) {
            var cur        = _allPoints[_currentIdx];
            var next       = _allPoints[_currentIdx + 1];
            // Hold at leg-end position; don't slide across a port gap to the next leg.
            if (cur.legIdx !== next.legIdx) {
                _boatLon    = cur.lon;
                _boatLat    = cur.lat;
                _boatCourse = cur.course || 0;
            } else {
                var segStartMs = cur.t  * 1000;
                var segEndMs   = next.t * 1000;
                var frac       = segEndMs > segStartMs
                    ? Math.max(0, Math.min(1, (targetMs - segStartMs) / (segEndMs - segStartMs)))
                    : 0;
                _boatLon    = cur.lon + (next.lon - cur.lon) * frac;
                _boatLat    = cur.lat + (next.lat - cur.lat) * frac;
                // Shortest-arc bearing interpolation (handles 0/360 wrap).
                var dc      = ((next.course - cur.course + 540) % 360) - 180;
                _boatCourse = cur.course + dc * frac;
            }
        } else {
            var endPt   = _allPoints[_currentIdx];
            _boatLon    = endPt.lon;
            _boatLat    = endPt.lat;
            _boatCourse = endPt.course || 0;
        }

        if (_map) {
            // Keep the boat centred; rotate map to match heading so bow points up.
            _map.jumpTo({ center: [_boatLon, _boatLat], bearing: _boatCourse, zoom: _mapZoom });
        }
        _updateScrubber();

        if (_currentIdx >= _allPoints.length - 1) {
            _playing = false;
            _updatePlayBtn();
            return;
        }
        _rafId = requestAnimationFrame(_animFrame);
    }

    function show() {
        if (!_mapDiv) return;
        document.getElementById('map').style.display = 'none';
        _mapDiv.style.display = 'block';
        if (_map) _map.resize();
    }

    function hide() {
        if (!_mapDiv) return;
        pause();
        _mapDiv.style.display = 'none';
        document.getElementById('map').style.display = 'block';
    }

    function loadTrip(legs, meta) {
        _allPoints = [];
        var legIdx = 0;
        legs.forEach(function (leg) {
            if (!leg.points || leg.points.length < 2 || leg.is_estimated) return;
            leg.points.forEach(function (p) {
                _allPoints.push({ lat: p[0], lon: p[1], speed: p[2] || 0, course: p[3] || 0, t: p[4] || 0, legIdx: legIdx });
            });
            legIdx++;
        });
        _currentIdx    = 0;
        _tripStartMs   = _allPoints.length ? _allPoints[0].t * 1000 : 0;
        _tripElapsedMs = 0;
        pause();

        if (!_allPoints.length) return;
        var center = [_allPoints[0].lon, _allPoints[0].lat];

        if (!_map) {
            _initMap(center);
            _pendingLegs = legs;
            _pendingMeta = meta;
        } else if (!_mapReady) {
            _pendingLegs = legs;
            _pendingMeta = meta;
        } else {
            _applyLegData(legs, meta);
            _positionBoat(_allPoints[0]);
        }

        if (_labelEl && meta) {
            _labelEl.textContent = (meta.harbour_start || '?') + ' \u2192 ' + (meta.harbour_end || '?');
        }
        _updateScrubber();
        _updatePlayBtn();
    }

    function play() {
        if (!_allPoints.length) return;
        if (_currentIdx >= _allPoints.length - 1) { _currentIdx = 0; _tripElapsedMs = 0; }
        _playing     = true;
        _lastFrameMs = null;
        _updatePlayBtn();
        _rafId = requestAnimationFrame(_animFrame);
    }

    function pause() {
        _playing = false;
        if (_rafId !== null) { cancelAnimationFrame(_rafId); _rafId = null; }
        _updatePlayBtn();
    }

    (function _init() {
        console.log('[boat-3d] init, looking for #map-wrap');
        var mapWrap = document.getElementById('map-wrap');
        if (!mapWrap) { console.warn('[boat-3d] #map-wrap not found'); return; }
        _mapDiv = document.createElement('div');
        _mapDiv.id = 'pcio-bp-3d-map';
        _mapDiv.style.display = 'none';
        mapWrap.insertBefore(_mapDiv, document.getElementById('map'));
        _buildControls();
        console.log('[boat-3d] init complete');
    }());

    window.pcio_bp_3d = { show: show, hide: hide, loadTrip: loadTrip, play: play, pause: pause };
    console.log('[boat-3d] window.pcio_bp_3d exposed');

}());
