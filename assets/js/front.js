(function () {
    var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
    var __ = i18n && typeof i18n.__ === 'function' ? i18n.__ : function (text) { return text; };

    function parsePricing(element) {
        var raw = element.getAttribute('data-pricing') || '[]';
        try {
            var data = JSON.parse(raw);
            return Array.isArray(data) ? data : [];
        } catch (e) {
            return [];
        }
    }

    function updateWidget(widget) {
        var summary = widget.querySelector('[data-role="fp-exp-summary"]');
        if (!summary) {
            return;
        }

        var version = widget.getAttribute('data-pricing-version') || '';
        if (summary.dataset.renderedVersion === version) {
            return;
        }

        var pricing = parsePricing(widget);
        if (!pricing.length) {
            summary.textContent = __('Contattaci per un preventivo', 'hotel-in-cloud');
            summary.dataset.renderedVersion = version;
            return;
        }

        var firstPrice = '';
        for (var i = 0; i < pricing.length; i++) {
            if (pricing[i] && pricing[i].price) {
                firstPrice = pricing[i].price;
                break;
            }
        }

        if (!firstPrice) {
            summary.textContent = __('Contattaci per un preventivo', 'hotel-in-cloud');
            summary.dataset.renderedVersion = version;
            return;
        }

        summary.textContent = __('A partire da', 'hotel-in-cloud') + ' ' + firstPrice;
        summary.dataset.renderedVersion = version;
    }

    function init() {
        var widgets = document.querySelectorAll('.fp-exp-widget');
        for (var i = 0; i < widgets.length; i++) {
            updateWidget(widgets[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
