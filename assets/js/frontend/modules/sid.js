/* SID utilities for HIC frontend */
(function (root) {
  'use strict';
  root.hicFront = root.hicFront || {};
  var cookie = root.hicFront.cookie;
  function uuidv4() {
    try { if (typeof crypto !== 'undefined' && crypto.getRandomValues) { return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, function(c){ return (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16); }); } } catch(e) {}
    return 'hic_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
  }
  function isValidSid(s) { try { return s && typeof s==='string' && s.length>8 && s.length<256 && /^[a-zA-Z0-9_-]+$/.test(s); } catch(e){ return false; } }
  function ensureSid() {
    var sid = cookie && cookie.get ? cookie.get('hic_sid') : null;
    if (!sid) { sid = uuidv4(); cookie && cookie.set && cookie.set('hic_sid', sid, 90); }
    return sid;
  }
  root.hicFront.sid = { ensure: ensureSid, isValid: isValidSid };
})(window);


