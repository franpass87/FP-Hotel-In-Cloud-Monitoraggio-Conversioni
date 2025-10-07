/* global window */
(function (root) {
  'use strict';
  root.hicPerf = root.hicPerf || {};
  var locale = (document && document.documentElement && document.documentElement.lang) || 'it-IT';
  var n0 = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 });
  var n2 = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var n1p = new Intl.NumberFormat(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  root.hicPerf.fmt = {
    int: function (v) { return n0.format(v || 0); },
    sec2: function (v) { return n2.format(v || 0); },
    pct1: function (v) { return n1p.format(v || 0); }
  };
})(window);


