/* global jQuery, L, routew_checkout_params */
/**
 * RouteMile Checkout Manager — Leaflet provider.
 *
 * Feature parity with the Google picker in checkout.js: draggable pin,
 * delivery-radius circle, browser geolocation, address search and REST
 * zone validation. Coordinates-only: the pin's lat/lng is the single
 * thing that flows onward, and no lookup ever writes into a form field.
 *
 * Geocoding is proxied through the plugin's own REST route so provider
 * keys stay server-side.
 *
 * @since 1.3.0
 */
(function ($) {
    'use strict';

    const FXW = {
        state: {
            lat: null,
            lng: null,
            isValid: false,
            map: null,
            marker: null,
            circle: null,
            searchTimer: null,
            settings: {}
        },

        C: {
            DEFAULT_CENTER: [20, 10],
            DEFAULT_ZOOM: 2,
            ZONE_ZOOM: 11,
            PIN_ZOOM: 15,
            SKELETON_CLASS: 'routew-skeleton-loading',
            NOTIFICATION_DURATION: 4000,
            SEARCH_DEBOUNCE: 600
        },

        init: function () {
            if (!$('#routew-map').length || typeof L === 'undefined') {
                return;
            }

            this.state.settings = { ...routew_checkout_params };
            this.cacheElements();
            this.bindEvents();
            this.initMap();
        },

        cacheElements: function () {
            this.$el = {
                mapContainer: $('#routew-map'),
                searchWrapper: $('.routew-location-search-wrapper'),
                searchInput: $('#routew-location-search-input'),
                locateBtn: $('#routew-get-location'),
                selectedLocation: $('#routew-selected-location'),
                selectedAddress: $('#routew-selected-address'),
                hiddenLat: $('#routew_lat'),
                hiddenLng: $('#routew_lng')
            };
        },

        bindEvents: function () {
            this.$el.locateBtn.on('click', (e) => {
                e.preventDefault();
                this.getCurrentLocation();
            });

            // Debounced address search. Enter searches immediately.
            this.$el.searchInput.on('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(this.state.searchTimer);
                    this.search(this.$el.searchInput.val());
                }
            });

            this.$el.searchInput.on('input', () => {
                clearTimeout(this.state.searchTimer);
                const value = this.$el.searchInput.val();
                this.state.searchTimer = setTimeout(() => {
                    this.search(value);
                }, this.C.SEARCH_DEBOUNCE);
            });
        },

        initMap: function () {
            const viewport = this.getInitialViewport();

            this.state.map = L.map(this.$el.mapContainer[0], {
                center: viewport.center,
                zoom: viewport.zoom,
                scrollWheelZoom: true
            });

            L.tileLayer(this.state.settings.tile_url, {
                maxZoom: this.state.settings.max_zoom || 19,
                attribution: this.state.settings.attribution || ''
            }).addTo(this.state.map);

            // Leaflet resolves its default marker icons relative to the
            // stylesheet; the bundled copy keeps that relationship, but the
            // paths are set explicitly so a CDN-hosted CSS override cannot
            // break the pin.
            const icon = L.icon({
                iconUrl: this.state.settings.marker_icon,
                iconRetinaUrl: this.state.settings.marker_icon_2x,
                shadowUrl: this.state.settings.marker_shadow,
                iconSize: [25, 41],
                iconAnchor: [12, 41],
                shadowSize: [41, 41]
            });

            this.state.marker = L.marker(viewport.center, {
                draggable: true,
                autoPan: true,
                icon: icon,
                keyboard: true,
                title: this.t('drag_pin')
            }).addTo(this.state.map);

            this.state.marker.on('dragend', () => {
                const pos = this.state.marker.getLatLng();
                this.onLocationChange(pos.lat, pos.lng);
            });

            // Tapping the map moves the pin — expected behaviour on mobile,
            // where dragging a small marker is awkward.
            this.state.map.on('click', (e) => {
                this.state.marker.setLatLng(e.latlng);
                this.onLocationChange(e.latlng.lat, e.latlng.lng);
            });

            this.drawDeliveryRadius();

            const saved = this.state.settings.saved_address;
            if (saved && saved.lat) {
                this.onLocationChange(parseFloat(saved.lat), parseFloat(saved.lng));
            }
        },

        /**
         * Look up an address through the plugin's own REST route (provider
         * keys never reach the browser) and move the pin to the result.
         */
        search: async function (query) {
            query = (query || '').trim();
            if (query.length < 3) {
                return;
            }

            this.setLoading(true);

            try {
                const url = routew_checkout_params.rest_url
                    + '/geocode?q=' + encodeURIComponent(query);

                const res = await fetch(url, {
                    headers: { 'X-WP-Nonce': routew_checkout_params.rest_nonce }
                });
                const data = await res.json();

                if (res.ok && data && typeof data.lat !== 'undefined') {
                    this.panMap(data.lat, data.lng);
                    this.onLocationChange(data.lat, data.lng);
                } else {
                    this.notify((data && data.message) || this.t('search_no_results'), 'error');
                }
            } catch (err) {
                console.error('FXW: Geocode failed', err);
                this.notify(this.t('error_generic'), 'error');
            } finally {
                this.setLoading(false);
            }
        },

        /**
         * The pin moved. Persist the coordinates to the hidden inputs and
         * validate the zone server-side (which also stores them in the WC
         * session for the shipping method).
         */
        onLocationChange: function (lat, lng) {
            this.state.lat = lat;
            this.state.lng = lng;

            this.$el.hiddenLat.val(lat);
            this.$el.hiddenLng.val(lng);

            this.validateZone(lat, lng);
        },

        validateZone: async function (lat, lng) {
            this.setLoading(true);

            try {
                const res = await fetch(
                    routew_checkout_params.rest_url + '/validate-location',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': routew_checkout_params.rest_nonce
                        },
                        body: JSON.stringify({ lat, lng })
                    }
                );

                const data = await res.json();

                // The server answers 200 with in_zone:false for an
                // out-of-zone pin (a normal outcome), so check the payload
                // rather than only the HTTP status.
                if (res.ok && data.status === 'success') {
                    this.state.isValid = true;

                    let suffix = `${data.distance_km} km`;
                    if (data.duration_text) {
                        suffix += ` · ${data.duration_text}`;
                    }
                    // Providers without road routing return a straight-line
                    // estimate; say so rather than implying a driven route.
                    if (data.estimated) {
                        suffix += ` (${this.t('estimated')})`;
                    }

                    let message;
                    if (parseFloat(data.fee) === 0) {
                        message = `${this.t('free_delivery')} · ${suffix}`;
                    } else {
                        const price = data.fee_formatted
                            || `${routew_checkout_params.currency_symbol || ''}${data.fee}`;
                        message = `${this.t('delivery_fee_estimated')} ${price} · ${suffix}`;
                    }

                    this.notify(message, 'success');
                    this.triggerTotalRefresh(lat, lng);
                } else {
                    this.state.isValid = false;
                    this.notify(data.message || data.code || this.t('out_of_zone'), 'error');
                    this.triggerTotalRefresh(lat, lng);
                }
            } catch (err) {
                console.error('FXW: Zone validation error', err);
                this.notify(this.t('error_generic'), 'error');
            } finally {
                this.setLoading(false);
            }
        },

        // ─── Live Order Summary Update ───────────────────────────
        // Mirror of the helper in checkout.js (Google Maps picker). The
        // block checkout's React totals panel only refreshes when the
        // Store API is told via `extensionCartUpdate`; classic checkout
        // rebuilds its totals on the jQuery `update_checkout` event.
        // We sniff at call time, NOT init, because `wc.blocksCheckout`
        // may not have been wired in yet when the picker boots —
        // caching the result would have us fall back to jQuery
        // permanently on the block checkout (v1.3.1).
        isBlocksCheckout: function () {
            return typeof window.wc !== 'undefined'
                && typeof window.wc.blocksCheckout !== 'undefined'
                && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function';
        },

        triggerTotalRefresh: function (lat, lng) {
            if (this.isBlocksCheckout()) {
                try {
                    window.wc.blocksCheckout.extensionCartUpdate({
                        namespace: 'routemile-for-woocommerce',
                        data: { lat, lng }
                    });
                    return;
                } catch (err) {
                    console.warn('FXW: extensionCartUpdate failed, falling back to jQuery', err);
                }
            }
            $(document.body).trigger('update_checkout');
        },

        getCurrentLocation: function () {
            if (!navigator.geolocation) {
                this.notify(this.t('geolocation_unsupported'), 'error');
                return;
            }

            const $btn = this.$el.locateBtn;
            const originalText = $btn.text();
            $btn.addClass('loading').prop('disabled', true).text(this.t('locating'));

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    $btn.removeClass('loading').prop('disabled', false).text(originalText);
                    this.panMap(pos.coords.latitude, pos.coords.longitude);
                    this.onLocationChange(pos.coords.latitude, pos.coords.longitude);
                },
                (err) => {
                    $btn.removeClass('loading').prop('disabled', false).text(originalText);
                    const messages = {
                        1: this.t('location_denied'),
                        2: this.t('location_unavailable'),
                        3: this.t('location_timeout')
                    };
                    this.notify(messages[err.code] || this.t('error_generic'), 'error');
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        },

        panMap: function (lat, lng) {
            if (!this.state.map || !this.state.marker) {
                return;
            }
            const pos = [lat, lng];
            this.state.map.setView(pos, Math.max(this.state.map.getZoom(), this.C.PIN_ZOOM));
            this.state.marker.setLatLng(pos);
        },

        getInitialViewport: function () {
            const saved = this.state.settings.saved_address;
            if (saved && saved.lat && saved.lng) {
                return {
                    center: [parseFloat(saved.lat), parseFloat(saved.lng)],
                    zoom: this.C.PIN_ZOOM
                };
            }
            const restaurant = this.state.settings.restaurant_center;
            if (restaurant && restaurant.lat && restaurant.lng) {
                return {
                    center: [parseFloat(restaurant.lat), parseFloat(restaurant.lng)],
                    zoom: this.C.ZONE_ZOOM
                };
            }
            return { center: this.C.DEFAULT_CENTER, zoom: this.C.DEFAULT_ZOOM };
        },

        drawDeliveryRadius: function () {
            const restaurant = this.state.settings.restaurant_center;
            const radiusKm = parseFloat(this.state.settings.radius_km);

            if (!restaurant || !restaurant.lat || !restaurant.lng || !radiusKm) {
                return;
            }

            this.state.circle = L.circle(
                [parseFloat(restaurant.lat), parseFloat(restaurant.lng)],
                {
                    radius: radiusKm * 1000,
                    interactive: false,
                    fillColor: '#28a745',
                    fillOpacity: 0.06,
                    color: '#28a745',
                    opacity: 0.5,
                    weight: 1.5
                }
            ).addTo(this.state.map);
        },

        showSelectedAddress: function (address) {
            if (!address) {
                return;
            }
            this.$el.selectedAddress.text(address);
            this.$el.selectedLocation.slideDown(200);
        },

        setLoading: function (on) {
            this.$el.mapContainer.toggleClass(this.C.SKELETON_CLASS, on);
        },

        notify: function (message, type) {
            let $n = $('#routew-map-notification');
            if (!$n.length) {
                $n = $('<div id="routew-map-notification" role="status" aria-live="polite"></div>')
                    .insertAfter('#routew-location-picker-container');
            }

            $n.stop(true, true)
                .removeClass('error success info')
                .addClass(type)
                .text(message)
                .fadeIn(200);

            if (type !== 'error') {
                $n.delay(this.C.NOTIFICATION_DURATION).fadeOut(300);
            }
        },

        t: function (key) {
            return (routew_checkout_params.translations && routew_checkout_params.translations[key]) || key;
        }
    };

    $(function () {
        FXW.init();
    });

})(jQuery);
