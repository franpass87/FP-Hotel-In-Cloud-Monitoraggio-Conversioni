/* Sync SID between parent and iframes */
(function (root) {
  'use strict';
  root.hicFront = root.hicFront || {};
  var cookie = root.hicFront.cookie;
  var sidUtil = root.hicFront.sid;
  function syncOut() {
    try {
      if (root !== root.top) {
        root.parent.postMessage({ type: 'hic_sid_sync', sid: cookie.get('hic_sid') }, '*');
      }
    } catch (e) {}
  }
  function syncIn() {
    root.addEventListener('message', function (event) {
      try {
        if (event && event.data && event.data.type === 'hic_sid_sync' && sidUtil.isValid(event.data.sid)) {
          var currentSid = cookie.get('hic_sid');
          if (currentSid !== event.data.sid) {
            cookie.set('hic_sid', event.data.sid, 90);
          }
        }
      } catch (e) {}
    });
  }
  function observeNewIframes() {
    if (!root.MutationObserver) return;
    try {
      var obs = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          Array.prototype.forEach.call(m.addedNodes || [], function (node) {
            if (node && node.nodeType === 1 && node.tagName === 'IFRAME') {
              setTimeout(function () {
                try {
                  var s = cookie.get('hic_sid');
                  if (s && node.contentWindow) {
                    node.contentWindow.postMessage({ type: 'hic_sid_sync', sid: s }, '*');
                  }
                } catch (e) {}
              }, 500);
            }
          });
        });
      });
      if (document.body) { obs.observe(document.body, { childList: true, subtree: true }); }
    } catch (e) {}
  }
  root.hicFront.iframe = { syncOut: syncOut, syncIn: syncIn, observe: observeNewIframes };
})(window);


