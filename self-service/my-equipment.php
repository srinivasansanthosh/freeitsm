<?php
/**
 * Self-Service Portal — My equipment.
 *
 * The kit assigned to the signed-in person, so they can read a serial number off
 * the screen instead of raising a ticket to ask what it is.
 *
 * ⚠️ NOTHING HERE DECIDES WHAT SOMEBODY MAY SEE. It draws what
 * api/self-service/get_my_assets.php returns, and that endpoint takes the user
 * from the session, has no id parameter and offers no search — a portal user can
 * only ever reach equipment assigned to them. That endpoint predates this page
 * (it feeds the asset picker on a new ticket); this is a second, read-only view
 * of the same answer, so the two cannot disagree about who has what.
 *
 * 🔑 Reached only when an administrator has turned it on (System → Self-service
 * portal). Off by default, so an install that never asked for it does not get a
 * new tab. The nav hides the link and this page re-checks — a hidden link is not
 * a permission.
 */
/**
 * 🔴 THE GUARD RUNS BEFORE THE HEADER, AND THAT ORDER IS THE WHOLE POINT.
 *
 * It was originally after `require header.php`, which reads as the natural
 * place — every other page does its work there. But the header has already
 * echoed the doctype by then, so `header('Location: …')` had nothing to send:
 * the redirect silently did nothing and the page returned **200 with the full
 * contents** to anybody who typed the URL, whatever the setting said. A direct
 * request is what found it; the nav was hiding the link correctly all along,
 * which is exactly how a guard like this looks fine from the outside.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/self_service_settings.php';

if (empty($_SESSION['ss_user_id'])) {
    header('Location: login.php');
    exit;
}
// A hidden nav link is not a permission.
if (!selfServicePortalSettings(connectToDatabase())['show_my_assets']) {
    header('Location: index.php');
    exit;
}

$pageTitleKey = 'self-service.equipment.title';
$activeNav    = 'equipment';
$translationNamespaces = ['common', 'self-service'];

$pageStyles = <<<'CSS'
.eq-wrap { width: 100%; box-sizing: border-box; padding: 24px 28px 48px; }
.eq-wrap h1 { font-size: 22px; font-weight: 600; margin: 0 0 4px; color: var(--text, #333); }
.eq-sub { color: var(--text-muted, #666); font-size: 14px; margin: 0 0 22px; max-width: 640px; line-height: 1.55; }

.eq-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }

.eq-card {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    padding: 16px 18px;
}
.eq-card h2 { font-size: 15px; font-weight: 600; margin: 0 0 2px; color: var(--text, #333); word-break: break-word; }
.eq-type { font-size: 12px; color: var(--text-muted, #666); margin: 0 0 12px; }

.eq-facts { display: grid; grid-template-columns: auto 1fr; gap: 4px 14px; font-size: 13px; }
.eq-facts dt { color: var(--text-muted, #666); }
.eq-facts dd { margin: 0; color: var(--text, #333); word-break: break-word; }
/* A serial gets typed into a form or read down a phone, so it wants to be
   unambiguous rather than pretty: 0 against O and 1 against l is the whole job. */
.eq-facts dd.mono { font-family: Consolas, "SF Mono", Menlo, monospace; font-size: 12.5px; }

.eq-movements{margin-top:30px}.eq-movements h2{font-size:19px;margin:0 0 6px}.eq-movement-card{background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:8px;padding:16px 18px;margin:12px 0}.eq-movement-card h3{font-size:15px;margin:0 0 10px}.eq-movement-card p{margin:7px 0}.eq-movement-card textarea{display:block;width:100%;min-height:65px;box-sizing:border-box;margin:6px 0 10px;padding:9px;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:6px;font:inherit}.eq-movement-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.eq-movement-issue{background:var(--surface-2,var(--surface));padding:10px;border-radius:6px}.eq-empty {
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px; padding: 28px; text-align: center;
    color: var(--text-muted, #666); font-size: 14px; line-height: 1.6;
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="eq-wrap">
        <h1><?php echo htmlspecialchars(t('self-service.equipment.title')); ?></h1>
        <p class="eq-sub"><?php echo htmlspecialchars(t('self-service.equipment.subtitle')); ?></p>
        <?php /* ⚠️ DELIBERATELY EMPTY, for the reason training.php records: a
                 "loading" box is on screen for a couple of hundred milliseconds,
                 which is long enough to see as a flicker and not long enough to
                 read, so it reads as a fault. Nothing is drawn until there is
                 something to say, and "you have no equipment" is only said once
                 it is known to be true. */ ?>
        <div id="eqList"></div>
        <section id="asset-movements" class="eq-movements" aria-labelledby="assetMovementsTitle" hidden>
            <h2 id="assetMovementsTitle"><?php echo htmlspecialchars(t('self-service.equipment.movements_title')); ?></h2>
            <p class="eq-sub"><?php echo htmlspecialchars(t('self-service.equipment.movements_subtitle')); ?></p>
            <div id="eqMovementList" aria-live="polite"></div>
        </section>
    </div>
<?php
$pageScripts = <<<'JS'
const eqEsc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
const eqT = key => window.t('self-service.equipment.' + key);
let eqHasAssets = false;
let eqHasMovements = false;

async function eqLoad() {
    try {
        const res  = await fetch('../api/self-service/get_my_assets.php', { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) { eqEmpty(window.t('self-service.equipment.load_failed')); return; }
        eqRender(data.assets || []);
    } catch (e) {
        eqEmpty(window.t('self-service.equipment.load_failed'));
    }
}

function eqEmpty(msg) {
    document.getElementById('eqList').innerHTML = '<div class="eq-empty">' + eqEsc(msg) + '</div>';
}

function eqRender(rows) {
    eqHasAssets = rows.length > 0;
    if (!rows.length) { document.getElementById('eqList').innerHTML = ''; return; }

    document.getElementById('eqList').innerHTML =
        '<div class="eq-grid">' + rows.map(function (a) {
            // Named in the order somebody would actually recognise the thing:
            // what it is called on the network, then the label stuck to it, then
            // the make and model. Never a bare id.
            const name = a.hostname || a.asset_tag
                       || [a.manufacturer, a.model].filter(Boolean).join(' ')
                       || window.t('self-service.equipment.unnamed');

            const facts = [];
            if (a.asset_tag)   facts.push([window.t('self-service.equipment.asset_tag'), a.asset_tag, false]);
            if (a.service_tag) facts.push([window.t('self-service.equipment.serial'),    a.service_tag, true]);
            const make = [a.manufacturer, a.model].filter(Boolean).join(' ');
            if (make)          facts.push([window.t('self-service.equipment.make'),      make, false]);

            return '<article class="eq-card">' +
                '<h2>' + eqEsc(name) + '</h2>' +
                (a.type_name ? '<p class="eq-type">' + eqEsc(a.type_name) + '</p>' : '') +
                (facts.length
                    ? '<dl class="eq-facts">' + facts.map(function (f) {
                          return '<dt>' + eqEsc(f[0]) + '</dt><dd' + (f[2] ? ' class="mono"' : '') + '>' + eqEsc(f[1]) + '</dd>';
                      }).join('') + '</dl>'
                    : '') +
            '</article>';
        }).join('') + '</div>';
}

async function eqLoadMovements(){
    const root=document.getElementById('eqMovementList');
    try{
        const res=await fetch('../api/self-service/asset_deliveries.php',{credentials:'same-origin'}),data=await res.json();
        if(!res.ok||!data.success)throw new Error(data.error||eqT('movements_load_failed'));
        const rows=data.shipments||[];
        const section=document.getElementById('asset-movements');
        if(!rows.length){section.hidden=true;root.innerHTML='';eqHasMovements=false;if(!eqHasAssets)eqEmpty(window.t('self-service.equipment.none'));return;}
        eqHasMovements=true;section.hidden=false;document.getElementById('eqList').innerHTML = eqHasAssets ? document.getElementById('eqList').innerHTML : '';
        root.innerHTML=rows.map(function(s){
            const isReturn=s.movement_type==='inbound_return';
            const title=isReturn?eqT('return_title'):eqT('delivery_title');
            const tracking=[s.carrier_name,s.tracking_number].filter(Boolean).join(' · ')||eqT('not_yet_available');
            const collection=isReturn?(s.collection_method==='home_pickup'?eqT('home_pickup'):(s.collection_method==='drop_off'?eqT('drop_off'):eqT('not_yet_arranged'))):'';
            const place=isReturn?(s.collection_method==='home_pickup'?s.pickup_address:(s.collection_method==='drop_off'?s.dropoff_location:'')):'';
            const schedule=isReturn&&s.pickup_scheduled_at?'<p><strong>'+eqEsc(eqT('scheduled'))+':</strong> '+eqEsc(s.pickup_scheduled_at)+'</p>':'';
            const instructions=isReturn&&s.return_instructions?'<p><strong>'+eqEsc(eqT('instructions'))+':</strong> '+eqEsc(s.return_instructions)+'</p>':'';
            const returnArranged=(s.collection_method==='home_pickup'&&!!s.pickup_address)||(s.collection_method==='drop_off'&&!!s.dropoff_location);
            const ack=isReturn?(s.return_acknowledged_at?'<p>'+eqEsc(eqT('return_acknowledged_on'))+' '+eqEsc(s.return_acknowledged_at)+'.</p>':(s.shipment_status!=='delivered'&&s.shipment_status!=='cancelled'&&returnArranged?'<button class="btn btn-secondary" data-return-ack="'+Number(s.shipment_id)+'">' + eqEsc(eqT('acknowledge_return')) + '</button>':(s.shipment_status!=='delivered'&&s.shipment_status!=='cancelled'?'<p>'+eqEsc(eqT('return_preparing'))+'</p>':''))):(s.recipient_acknowledged_at?'<p>'+eqEsc(eqT('receipt_acknowledged_on'))+' '+eqEsc(s.recipient_acknowledged_at)+'.</p>':(s.shipment_status==='delivered'?'<button class="btn btn-primary" data-delivery-ack="'+Number(s.shipment_id)+'">' + eqEsc(eqT('acknowledge_receipt')) + '</button>':''));
            const issueValue=isReturn?s.return_issue:s.recipient_issue;
            const issue=issueValue?'<p class="eq-movement-issue"><strong>'+eqEsc(eqT('issue_reported'))+':</strong> '+eqEsc(issueValue)+'</p>':'';
            const canIssue=['pending','dispatched','in_transit','delivered','exception'].includes(s.shipment_status);
            const issueForm=canIssue?'<form data-movement-issue="'+Number(s.shipment_id)+'" data-return="'+(isReturn?'1':'0')+'"><label><strong>'+eqEsc(eqT(isReturn?'report_return_issue':'report_delivery_issue'))+'</strong><textarea name="issue" maxlength="5000" required placeholder="'+eqEsc(eqT('issue_placeholder'))+'"></textarea></label><button class="btn btn-secondary" type="submit">'+eqEsc(eqT('submit_issue'))+'</button></form>':'';
            return '<article class="eq-movement-card"><h3>'+eqEsc(title)+' #'+Number(s.shipment_id)+'</h3><p><strong>'+eqEsc(eqT('assets'))+':</strong> '+eqEsc(s.assets||'—')+'</p><p><strong>'+eqEsc(eqT('status'))+':</strong> '+eqEsc(s.shipment_status)+'</p><p><strong>'+eqEsc(eqT('purpose'))+':</strong> '+eqEsc(s.purpose_name||'—')+'</p><p><strong>'+eqEsc(eqT('carrier_tracking'))+':</strong> '+eqEsc(tracking)+'</p>'+(s.tracking_url?'<p><a href="'+escapeHtml(s.tracking_url)+'" target="_blank" rel="noopener noreferrer">'+eqEsc(eqT('track_shipment'))+'</a></p>':'')+(isReturn?'<p><strong>'+eqEsc(eqT('collection_method'))+':</strong> '+eqEsc(collection)+'</p>'+(place?'<p><strong>'+eqEsc(eqT('location_address'))+':</strong> '+eqEsc(place)+'</p>':'')+schedule+instructions:'')+ack+issue+issueForm+'</article>';
        }).join('');
        root.querySelectorAll('[data-delivery-ack]').forEach(b=>b.addEventListener('click',()=>eqMovementAction({action:'acknowledge',shipment_id:Number(b.dataset.deliveryAck)})));
        root.querySelectorAll('[data-return-ack]').forEach(b=>b.addEventListener('click',()=>eqMovementAction({action:'return_acknowledge',shipment_id:Number(b.dataset.returnAck)})));
        root.querySelectorAll('[data-movement-issue]').forEach(f=>f.addEventListener('submit',async e=>{e.preventDefault();const issue=new FormData(f).get('issue');await eqMovementAction({action:f.dataset.return==='1'?'report_return_issue':'report_issue',shipment_id:Number(f.dataset.movementIssue),issue});}));
    }catch(e){root.innerHTML='<div class="eq-empty">'+eqEsc(e.message||eqT('movements_load_failed'))+'</div>';if(!eqHasAssets)document.getElementById('eqList').innerHTML='';}
}
async function eqMovementAction(payload){try{const res=await fetch('../api/self-service/asset_deliveries.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}),data=await res.json();if(!res.ok||!data.success)throw new Error(data.error||eqT('action_failed'));await eqLoadMovements();}catch(e){alert(e.message||eqT('action_failed'));}}
eqLoad();
eqLoadMovements();
JS;

require_once __DIR__ . '/includes/footer.php';
