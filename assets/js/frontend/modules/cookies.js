/* Cookie helpers for HIC frontend */
(function (root) {
  'use strict';
  root.hicFront = root.hicFront || {};
  function getCookie(name) {
    try { if (!name || typeof name !== 'string') return null; var m = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)')); return m ? decodeURIComponent(m[2]) : null; } catch (e) { return null; }
  }
  function setCookie(name, val, days) {
    try { if (!name || typeof name !== 'string' || !val) return false; var d = new Date(); d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000)); document.cookie = name + '=' + encodeURIComponent(val) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax'; return true; } catch (e) { return false; }
  }
  root.hicFront.cookie = { get: getCookie, set: setCookie };
})(window);


