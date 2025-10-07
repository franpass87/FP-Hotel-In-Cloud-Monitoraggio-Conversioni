/* global jQuery */
jQuery(function ($) {
    const tabButtons = $('.hic-tab');
    const tabPanels = $('.hic-tab-panel');

    function activateTab(tabId, focusButton) {
        if (!tabId) { return; }

        tabButtons.each(function () {
            const $button = $(this);
            const isActive = $button.data('tab') === tabId;
            $button.toggleClass('is-active', isActive);
            $button.attr('aria-selected', isActive ? 'true' : 'false');
            if (isActive && focusButton) { $button.trigger('focus'); }
        });

        tabPanels.each(function () {
            const $panel = $(this);
            const isActive = $panel.data('tab') === tabId;
            $panel.toggleClass('is-active', isActive);
            if (isActive) { $panel.removeAttr('hidden'); } else { $panel.attr('hidden', true); }
        });

        try { window.localStorage.setItem('hicSettingsActiveTab', tabId); } catch (e) {}
    }

    if (!tabButtons.length) { return; }

    let initialTab = tabButtons.filter('.is-active').data('tab') || tabButtons.first().data('tab');
    try {
        const storedTab = window.localStorage.getItem('hicSettingsActiveTab');
        if (storedTab && tabButtons.filter(`[data-tab="${storedTab}"]`).length) {
            initialTab = storedTab;
        }
    } catch (e) {}

    activateTab(initialTab, false);

    tabButtons.on('click', function () { activateTab($(this).data('tab'), false); });

    tabButtons.on('keydown', function (event) {
        const key = event.key;
        if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(key)) { return; }
        event.preventDefault();
        const index = tabButtons.index(this);
        let targetIndex = index;
        if (key === 'ArrowRight' || key === 'ArrowDown') { targetIndex = (index + 1) % tabButtons.length; }
        else if (key === 'ArrowLeft' || key === 'ArrowUp') { targetIndex = (index - 1 + tabButtons.length) % tabButtons.length; }
        else if (key === 'Home') { targetIndex = 0; }
        else if (key === 'End') { targetIndex = tabButtons.length - 1; }
        const $target = tabButtons.eq(targetIndex);
        activateTab($target.data('tab'), true);
    });
});


