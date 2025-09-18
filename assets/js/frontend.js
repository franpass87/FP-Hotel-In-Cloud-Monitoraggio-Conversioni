document.addEventListener('DOMContentLoaded', function(){
  // Error handling wrapper
  function safeExecute(fn, errorContext) {
    try {
      return fn();
    } catch(e) {
      console.warn('HIC Error in ' + errorContext + ':', e);
      return null;
    }
  }

  function uuidv4(){
    try {
      if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
        return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
          (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
        );
      } else {
        // Fallback for environments without crypto
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
          var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
          return v.toString(16);
        });
      }
    } catch(e) {
      console.warn('HIC: UUID generation failed:', e);
      // Ultra-simple fallback
      return 'hic_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
  }
  
  function getCookie(name){
    try {
      if (!name || typeof name !== 'string') return null;
      var m = document.cookie.match(new RegExp('(^| )'+name+'=([^;]+)'));
      return m ? decodeURIComponent(m[2]) : null;
    } catch(e) {
      console.warn('HIC: Failed to get cookie ' + name + ':', e);
      return null;
    }
  }
  
  function setCookie(name, val, days){
    try {
      if (!name || typeof name !== 'string' || !val) return false;
      var d = new Date(); 
      d.setTime(d.getTime() + (days*24*60*60*1000));
      document.cookie = name + "=" + encodeURIComponent(val) + "; expires=" + d.toUTCString() + "; path=/; SameSite=Lax";
      return true;
    } catch(e) {
      console.warn('HIC: Failed to set cookie ' + name + ':', e);
      return false;
    }
  }

  // Assicura un SID anche per traffico non-ads
  var sid = getCookie('hic_sid');
  if (!sid) {
    sid = uuidv4();
    if (sid && !setCookie('hic_sid', sid, 90)) {
      console.warn('HIC: Failed to set SID cookie');
    }
  }

  if (sid) {
    fetchQueuedGtmEvents(sid);
  }

  // Capture gclid/fbclid from URL and persist in cookies for redirect preservation
  safeExecute(function() {
    var params = new URLSearchParams(window.location.search);
    ['gclid', 'fbclid'].forEach(function(key) {
      var val = params.get(key);
      if (val && isValidTrackingId(val)) {
        setCookie('hic_' + key, val, 90);
      }
    });
  }, 'captureTrackingParams');

  // Helper function to validate SID format (enhanced validation)
  function isValidSid(sid) {
    try {
      return sid &&
             typeof sid === 'string' &&
             sid.length > 8 &&
             sid.length < 256 &&
             /^[a-zA-Z0-9_-]+$/.test(sid); // Only allow safe characters
    } catch(e) {
      console.warn('HIC: SID validation error:', e);
      return false;
    }
  }

  // Generic validation for tracking IDs like gclid/fbclid
  function isValidTrackingId(val) {
    try {
      return val &&
             typeof val === 'string' &&
             val.length > 0 &&
             val.length < 256 &&
             /^[A-Za-z0-9_-]+$/.test(val);
    } catch(e) {
      console.warn('HIC: tracking ID validation error:', e);
      return false;
    }
  }

  // Helper function to validate URL
  function isValidUrl(url) {
    try {
      new URL(url);
      return true;
    } catch(e) {
      return false;
    }
  }

  var gtmEventsRequestedForSid = null;

  function fetchQueuedGtmEvents(currentSid) {
    safeExecute(function() {
      if (!currentSid || !isValidSid(currentSid)) {
        return;
      }

      if (!window.hicFrontend || !window.hicFrontend.gtmEnabled) {
        return;
      }

      var endpoint = window.hicFrontend.gtmEventsEndpoint;
      if (!endpoint || typeof endpoint !== 'string') {
        return;
      }

      if (gtmEventsRequestedForSid === currentSid) {
        return;
      }

      if (!window.fetch) {
        console.warn('HIC: fetch API non disponibile per eventi GTM');
        return;
      }

      if (!isValidUrl(endpoint)) {
        console.warn('HIC: endpoint eventi GTM non valido:', endpoint);
        return;
      }

      var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'sid=' + encodeURIComponent(currentSid);
      gtmEventsRequestedForSid = currentSid;

      fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json'
        }
      })
        .then(function(response) {
          if (!response || !response.ok) {
            throw new Error('HTTP ' + (response ? response.status : '0'));
          }
          return response.json();
        })
        .then(function(payload) {
          if (!payload || !Array.isArray(payload.events) || payload.events.length === 0) {
            return;
          }

          window.dataLayer = window.dataLayer || [];
          payload.events.forEach(function(event) {
            if (event && typeof event === 'object') {
              window.dataLayer.push(event);
            }
          });
        })
        .catch(function(err) {
          console.warn('HIC: errore nel recupero degli eventi GTM:', err);
          gtmEventsRequestedForSid = null;
        });
    }, 'fetchQueuedGtmEvents');
  }

  // Helper function to add SID and tracking IDs to a link on click
  function addSidToLink(link) {
    if (!link || typeof link.addEventListener !== 'function') {
      console.warn('HIC: Invalid link element provided to addSidToLink');
      return;
    }

    link.addEventListener('click', function(){
      safeExecute(function() {
        var s = getCookie('hic_sid');
        var g = getCookie('hic_gclid');
        var f = getCookie('hic_fbclid');
        if (s && isValidSid(s)) {
          if (!link.href || !isValidUrl(link.href)) {
            console.warn('HIC: Invalid link URL:', link.href);
            return;
          }

          var url = new URL(link.href);
          url.searchParams.set('sid', s);
          if (g && isValidTrackingId(g)) url.searchParams.set('gclid', g);
          if (f && isValidTrackingId(f)) url.searchParams.set('fbclid', f);
          link.href = url.toString();
        }
      }, 'addSidToLink');
    });
  }

  // Funzione per appendere SID e tracking IDs ai link
  function appendSidToLinks() {
    safeExecute(function() {
      var links = document.querySelectorAll('a.js-book, a[href*="booking.hotelincloud.com"]');
      if (links && links.length > 0) {
        links.forEach(function(link) {
          if (link) addSidToLink(link);
        });
      }
    }, 'appendSidToLinks');
  }

  // Appendi sid e tracking IDs ai link nel documento principale
  appendSidToLinks();

  // Supporto per iframe - monitora per nuovi link aggiunti dinamicamente
  if (window.MutationObserver) {
    safeExecute(function() {
      var mutationThrottleTimer;
      var observer = new MutationObserver(function(mutations) {
        // Throttle mutations to avoid performance issues
        if (mutationThrottleTimer) return;
        mutationThrottleTimer = setTimeout(function() {
          mutationThrottleTimer = null;
          
          safeExecute(function() {
            mutations.forEach(function(mutation) {
              if (mutation.type === 'childList' && mutation.addedNodes) {
                mutation.addedNodes.forEach(function(node) {
                  if (node.nodeType === 1) { // Element node
                    // Controlla se il nodo aggiunto contiene link booking
                    var newLinks = node.querySelectorAll ? node.querySelectorAll('a.js-book, a[href*="booking.hotelincloud.com"]') : [];
                    if (newLinks.length > 0) {
                      newLinks.forEach(function(link) {
                        if (link) addSidToLink(link);
                      });
                    }
                  }
                });
              }
            });
          }, 'mutationObserver');
        }, 100); // 100ms throttle
      });
      
      if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
      }
    }, 'mutationObserver setup');
  }

  // Supporto per iframe - comunica con la finestra padre se siamo in un iframe
  if (window !== window.top) {
    safeExecute(function() {
      window.parent.postMessage({
        type: 'hic_sid_sync',
        sid: sid
      }, '*');
    }, 'iframe parent communication');
  }

  // Ascolta messaggi da iframe - supporta sia parent->iframe che iframe->parent
  window.addEventListener('message', function(event) {
    safeExecute(function() {
      if (event.data && 
          event.data.type === 'hic_sid_sync' && 
          event.data.sid && 
          isValidSid(event.data.sid)) {
        var currentSid = getCookie('hic_sid');
        if (currentSid !== event.data.sid) {
          if (setCookie('hic_sid', event.data.sid, 90)) {
            // Re-applica SID ai link esistenti con il nuovo SID
            appendSidToLinks();
            fetchQueuedGtmEvents(event.data.sid);
          }
        }
      }
    }, 'message listener');
  });

  // Monitora per iframe caricati dinamicamente e invia loro il SID
  if (window.MutationObserver) {
    safeExecute(function() {
      var iframeObserver = new MutationObserver(function(mutations) {
        safeExecute(function() {
          mutations.forEach(function(mutation) {
            if (mutation.type === 'childList' && mutation.addedNodes) {
              mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1 && node.tagName === 'IFRAME') {
                  // Invia SID ai nuovi iframe dopo un breve delay per assicurarsi che siano carichi
                  setTimeout(function() {
                    safeExecute(function() {
                      var currentSid = getCookie('hic_sid');
                      if (currentSid && node.contentWindow) {
                        node.contentWindow.postMessage({
                          type: 'hic_sid_sync',
                          sid: currentSid
                        }, '*');
                      }
                    }, 'iframe postMessage');
                  }, 500);
                }
              });
            }
          });
        }, 'iframe mutation observer');
      });
      
      if (document.body) {
        iframeObserver.observe(document.body, { childList: true, subtree: true });
      }
    }, 'iframe observer setup');
  }
});