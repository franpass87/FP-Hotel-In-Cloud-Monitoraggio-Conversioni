/* global jQuery */
(function ($, root) {
  'use strict';
  root.hicDiag = root.hicDiag || {};

  function ensureContainer() {
    var $c = $('#hic-toast-container');
    if ($c.length === 0) {
      $('body').append('<div id="hic-toast-container" class="hic-toast-container"></div>');
      $c = $('#hic-toast-container');
    }
    return $c;
  }

  function showToast(message, type, duration) {
    type = type || 'info';
    duration = duration || 5000;
    var icons = { success: '✓', error: '✗', warning: '⚠', info: 'ℹ' };
    var $container = ensureContainer();
    var $toast = $('<div class="hic-toast ' + type + '"><div class="hic-toast-content"><span class="hic-toast-icon">' + (icons[type] || icons.info) + '</span><span class="hic-toast-message"></span><button class="hic-toast-close" aria-label="Chiudi">&times;</button></div></div>');
    $toast.find('.hic-toast-message').text(message || '');
    $container.append($toast);
    setTimeout(function () { $toast.addClass('show'); }, 50);
    var timer = setTimeout(function () { $toast.removeClass('show'); setTimeout(function(){ $toast.remove(); }, 300); }, duration);
    $toast.find('.hic-toast-close').on('click', function () { clearTimeout(timer); $toast.removeClass('show'); setTimeout(function(){ $toast.remove(); }, 300); });
  }

  root.hicDiag.ui = root.hicDiag.ui || {};
  root.hicDiag.ui.toast = showToast;
})(jQuery, window);


