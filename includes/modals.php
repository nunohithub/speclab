<!-- Modal genérico reutilizável (alert, confirm) -->
<div id="appModalOverlay" class="hidden" style="display:flex; position:fixed; top:0; left:0; width:100%; height:100%; background:var(--color-overlay, rgba(0,0,0,0.5)); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:var(--color-bg, #ffffff); border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3); max-width:440px; width:90%; overflow:hidden; border-top:3px solid var(--color-primary, #2563eb);">
        <div style="padding:20px 24px 0; display:flex; align-items:center; justify-content:space-between;">
            <h3 id="appModalTitle" style="margin:0; font-size:16px; color:var(--color-text, #111827);"></h3>
            <button onclick="appModalClose(false)" style="background:none; border:none; font-size:22px; cursor:pointer; color:var(--color-text-secondary, #999); padding:0; line-height:1;">&times;</button>
        </div>
        <div id="appModalBody" style="padding:16px 24px; font-size:14px; color:var(--color-text-secondary, #374151); line-height:1.5;"></div>
        <div id="appModalFooter" style="padding:12px 24px 20px; display:flex; justify-content:flex-end; gap:8px;"></div>
    </div>
</div>
<script>
var _appModalCallback = null;
function appModalClose(result) {
    document.getElementById('appModalOverlay').classList.add('hidden');
    if (_appModalCallback) { var cb = _appModalCallback; _appModalCallback = null; cb(result); }
}
function appAlert(msg, title) {
    document.getElementById('appModalTitle').textContent = title || 'Aviso';
    document.getElementById('appModalBody').innerHTML = msg;
    document.getElementById('appModalFooter').innerHTML = '<button class="btn btn-primary btn-sm" onclick="appModalClose(true)">OK</button>';
    document.getElementById('appModalOverlay').classList.remove('hidden');
    _appModalCallback = null;
}
function appConfirm(msg, onConfirm, title) {
    document.getElementById('appModalTitle').textContent = title || 'Confirmar';
    document.getElementById('appModalBody').innerHTML = msg;
    document.getElementById('appModalFooter').innerHTML =
        '<button class="btn btn-secondary btn-sm" onclick="appModalClose(false)">Cancelar</button>' +
        '<button class="btn btn-primary btn-sm" onclick="appModalClose(true)">Confirmar</button>';
    document.getElementById('appModalOverlay').classList.remove('hidden');
    _appModalCallback = function(ok) { if (ok && onConfirm) onConfirm(); };
}
function appConfirmDanger(msg, onConfirm, title) {
    document.getElementById('appModalTitle').textContent = title || 'Confirmar';
    document.getElementById('appModalBody').innerHTML = msg;
    document.getElementById('appModalFooter').innerHTML =
        '<button class="btn btn-secondary btn-sm" onclick="appModalClose(false)">Cancelar</button>' +
        '<button class="btn btn-danger btn-sm" onclick="appModalClose(true)">Eliminar</button>';
    document.getElementById('appModalOverlay').classList.remove('hidden');
    _appModalCallback = function(ok) { if (ok && onConfirm) onConfirm(); };
}
// Fechar com Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('appModalOverlay').classList.contains('hidden')) {
        appModalClose(false);
    }
});
</script>
