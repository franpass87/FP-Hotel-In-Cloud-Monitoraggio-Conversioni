/* global jQuery, hicDiagnostics */
(function ($, root) {
  'use strict';
  root.hicDiag = root.hicDiag || {};

  function initLogStream() {
    if (!hicDiagnostics || !hicDiagnostics.can_view_logs) { return; }
    var $container = $('#hic-logs-container');
    var $status = $('#hic-log-stream-status');
    if ($container.length === 0) { return; }
    var refreshInterval = parseInt(hicDiagnostics.log_refresh_interval, 10) || 10000;
    var fetchLimit = parseInt($container.data('fetch-limit'), 10) || 40;
    var displayLimit = parseInt($container.data('display-limit'), 10) || 8;
    var emptyMessage = $container.data('empty-message') || 'Nessun log recente disponibile.';
    var lastHash = null; var timerId = null; var inflight = false; var visible = !document.hidden;

    function setStatus(state, message) {
      if ($status.length === 0) { return; }
      var states = { active: 'Aggiornamento automatico attivo', idle: 'In attesa di nuovi eventi...', paused: 'Aggiornamento in pausa (scheda inattiva)', error: "Errore durante l'aggiornamento dei log" };
      var text = message || states[state] || states.active;
      $status.removeClass('active idle paused error').addClass(state);
      $status.find('.hic-log-stream-text').text(text);
    }
    function renderLogs(logs) {
      if (!Array.isArray(logs) || logs.length === 0) { $container.html('<p class="hic-no-logs">' + emptyMessage + '</p>'); return; }
      var html = ''; var displayLogs = logs.slice(0, displayLimit);
      function esc(v){ return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
      displayLogs.forEach(function (e) { html += '<div class="hic-log-entry">[' + esc(e.timestamp||'') + '] [' + esc(e.level||'') + '] [' + esc(e.memory||'') + '] ' + esc(e.message||'') + '</div>'; });
      if (logs.length > displayLimit) { html += '<div class="hic-log-more">... e altri ' + (logs.length - displayLimit) + ' eventi</div>'; }
      $container.html(html);
    }
    function schedule(delay){ if (timerId) { clearTimeout(timerId); } timerId = setTimeout(fetch, delay); }
    function fetch(){ if (!visible || inflight) { schedule(refreshInterval); return; } inflight = true; $container.addClass('hic-logs-loading');
      $.post((typeof window.ajaxurl!=='undefined'?window.ajaxurl:hicDiagnostics.ajax_url), { action:'hic_get_recent_logs', nonce: hicDiagnostics.diagnostics_nonce, limit: fetchLimit }, function(resp){
        if (resp && resp.success && resp.data) {
          var logs = resp.data.logs || []; var h = JSON.stringify(logs);
          if (h !== lastHash) { renderLogs(logs); lastHash = h; }
          setStatus(logs.length===0?'idle':'active');
        } else if (resp && resp.data && resp.data.requires_permission) { setStatus('error', resp.data.message); return; } else { setStatus('error', (resp && resp.data && resp.data.message) || null); }
      }, 'json').fail(function(){ setStatus('error'); }).always(function(){ inflight=false; $container.removeClass('hic-logs-loading'); schedule(refreshInterval); }); }
    document.addEventListener('visibilitychange', function(){ visible = !document.hidden; setStatus(visible ? (lastHash?'active':'idle') : 'paused'); if (visible) { schedule(200); } });
    if ($container.find('.hic-log-entry').length>0) { setStatus('active'); } else { setStatus('idle'); }
    schedule(1000);
  }

  root.hicDiag.features = root.hicDiag.features || {};
  root.hicDiag.features.logStream = { init: initLogStream };
})(jQuery, window);


