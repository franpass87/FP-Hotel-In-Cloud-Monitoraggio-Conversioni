document.addEventListener('DOMContentLoaded', function(){
  function uuidv4(){
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
      (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
  }
  function getCookie(name){
    var m = document.cookie.match(new RegExp('(^| )'+name+'=([^;]+)'));
    return m ? m[2] : null;
  }
  function setCookie(name, val, days){
    var d = new Date(); d.setTime(d.getTime() + (days*24*60*60*1000));
    document.cookie = name + "=" + val + "; expires=" + d.toUTCString() + "; path=/; SameSite=Lax";
  }

  // Assicura un SID anche per traffico non-ads
  var sid = getCookie('hic_sid');
  if (!sid) { sid = uuidv4(); setCookie('hic_sid', sid, 90); }

  // Helper function to validate SID format (basic validation)
  function isValidSid(sid) {
    return sid && typeof sid === 'string' && sid.length > 8 && sid.length < 256;
  }

  // Helper function to add SID to a link on click
  function addSidToLink(link) {
    link.addEventListener('click', function(){
      var s = getCookie('hic_sid');
      if (s && isValidSid(s)) {
        try {
          var url = new URL(link.href);
          url.searchParams.set('sid', s);
          link.href = url.toString();
        } catch(e){
          // Log error for debugging
          console.warn('HIC: Failed to add SID to link', link.href, e);
        }
      }
    });
  }

  // Funzione per appendere SID ai link
  function appendSidToLinks() {
    var links = document.querySelectorAll('a.js-book, a[href*="booking.hotelincloud.com"]');
    links.forEach(addSidToLink);
  }

  // Appendi sid ai link nel documento principale
  appendSidToLinks();

  // Supporto per iframe - monitora per nuovi link aggiunti dinamicamente
  if (window.MutationObserver) {
    var mutationThrottleTimer;
    var observer = new MutationObserver(function(mutations) {
      // Throttle mutations to avoid performance issues
      if (mutationThrottleTimer) return;
      mutationThrottleTimer = setTimeout(function() {
        mutationThrottleTimer = null;
        
        mutations.forEach(function(mutation) {
          if (mutation.type === 'childList') {
            mutation.addedNodes.forEach(function(node) {
              if (node.nodeType === 1) { // Element node
                // Controlla se il nodo aggiunto contiene link booking
                var newLinks = node.querySelectorAll ? node.querySelectorAll('a.js-book, a[href*="booking.hotelincloud.com"]') : [];
                if (newLinks.length > 0) {
                  newLinks.forEach(addSidToLink);
                }
              }
            });
          }
        });
      }, 100); // 100ms throttle
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  // Supporto per iframe - comunica con la finestra padre se siamo in un iframe
  if (window !== window.top) {
    try {
      window.parent.postMessage({
        type: 'hic_sid_sync',
        sid: sid
      }, '*');
    } catch(e) {
      // Cross-origin, non possiamo comunicare
    }
  }

  // Ascolta messaggi da iframe
  window.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'hic_sid_sync' && event.data.sid && isValidSid(event.data.sid)) {
      setCookie('hic_sid', event.data.sid, 90);
    }
  });
});