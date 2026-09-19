/* PCIO_BP_API, PCIO_BP_CAN_EDIT, PCIO_BP_NONCE are injected by wp_add_inline_script() before this file. */
'use strict';

// ── State ─────────────────────────────────────────────────────────────────────
let plans         = [];
let currentPlanId = null;
let waypoints     = [];
let locationIndex = []; // { label, name, lat, lon } – harbours + existing plan waypoints

// ── Utils ─────────────────────────────────────────────────────────────────────
const todayStr = () => new Date().toISOString().slice( 0, 10 );

function daysToGo( eta ) {
    if ( !eta ) return null;
    return Math.round( ( new Date( eta ) - new Date( todayStr() ) ) / 86400000 );
}

function fmtEta( eta ) {
    if ( !eta ) return '—';
    return new Date( eta + 'T12:00:00' ).toLocaleDateString( undefined, {
        day: 'numeric', month: 'short', year: 'numeric',
    } );
}

function fmtDays( d ) {
    if ( d === null ) return '—';
    if ( d === 0 )   return PCIO_BP_L10N.today;
    return d > 0 ? '+' + d + 'd' : Math.abs( d ) + 'd ago';
}

const escHtml = s => String( s )
    .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
    .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

/** Great-circle distance in nautical miles (Haversine). */
function nmDist( lat1, lon1, lat2, lon2 ) {
    const R    = 3440.065; // Earth radius in nm
    const dLat = ( lat2 - lat1 ) * Math.PI / 180;
    const dLon = ( lon2 - lon1 ) * Math.PI / 180;
    const a    = Math.sin( dLat / 2 ) ** 2
               + Math.cos( lat1 * Math.PI / 180 ) * Math.cos( lat2 * Math.PI / 180 )
               * Math.sin( dLon / 2 ) ** 2;
    return R * 2 * Math.atan2( Math.sqrt( a ), Math.sqrt( 1 - a ) );
}

const apiHdr = () => ( { 'Content-Type': 'application/json', 'X-WP-Nonce': PCIO_BP_NONCE } );

// ── DOM shortcuts ─────────────────────────────────────────────────────────────
const $id    = id  => document.getElementById( id );
const show   = id  => { const e = $id( id ); if ( e ) e.style.display = ''; };
const hide   = id  => { const e = $id( id ); if ( e ) e.style.display = 'none'; };
const getVal = id  => { const e = $id( id ); return e ? e.value.trim() : ''; };
const setVal = ( id, v ) => { const e = $id( id ); if ( e ) e.value = ( v ?? '' ); };
const on     = ( id, ev, fn ) => { const e = $id( id ); if ( e ) e.addEventListener( ev, fn ); };

// Eye SVGs for visibility toggle
const SVG_EYE_OPEN   = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="12" rx="9" ry="5"/><circle cx="12" cy="12" r="2.5" fill="currentColor" stroke="none"/></svg>`;
const SVG_EYE_CLOSED = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="12" rx="9" ry="5"/><circle cx="12" cy="12" r="2.5" fill="currentColor" stroke="none"/><line x1="3" y1="3" x2="21" y2="21"/></svg>`;

// ── Bootstrap ─────────────────────────────────────────────────────────────────
async function init() {
    if ( PCIO_BP_CAN_EDIT ) {
        show( 'btn-show-new-plan' );
        const thActs = document.querySelector( '.col-acts' );
        if ( thActs ) thActs.style.display = '';
    }

    try {
        plans = await fetch( PCIO_BP_API + '/planned-trips' ).then( r => r.json() );
    } catch ( e ) {
        console.error( e );
        plans = [];
    }

    await pcio_plan_buildLocationIndex();

    // If URL contains #plan-{id}, open that plan's waypoint view directly
    const hashMatch = window.location.hash.match( /^#plan-(\d+)\/?$/ );
    if ( hashMatch ) {
        const targetId = parseInt( hashMatch[ 1 ], 10 );
        const target   = plans.find( p => p.id === targetId );
        if ( target ) {
            renderPlanList(); // build list in background (needed for back-button)
            pcio_plan_openPlan( targetId );
            return;
        }
    }

    renderPlanList();
}

// ── Plan list view ────────────────────────────────────────────────────────────
function renderPlanList() {
    hide( 'plan-wp-view' );
    hide( 'new-plan-form' );
    show( 'plan-list-view' );

    const container = $id( 'plan-list-items' );
    if ( !container ) return;

    if ( !plans.length ) {
        container.innerHTML = '';
        show( 'plan-empty' );
        return;
    }
    hide( 'plan-empty' );

    container.innerHTML = plans.map( p => {
        const vis      = effectiveVisible( p );
        const visClass = vis ? 'btn-vis btn-vis-on' : 'btn-vis btn-vis-off';
        const visTitle = vis ? PCIO_BP_L10N.visibleOnMap : PCIO_BP_L10N.hiddenFromMap;
        // Visibility toggle is available to everyone
        const visBtn = `<button class="${visClass}" onclick="pcio_plan_toggleVisible(${p.id})" title="${visTitle}">${vis ? SVG_EYE_OPEN : SVG_EYE_CLOSED}</button>`;

        const delBtn = PCIO_BP_CAN_EDIT
            ? `<button class="btn-icon btn-danger" onclick="pcio_plan_deletePlan(${p.id})" title="${PCIO_BP_L10N.deletePlanTitle}">&#x2715;</button>`
            : '';

        // Editors see a pencil (edit), others see a list icon (view waypoints)
        const openIcon  = PCIO_BP_CAN_EDIT ? '&#9998;' : '&#9776;';
        const openTitle = PCIO_BP_CAN_EDIT ? PCIO_BP_L10N.editWaypoints : PCIO_BP_L10N.viewWaypoints;

        return `<div class="plan-list-row" id="plan-row-${p.id}">
            ${visBtn}
            <span class="plan-row-name">${escHtml( p.title || PCIO_BP_L10N.untitled )}</span>
            <div class="plan-row-acts">
                <button class="btn-icon" onclick="pcio_plan_openPlan(${p.id})" title="${openTitle}">${openIcon}</button>
                ${delBtn}
            </div>
        </div>`;
    } ).join( '' );
}

function showPlanList() {
    currentPlanId = null;
    waypoints     = [];
    hide( 'plan-wp-view' );
    show( 'plan-list-view' );
    renderPlanList();
}

// ── Local visibility overrides (anonymous users) ────────────────────────────
const VIS_KEY = 'pcio_bp_plan_vis';

function localVisGet() {
    try { return JSON.parse( localStorage.getItem( VIS_KEY ) || '{}' ); } catch { return {}; }
}

function localVisSet( id, value ) {
    const store = localVisGet();
    store[ id ] = value;
    localStorage.setItem( VIS_KEY, JSON.stringify( store ) );
}

/** Returns effective visible value: localStorage override wins for anonymous users. */
function effectiveVisible( plan ) {
    if ( PCIO_BP_IS_LOGGED_IN ) return plan.visible;
    const store = localVisGet();
    return ( plan.id in store ) ? store[ plan.id ] : plan.visible;
}

async function pcio_plan_toggleVisible( id ) {
    const plan = plans.find( p => p.id === id );
    if ( !plan ) return;
    const curVisible = effectiveVisible( plan );
    const newVisible = curVisible ? 0 : 1;

    if ( PCIO_BP_IS_LOGGED_IN ) {
        // Persist to DB for logged-in users
        try {
            const res = await fetch( PCIO_BP_API + '/planned-trips/' + id + '/visible', {
                method:  'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PCIO_BP_NONCE },
                body:    JSON.stringify( { visible: newVisible } ),
            } );
            if ( !res.ok ) return;
            plan.visible = newVisible;
        } catch ( e ) { console.error( e ); return; }
    } else {
        // Anonymous: store in localStorage only, never touch the DB
        localVisSet( id, newVisible );
    }

    const row = $id( 'plan-row-' + id );
    if ( row ) {
        const btn = row.querySelector( '.btn-vis' );
        if ( btn ) {
            btn.className = newVisible ? 'btn-vis btn-vis-on' : 'btn-vis btn-vis-off';
            btn.title     = newVisible ? PCIO_BP_L10N.visibleOnMap : PCIO_BP_L10N.hiddenFromMap;
            btn.innerHTML = newVisible ? SVG_EYE_OPEN : SVG_EYE_CLOSED;
        }
    }
}

// ── Delete plan ───────────────────────────────────────────────────────────────
async function pcio_plan_deletePlan( id ) {
    const plan = plans.find( p => p.id === id );
    if ( !plan ) return;
    if ( !confirm( PCIO_BP_L10N.deletePlanConfirm.replace( '{title}', plan.title || PCIO_BP_L10N.untitled ) ) ) return;

    try {
        const res = await fetch( PCIO_BP_API + '/planned-trips/' + id, {
            method:  'DELETE',
            headers: { 'X-WP-Nonce': PCIO_BP_NONCE },
        } );
        if ( res.ok ) {
            plans = plans.filter( p => p.id !== id );
            renderPlanList();
        }
    } catch ( e ) { console.error( e ); }
}

// ── Open plan (waypoint view) ─────────────────────────────────────────────────
async function pcio_plan_openPlan( id ) {
    currentPlanId = id;
    const plan    = plans.find( p => p.id === id );
    if ( !plan ) return;

    hide( 'plan-list-view' );
    show( 'plan-wp-view' );

    pcio_plan_setTitleDisplay( plan );

    const notesEl = $id( 'plan-notes' );
    if ( notesEl ) notesEl.textContent = plan.notes || '';

    if ( PCIO_BP_CAN_EDIT ) {
        show( 'add-wp-area' );
        const thActs = document.querySelector( '.col-acts' );
        if ( thActs ) thActs.style.display = '';
    }

    await loadWaypoints();
}

// ── Editable plan title ───────────────────────────────────────────────────────
function pcio_plan_setTitleDisplay( plan ) {
    const el = $id( 'plan-title-text' );
    if ( el ) el.textContent = plan.title || PCIO_BP_L10N.untitled;
    show( 'plan-title-display' );
    hide( 'plan-title-edit' );
    const notesEl = $id( 'plan-notes' );
    if ( notesEl ) notesEl.textContent = plan.notes || '';
}

on( 'btn-edit-plan-title', 'click', () => {
    const plan = plans.find( p => p.id === currentPlanId );
    if ( !plan ) return;
    setVal( 'plan-title-input', plan.title || '' );
    setVal( 'plan-notes-input', plan.notes || '' );
    hide( 'plan-title-display' );
    hide( 'plan-notes' );
    show( 'plan-title-edit' );
    $id( 'plan-title-input' )?.focus();
} );

on( 'btn-cancel-title-edit', 'click', () => {
    const plan = plans.find( p => p.id === currentPlanId );
    if ( plan ) pcio_plan_setTitleDisplay( plan );
    show( 'plan-notes' );
} );

on( 'btn-save-plan-title', 'click', async () => {
    const title = getVal( 'plan-title-input' );
    const notes = getVal( 'plan-notes-input' );
    if ( !title ) { $id( 'plan-title-input' )?.focus(); return; }

    try {
        const res = await fetch( PCIO_BP_API + '/planned-trips/' + currentPlanId, {
            method:  'PATCH',
            headers: apiHdr(),
            body:    JSON.stringify( { title, notes } ),
        } );
        if ( res.ok ) {
            const updated = await res.json();
            const idx = plans.findIndex( p => p.id === currentPlanId );
            if ( idx !== -1 ) plans[ idx ] = updated;
            pcio_plan_setTitleDisplay( updated );
            show( 'plan-notes' );
        }
    } catch ( e ) { console.error( e ); }
} );

// ── Back to plan list ─────────────────────────────────────────────────────────
on( 'btn-back-to-plans', 'click', showPlanList );

// ── New plan ──────────────────────────────────────────────────────────────────
on( 'btn-show-new-plan', 'click', () => {
    setVal( 'new-plan-title', '' );
    setVal( 'new-plan-notes', '' );
    show( 'new-plan-form' );
    $id( 'new-plan-title' )?.focus();
} );

on( 'btn-cancel-new-plan', 'click', () => {
    hide( 'new-plan-form' );
} );

on( 'btn-create-plan', 'click', async () => {
    const title = getVal( 'new-plan-title' );
    const notes = getVal( 'new-plan-notes' );
    if ( !title ) { $id( 'new-plan-title' )?.focus(); return; }

    try {
        const res = await fetch( PCIO_BP_API + '/planned-trips', {
            method: 'POST', headers: apiHdr(), body: JSON.stringify( { title, notes } ),
        } );
        if ( !res.ok ) return;
        const plan = await res.json();
        plans.unshift( plan );
        hide( 'new-plan-form' );
        renderPlanList();
    } catch ( e ) { console.error( e ); }
} );

// ── Waypoints load + render ───────────────────────────────────────────────────
async function loadWaypoints() {
    if ( !currentPlanId ) return;
    try {
        waypoints = await fetch(
            PCIO_BP_API + '/planned-trips/' + currentPlanId + '/waypoints'
        ).then( r => r.json() );
    } catch ( e ) {
        console.error( e );
        waypoints = [];
    }
    renderWaypoints();
}

function renderWaypoints() {
    const tbody = $id( 'wp-tbody' );
    if ( !tbody ) return;
    const today = todayStr();

    if ( !waypoints.length ) {
        tbody.innerHTML = '';
        show( 'wp-empty-msg' );
        const totalEl = $id( 'wp-total-dist' );
        if ( totalEl ) totalEl.textContent = '';
        return;
    }
    hide( 'wp-empty-msg' );

    let totalNm = 0;

    tbody.innerHTML = waypoints.map( ( wp, i ) => {
        const days    = daysToGo( wp.eta_date );
        const isPast  = wp.eta_date && wp.eta_date < today;
        const isToday = wp.eta_date && wp.eta_date === today;
        const rowCls  = isPast ? 'wp-past' : ( isToday ? 'wp-today' : '' );

        const dayCell = !wp.eta_date
            ? '<span class="days-val">—</span>'
            : isPast
                ? `<span class="badge badge-past">${fmtDays( days )}</span>`
                : isToday
                    ? `<span class="badge badge-today">${PCIO_BP_L10N.today}</span>`
                    : `<span class="days-future">${fmtDays( days )}</span>`;

        let distCell = '<span class="dist-val">—</span>';
        if ( i > 0 ) {
            const prev = waypoints[ i - 1 ];
            const d    = nmDist( parseFloat( prev.lat ), parseFloat( prev.lon ),
                                 parseFloat( wp.lat ),   parseFloat( wp.lon ) );
            totalNm += d;
            distCell = `<span class="dist-val">${d.toFixed( 1 )} nm</span>`;
        }

        const actsTd = PCIO_BP_CAN_EDIT
            ? `<td class="col-acts">
                   <button class="btn-icon" onclick="pcio_plan_editRow(${wp.id})" title="${PCIO_BP_L10N.editTitle}">&#9998;</button>
                   <button class="btn-icon btn-danger" onclick="pcio_plan_deleteWp(${wp.id})" title="${PCIO_BP_L10N.deleteBtn}">&#x2715;</button>
                   <button class="btn-icon btn-rebase" onclick="pcio_plan_showRebase(${wp.id})" title="${PCIO_BP_L10N.rebaseFromHere}">&#x21BA;</button>
               </td>`
            : '';

        const orderCell = PCIO_BP_CAN_EDIT
            ? `<td class="col-order col-drag-handle" title="${PCIO_BP_L10N.dragToReorder}">&#8942;&#8942;</td>`
            : `<td class="col-order">${i + 1}</td>`;

        return `<tr id="wp-row-${wp.id}" class="wp-row ${rowCls}" data-wid="${wp.id}" ${ PCIO_BP_CAN_EDIT ? 'draggable="true"' : '' }>
            ${orderCell}
            <td class="col-name">${escHtml( wp.name )}</td>
            <td class="col-eta">${fmtEta( wp.eta_date )}</td>
            <td class="col-days">${dayCell}</td>
            <td class="col-dist">${distCell}</td>
            ${actsTd}
        </tr>`;
    } ).join( '' );

    const totalEl = $id( 'wp-total-dist' );
    if ( totalEl ) {
        totalEl.textContent = waypoints.length > 1
            ? PCIO_BP_L10N.totalDistLabel + ': ' + totalNm.toFixed( 1 ) + ' nm'
            : '';
    }

    if ( PCIO_BP_CAN_EDIT ) pcio_plan_attachDnd();
}

// ── Drag-and-drop reordering ──────────────────────────────────────────────────
function pcio_plan_attachDnd() {
    const tbody = $id( 'wp-tbody' );
    if ( !tbody ) return;

    let dragSrc = null;

    tbody.querySelectorAll( 'tr.wp-row' ).forEach( row => {
        row.addEventListener( 'dragstart', e => {
            dragSrc = row;
            row.classList.add( 'dnd-dragging' );
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData( 'text/plain', row.dataset.wid );
        } );

        row.addEventListener( 'dragend', () => {
            row.classList.remove( 'dnd-dragging' );
            tbody.querySelectorAll( '.dnd-over' ).forEach( r => r.classList.remove( 'dnd-over' ) );
            dragSrc = null;
        } );

        row.addEventListener( 'dragover', e => {
            if ( !dragSrc || dragSrc === row ) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            tbody.querySelectorAll( '.dnd-over' ).forEach( r => r.classList.remove( 'dnd-over' ) );
            row.classList.add( 'dnd-over' );
        } );

        row.addEventListener( 'dragleave', e => {
            if ( !row.contains( e.relatedTarget ) ) row.classList.remove( 'dnd-over' );
        } );

        row.addEventListener( 'drop', async e => {
            e.preventDefault();
            if ( !dragSrc || dragSrc === row ) return;

            const fromWid = parseInt( dragSrc.dataset.wid, 10 );
            const toWid   = parseInt( row.dataset.wid, 10 );
            const fromIdx = waypoints.findIndex( w => w.id === fromWid );
            const toIdx   = waypoints.findIndex( w => w.id === toWid );
            if ( fromIdx === -1 || toIdx === -1 ) return;

            const [ moved ] = waypoints.splice( fromIdx, 1 );
            waypoints.splice( toIdx, 0, moved );
            waypoints.forEach( ( w, i ) => { w.sort_order = i; } );

            renderWaypoints();

            try {
                await Promise.all( waypoints.map( ( w, i ) =>
                    fetch( `${PCIO_BP_API}/planned-trips/${currentPlanId}/waypoints/${w.id}`, {
                        method:  'PATCH',
                        headers: apiHdr(),
                        body:    JSON.stringify( { sort_order: i } ),
                    } )
                ) );
            } catch ( err ) { console.error( err ); }
        } );
    } );
}

// ── Row inline edit (exposed globally for onclick in innerHTML) ───────────────
function pcio_plan_editRow( wid ) {
    const wp  = waypoints.find( w => w.id === wid );
    const row = $id( 'wp-row-' + wid );
    if ( !wp || !row ) return;
    pcio_plan_cancelRebase();

    row.innerHTML = `
        <td class="col-order"></td>
        <td class="col-name">
            <input type="text" id="edit-n-${wid}" value="${escHtml( wp.name )}" maxlength="255">
        </td>
        <td class="col-eta">
            <input type="date" id="edit-e-${wid}" value="${wp.eta_date || ''}">
        </td>
        <td class="col-days"></td>
        <td class="col-dist"></td>
        <td class="col-acts">
            <button class="pcio-bp-btn-primary btn-sm" onclick="pcio_plan_saveRow(${wid})">${PCIO_BP_L10N.save}</button>
            <button class="pcio-bp-btn-secondary btn-sm" onclick="renderWaypoints()">${PCIO_BP_L10N.cancel}</button>
        </td>`;
    $id( 'edit-n-' + wid )?.focus();
}

async function pcio_plan_saveRow( wid ) {
    const nameEl = $id( 'edit-n-' + wid );
    const etaEl  = $id( 'edit-e-' + wid );
    const name   = nameEl?.value.trim() ?? '';
    const eta    = etaEl?.value || null;
    if ( !name ) { nameEl?.focus(); return; }

    try {
        const res = await fetch(
            `${PCIO_BP_API}/planned-trips/${currentPlanId}/waypoints/${wid}`,
            { method: 'PATCH', headers: apiHdr(), body: JSON.stringify( { name, eta_date: eta } ) }
        );
        if ( res.ok ) {
            const updated = await res.json();
            const idx = waypoints.findIndex( w => w.id === wid );
            if ( idx !== -1 ) waypoints[ idx ] = updated;
            renderWaypoints();
        }
    } catch ( e ) { console.error( e ); }
}

async function pcio_plan_deleteWp( wid ) {
    const wp = waypoints.find( w => w.id === wid );
    if ( !wp || !confirm( PCIO_BP_L10N.deleteWpConfirm.replace( '{name}', wp.name ) ) ) return;

    try {
        const res = await fetch(
            `${PCIO_BP_API}/planned-trips/${currentPlanId}/waypoints/${wid}`,
            { method: 'DELETE', headers: { 'X-WP-Nonce': PCIO_BP_NONCE } }
        );
        if ( res.ok ) {
            waypoints = waypoints.filter( w => w.id !== wid );
            renderWaypoints();
        }
    } catch ( e ) { console.error( e ); }
}

// ── Rebase inline form ────────────────────────────────────────────────────────
function pcio_plan_showRebase( wid ) {
    pcio_plan_cancelRebase();
    const wp  = waypoints.find( w => w.id === wid );
    const row = $id( 'wp-row-' + wid );
    if ( !wp || !row ) return;

    const colSpan = PCIO_BP_CAN_EDIT ? 6 : 5;
    const tr = document.createElement( 'tr' );
    tr.id        = 'rebase-row';
    tr.className = 'rebase-row';
    tr.innerHTML = `
        <td colspan="${colSpan}" class="rebase-cell">
            ${PCIO_BP_L10N.rebaseShift} <strong>${escHtml( wp.name )}</strong> ${PCIO_BP_L10N.rebaseAndAll}&nbsp;
            <input type="number" id="rebase-delta" value="1" min="-365" max="365">
            &nbsp;${PCIO_BP_L10N.rebaseDays}
            <button class="pcio-bp-btn-primary btn-sm" onclick="pcio_plan_applyRebase(${wid})">${PCIO_BP_L10N.apply}</button>
            <button class="pcio-bp-btn-secondary btn-sm" onclick="pcio_plan_cancelRebase()">${PCIO_BP_L10N.cancel}</button>
            <span class="rebase-hint">${PCIO_BP_L10N.rebaseHint}</span>
        </td>`;
    row.insertAdjacentElement( 'afterend', tr );
    $id( 'rebase-delta' )?.select();
}

function pcio_plan_cancelRebase() {
    $id( 'rebase-row' )?.remove();
}

async function pcio_plan_applyRebase( wid ) {
    const delta = parseInt( $id( 'rebase-delta' )?.value ?? '0', 10 );
    if ( !delta ) { pcio_plan_cancelRebase(); return; }

    try {
        const res = await fetch(
            `${PCIO_BP_API}/planned-trips/${currentPlanId}/rebase`,
            { method: 'POST', headers: apiHdr(), body: JSON.stringify( { from_wid: wid, delta_days: delta } ) }
        );
        if ( res.ok ) {
            const data = await res.json();
            ( data.updated || [] ).forEach( u => {
                const idx = waypoints.findIndex( w => w.id === u.id );
                if ( idx !== -1 ) waypoints[ idx ].eta_date = u.eta_date;
            } );
            pcio_plan_cancelRebase();
            renderWaypoints();
        }
    } catch ( e ) { console.error( e ); }
}

// ── Add waypoint ──────────────────────────────────────────────────────────────
on( 'btn-show-add-wp', 'click', () => {
    hide( 'btn-show-add-wp' );
    show( 'add-wp-form' );
    setVal( 'add-wp-search', '' );
    $id( 'add-wp-search' )?.focus();
} );

on( 'btn-cancel-add-wp', 'click', () => {
    hide( 'add-wp-form' );
    show( 'btn-show-add-wp' );
} );

on( 'btn-add-wp', 'click', async () => {
    const name = getVal( 'add-wp-name' );
    const eta  = getVal( 'add-wp-eta' ) || null;
    const lat  = parseFloat( getVal( 'add-wp-lat' ) );
    const lon  = parseFloat( getVal( 'add-wp-lon' ) );
    if ( !name )              { $id( 'add-wp-name' )?.focus(); return; }
    if ( isNaN( lat ) || isNaN( lon ) ) { $id( 'add-wp-lat' )?.focus(); return; }

    try {
        const res = await fetch( PCIO_BP_API + '/planned-trips/' + currentPlanId + '/waypoints', {
            method:  'POST',
            headers: apiHdr(),
            body:    JSON.stringify( { name, lat, lon, eta_date: eta, sort_order: waypoints.length } ),
        } );
        if ( res.ok ) {
            const wp = await res.json();
            waypoints.push( wp );
            setVal( 'add-wp-search', '' );
            setVal( 'add-wp-name', '' );
            setVal( 'add-wp-eta',  '' );
            setVal( 'add-wp-lat',  '' );
            setVal( 'add-wp-lon',  '' );
            hide( 'add-wp-form' );
            show( 'btn-show-add-wp' );
            renderWaypoints();
            pcio_plan_addToLocationIndex( wp );
        }
    } catch ( e ) { console.error( e ); }
} );

// ── Location index (harbours + plan waypoints for quick-pick) ─────────────────
async function pcio_plan_buildLocationIndex() {
    locationIndex = [];
    const seen = new Set();

    try {
        const harbours = await fetch( PCIO_BP_API + '/harbours' ).then( r => r.json() );
        ( harbours || [] ).forEach( h => {
            const key = h.name.toLowerCase();
            if ( seen.has( key ) ) return;
            seen.add( key );
            locationIndex.push( {
                label:      h.name,
                name:       h.name,
                lat:        parseFloat( h.lat ),
                lon:        parseFloat( h.lon ),
                source:     'harbour',
                harbour_id: parseInt( h.id, 10 ),
            } );
        } );
    } catch ( e ) { console.error( e ); }

    try {
        for ( const p of plans ) {
            const wps = await fetch( PCIO_BP_API + '/planned-trips/' + p.id + '/waypoints' ).then( r => r.json() );
            ( wps || [] ).forEach( w => {
                const key = w.name.toLowerCase();
                if ( seen.has( key ) ) return;
                seen.add( key );
                locationIndex.push( {
                    label:  w.name + ' (' + PCIO_BP_L10N.planSuffix + ')',
                    name:   w.name,
                    lat:    parseFloat( w.lat ),
                    lon:    parseFloat( w.lon ),
                    source: 'plan',
                } );
            } );
        }
    } catch ( e ) { console.error( e ); }

    pcio_plan_populateDatalist();
}

function pcio_plan_populateDatalist() {
    const dl = $id( 'add-wp-location-list' );
    if ( !dl ) return;
    dl.innerHTML = locationIndex
        .map( loc => `<option value="${escHtml( loc.label )}">` )
        .join( '' );
}

function pcio_plan_addToLocationIndex( wp ) {
    const key = wp.name.toLowerCase();
    if ( locationIndex.some( l => l.name.toLowerCase() === key ) ) return;
    locationIndex.push( { label: wp.name + ' (' + PCIO_BP_L10N.planSuffix + ')', name: wp.name, lat: wp.lat, lon: wp.lon, source: 'plan' } );
    pcio_plan_populateDatalist();
}

on( 'add-wp-search', 'input', () => {
    const val   = getVal( 'add-wp-search' );
    const match = locationIndex.find( l => l.label === val || l.name === val );
    if ( !match ) return;
    setVal( 'add-wp-name', match.name );
    setVal( 'add-wp-lat',  match.lat.toFixed( 6 ) );
    setVal( 'add-wp-lon',  match.lon.toFixed( 6 ) );
    $id( 'add-wp-eta' )?.focus();
} );

// ── Start ─────────────────────────────────────────────────────────────────────
init();
