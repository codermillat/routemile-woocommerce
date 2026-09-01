(function () {
    'use strict';

    if (typeof window.routew_toggle_delivery_status !== 'undefined') {
        return;
    }

    window.routew_toggle_delivery_status = function (element) {
        if (typeof ajaxurl === 'undefined' || typeof routew_admin_params === 'undefined') {
            return;
        }

        var i18n = routew_admin_params.i18n || {};
        element.textContent = i18n.updating || 'Updating...';

        jQuery.post(ajaxurl, {
            action: 'routew_toggle_delivery_status',
            nonce: routew_admin_params.nonce
        }, function (response) {
            if (response.success && response.data) {
                element.textContent = response.data.label || '';
            } else {
                element.textContent = i18n.error || 'Error!';
            }
        }).fail(function () {
            element.textContent = i18n.error || 'Error!';
        });
    };
})();

/**
 * Map-provider settings: show only the fields the selected provider
 * actually uses. Progressive enhancement — with JS off every field stays
 * visible and usable.
 *
 * Deliberately a separate IIFE: the block above returns early when its
 * global already exists, which would otherwise skip this too.
 *
 * @since 1.3.0
 */
(function () {
    'use strict';

    function initProviderToggle() {
        var select = document.getElementById('routew_map_provider');
        if (!select) {
            return;
        }

        // Providers that supply real road distance make the correction
        // factor irrelevant. Mirrors ROUTEW_Map_Providers::all().
        var hasRouting = { google: true, osm: true, maptiler: false, geoapify: true };
        var googleFieldIds = [
            'routew_google_maps_api_key',
            'routew_google_maps_server_key',
            'routew_google_maps_map_id'
        ];

        function toggleRow(id, show) {
            var field = document.getElementById(id);
            var row = field ? field.closest('tr') : null;
            if (row) {
                row.style.display = show ? '' : 'none';
            }
        }

        function apply() {
            var provider = select.value;

            googleFieldIds.forEach(function (id) {
                toggleRow(id, provider === 'google');
            });

            toggleRow('routew_map_provider_key', provider !== 'google' && provider !== 'osm');
            toggleRow('routew_road_distance_factor', !hasRouting[provider]);

            Array.prototype.forEach.call(
                document.querySelectorAll('.routew-provider-note'),
                function (note) {
                    var mine = note.classList.contains('routew-provider-note--' + provider);
                    note.style.display = mine ? '' : 'none';
                }
            );
        }

        select.addEventListener('change', apply);
        apply();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProviderToggle);
    } else {
        initProviderToggle();
    }
})();
