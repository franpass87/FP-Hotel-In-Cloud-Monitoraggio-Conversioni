/* Append SID and tracking IDs to booking links */
(function (root) {
  'use strict';
  root.hicFront = root.hicFront || {};
  var cookie = root.hicFront.cookie;
  var sidUtil = root.hicFront.sid;
  function isValidId(v){ try { return v && typeof v==='string' && v.length>0 && v.length<256 && /^[A-Za-z0-9_-]+$/.test(v); } catch(e){ return false; } }
  function isValidUrl(url) { try { new URL(url); return true; } catch(e){ return false; } }
  function addSidToLink(link){
    if (!link || typeof link.addEventListener !== 'function') return;
    link.addEventListener('click', function(){
      var s = cookie.get('hic_sid'); if (!sidUtil.isValid(s)) return;
      if (!link.href || !isValidUrl(link.href)) return;
      var url = new URL(link.href);
      url.searchParams.set('sid', s);
      var g = cookie.get('hic_gclid'); var f = cookie.get('hic_fbclid');
      if (isValidId(g)) url.searchParams.set('gclid', g);
      if (isValidId(f)) url.searchParams.set('fbclid', f);
      link.href = url.toString();
    });
  }
  function augment(){
    var links = document.querySelectorAll('a.js-book, a[href*="booking.hotelincloud.com"]');
    if (links && links.length) Array.prototype.forEach.call(links, addSidToLink);
  }
  root.hicFront.links = { augment: augment };
})(window);


