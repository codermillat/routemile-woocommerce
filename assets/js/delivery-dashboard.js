jQuery(function ($) {
    // =========================================================================
    // Agent PWA — tab navigation, in-place order actions (no page reload),
    // live counters, toasts, heartbeat auto-refresh, offline awareness.
    //
    // Every status action returns a rich payload from the server
    // (build_action_response): the fresh order card, its destination tab,
    // the updated tab counts + today stats, and the new state signature.
    // The client moves the card, updates the counters, switches to the
    // destination tab and toasts — exactly like a native app. A full page
    // reload only happens for changes the agent didn't trigger (heartbeat).
    // =========================================================================

    // --- Tab switching -----------------------------------------------------
    function switchTab(tabId, options) {
        if (!/^[a-zA-Z0-9_-]+$/.test(tabId)) {
            return;
        }
        var $panel = document.getElementById(tabId);
        if (!$panel) {
            return;
        }
        var $link = $('[data-routew-tab="' + tabId + '"]');
        $('.routew-tab-link').removeClass('active').attr('aria-selected', 'false');
        $link.addClass('active').attr('aria-selected', 'true');
        $('.routew-tab-content').removeClass('active').hide();
        $($panel).addClass('active').show();

        if (!options || !options.noScroll) {
            // Keep the app header visible; scroll the list to top so the
            // newest card (inserted at the top) is in view.
            $('.routew-app-main').scrollTop(0);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    $(document).on('click', '[data-routew-tab]', function () {
        switchTab($(this).data('routew-tab'));
    });

    // --- Live counters -----------------------------------------------------
    // Map the server's count keys to the tabbar badges + header stat tiles
    // + the "Cash to collect" / "you are holding ৳X" banners. Runs in
    // place after every agent action so the dashboard never has to
    // reload just to show new totals (the agent's own Cash Collected /
    // Mark Delivered / Mark Picked Up tap updates every banner
    // immediately — see the comment on
    // ROUTEW_Delivery_Boy_View::build_dashboard_state for the
    // server-side half).
    function updateCounters(counts, today, cod, cash) {
        var $shell = $('.routew-app-dashboard');

        if (counts) {
            var $newBadge = $('[data-routew-tab="new-orders"] .routew-tabbar__count');
            var $progBadge = $('[data-routew-tab="in-progress"] .routew-tabbar__count');
            var $doneBadge = $('[data-routew-tab="delivered"] .routew-tabbar__count');
            pulseBadge($newBadge, counts.new);
            pulseBadge($progBadge, counts.in_progress);
            pulseBadge($doneBadge, counts.delivered);
            $shell.data('routew-counts', counts);
        }

        if (today && $shell.find('.routew-agent-stats').length) {
            // Header stat tiles: [0] delivered today, [1] active now,
            // [2] collected today, [3] to hand over, [4] all-time.
            var $tiles = $shell.find('.routew-agent-stat');
            if ($tiles.length >= 3) {
                $tiles.eq(0).find('.routew-agent-stat__value').text(today.delivered);
                $tiles.eq(1).find('.routew-agent-stat__value').text(today.active);
            }
        }

        // "Cash to collect: ৳X across N COD order(s)" banner — drop or
        // restore the strip based on the new totals so the agent doesn't
        // need a reload to see the banner shrink after Cash Collected.
        if (cod && typeof cod.count !== 'undefined' && typeof cod.total !== 'undefined') {
            var $codBanner = $('.routew-cod-summary');
            if (cod.count > 0 && cod.total > 0) {
                if (!$codBanner.length) {
                    $codBanner = $('<div class="routew-cod-summary" role="status"></div>');
                    $shell.find('.routew-app-header').append($codBanner);
                }
                var label = agentI18n('cash_to_collect', 'Cash to collect: {total} across {count} COD order(s)')
                    .replace('{total}', String(cod.total)).replace('{count}', String(cod.count));
                $codBanner.text(label);
            } else if ($codBanner.length) {
                $codBanner.remove();
            }
        }

        // "You are holding ৳X of the store's cash" unsettled banner —
        // visible when the agent has delivered COD orders but no
        // settlement is pending. Touches the to-hand-over stat tile
        // too so the dashboard reflects the new total immediately
        // after Mark Delivered. Amounts arrive PRE-FORMATTED from the
        // server (wc_price with the store currency) — never render a
        // raw float here.
        if (cash && typeof cash.unsettled !== 'undefined') {
            var amountText = cash.unsettled_formatted || String(cash.unsettled);
            var $holdBar = $('.routew-settle-bar--active');
            var $dueTile = $('.routew-agent-stat--due .routew-agent-stat__value');
            if (cash.unsettled > 0 && !cash.has_pending) {
                var label = agentI18n('holding', "You are holding {amount} of the store's cash.").replace('{amount}', amountText);
                if (!$holdBar.length) {
                    $holdBar = $('<div class="alert routew-settle-bar routew-settle-bar--active" role="status"><span class="routew-settle-bar__text"></span></div>');
                    var $header = $shell.find('.routew-app-header');
                    var $anchor = $header.find('.routew-settle-bar, .routew-settle-last').last();
                    if ($anchor.length) {
                        $holdBar.insertAfter($anchor);
                    } else {
                        $header.append($holdBar);
                    }
                }
                $holdBar.find('.routew-settle-bar__text').text(label);
            } else if ($holdBar.length) {
                $holdBar.remove();
            }
            if ($dueTile.length) {
                $dueTile.text(amountText);
            }
        }
    }

    // Animate a count badge only when it grows (native-app "new item" cue).
    function pulseBadge($badge, value) {
        if (!$badge || !$badge.length || typeof value === 'undefined') {
            return;
        }
        var old = parseInt($badge.text(), 10);
        if (isNaN(old)) { old = 0; }
        var next = parseInt(value, 10) || 0;
        $badge.text(next);
        if (next > old) {
            $badge.removeClass('routew-tabbar__count--pulse');
            // restart the animation
            void $badge[0].offsetWidth;
            $badge.addClass('routew-tabbar__count--pulse');
        }
    }

    // --- Empty-state management -------------------------------------------
    // The template renders one static empty state per tab. Keep it in sync
    // with the live card count so a tab never shows "No deliveries" above
    // a card that just arrived (or vice versa).
    function refreshEmptyStates() {
        $('#new-orders, #in-progress, #delivered').each(function () {
            var $panel = $(this);
            var hasCards = $panel.children('.routew-order-card').length > 0;
            var $empty = $panel.children('.routew-no-orders');
            if (hasCards && $empty.length) {
                $empty.remove();
            } else if (!hasCards && !$empty.length) {
                $panel.append(EMPTY_STATES[this.id]);
            }
        });
    }

    var EMPTY_STATES = {
        'new-orders': '<div class="routew-no-orders"><svg class="routew-svg-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.3 6.3 3.2L12 10.7 5.7 7.5 12 4.3Zm-7 4.9 6 3v7.2l-6-3V9.2Zm8 10.2v-7.2l6-3v7.2l-6 3Z"/></svg><p>No new deliveries assigned.</p><p class="routew-no-orders__hint">New orders appear here automatically — leave the app open.</p></div>',
        'in-progress': '<div class="routew-no-orders"><svg class="routew-svg-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 10.6 4 2.3-1 1.7-5-2.9V6h2v6.6Z"/></svg><p>No deliveries in progress.</p></div>',
        'delivered': '<div class="routew-no-orders"><svg class="routew-svg-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3v18h2v-7h10.6l-2.2-4 2.2-4H7V3H5Z"/></svg><p>No delivered orders yet.</p></div>'
    };

    // --- Toast -------------------------------------------------------------
    function agentI18n(key, fallback) {
        if (typeof fxwAgentDashboard === 'undefined' || !fxwAgentDashboard.i18n) {
            return fallback;
        }
        return fxwAgentDashboard.i18n[key] || fallback;
    }

    function agentConfig() {
        return typeof fxwAgentDashboard !== 'undefined' ? fxwAgentDashboard : null;
    }

    var toastTimer = null;
    function showToast(message) {
        var $toast = $('#routew-toast');
        if (!$toast.length || !message) {
            return;
        }
        $toast.text(message).attr('hidden', false).addClass('routew-toast--visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            $toast.removeClass('routew-toast--visible').attr('hidden', true);
        }, 3200);
    }

    // Confirmations that arrive via query flags after a non-JS
    // (admin_post) fallback action.
    if (window.location.search.indexOf('updated=1') !== -1) {
        showToast(agentI18n('picked_up_toast', 'Order picked up'));
    } else if (window.location.search.indexOf('delivered=1') !== -1) {
        showToast(agentI18n('delivered_toast', 'Order delivered. Great job!'));
    } else if (window.location.search.indexOf('settled=1') !== -1) {
        showToast(agentI18n('settled_toast', 'Hand-over request sent — waiting for manager approval'));
    }

    // --- Receipt printing ---------------------------------------------------
    $(document).on('click', '.routew-print-receipt', function (e) {
        e.preventDefault();

        var $button = $(this);
        var orderId = $button.data('order-id');

        if (typeof routew_checkout_params === 'undefined' || !routew_checkout_params.ajax_url) {
            alert('Print receipt functionality is not available. Please contact support.');
            return;
        }

        if (!orderId) {
            alert('Order ID is missing. Cannot print receipt.');
            return;
        }

        var originalText = $button.text();
        $button.prop('disabled', true).text('Opening receipt...');

        var printUrl = routew_checkout_params.ajax_url + '?action=routew_print_receipt&order_id=' + orderId + '&nonce=' + routew_checkout_params.nonce;

        try {
            var printWindow = window.open(printUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');

            if (!printWindow) {
                alert('Receipt window was blocked. Please allow popups for this site and try again.');
                $button.prop('disabled', false).text(originalText);
                return;
            }

            setTimeout(function () {
                $button.prop('disabled', false).text(originalText);
            }, 2000);

            printWindow.onload = function () {
                setTimeout(function () {
                    if (printWindow && !printWindow.closed) {
                        printWindow.print();
                    }
                }, 500);
            };

            printWindow.onerror = function () {
                alert('Failed to load receipt. Please try again or contact support.');
                $button.prop('disabled', false).text(originalText);
            };

        } catch (error) {
            console.error('Error opening receipt window:', error);
            alert('Failed to open receipt window. Please try again.');
            $button.prop('disabled', false).text(originalText);
        }
    });

    // --- Order actions: in-place update, no page reload ---------------------
    // The server returns { tab, card, counts, today, cod, signature } — see
    // ROUTEW_Delivery_Boy_View::build_action_response().
    $(document).on('click', '.routew-action-btn', function (e) {
        e.preventDefault();

        var $button = $(this);
        var orderId = $button.data('order-id');
        var actionStatus = $button.data('status');
        var nonce = $button.data('nonce');
        var ajaxAction = $button.data('action') || 'routew_update_delivery_status';
        var confirmMessage = $button.data('confirm') || 'Are you sure you want to update the order status?';
        var originalHtml = $button.html();

        if (!orderId || !nonce) {
            alert('Missing data for this action.');
            return;
        }
        if (ajaxAction === 'routew_update_delivery_status' && !actionStatus) {
            alert('Missing data for this action.');
            return;
        }

        if (!confirm(confirmMessage)) {
            return;
        }

        $(document).trigger('routew:action-start');
        $button.prop('disabled', true).text('Updating...');

        if (typeof routew_checkout_params === 'undefined' || !routew_checkout_params.ajax_url) {
            alert('Session expired. Please reload the page.');
            $button.prop('disabled', false).html(originalHtml);
            $(document).trigger('routew:action-end');
            return;
        }

        var postData = {
            action: ajaxAction,
            order_id: orderId,
            nonce: nonce
        };
        if (actionStatus) {
            postData.status = actionStatus;
        }

        $.ajax({
            url: routew_checkout_params.ajax_url,
            type: 'POST',
            data: postData,
            success: function (response) {
                if (response && response.success && response.data) {
                    applyActionResponse($button, response.data);
                } else {
                    alert((response && response.data && response.data.message) ? response.data.message : 'Failed to update status.');
                    $button.prop('disabled', false).html(originalHtml);
                    $(document).trigger('routew:action-end');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', textStatus, errorThrown);
                alert('Connection error. Please try again.');
                $button.prop('disabled', false).html(originalHtml);
                $(document).trigger('routew:action-end');
            }
        });
    });

    /**
     * Move the acted-on card to its destination tab (or refresh it in
     * place for same-tab updates like the COD cash gate), update every
     * counter, switch to the destination tab and toast the result.
     */
    function applyActionResponse($button, data) {
        var $card = $button.closest('.routew-order-card');
        var currentPanelId = $card.closest('.routew-tab-content').attr('id');
        var destinationTab = data.tab || currentPanelId;
        var isCashConfirm = destinationTab === currentPanelId; // cash gate refreshes in place

        // 1) Update counters + signature FIRST so badges are correct even
        // if the animations below are interrupted.
        updateCounters(data.counts, data.today, data.cod, data.cash);
        if (data.signature) {
            try {
                sessionStorage.setItem('fxwAgentStateSig', String(data.signature));
            } catch (e) { /* private mode */ }
            var $shell = $('.routew-app-dashboard');
            if ($shell.length) {
                $shell.data('routew-state', String(data.signature));
            }
        }

        // 2) Insert the fresh card into its destination panel.
        var $destination = $('#' + destinationTab);
        if ($destination.length && data.card) {
            var $newCard = $(data.card);
            // Remove any stale copy (idempotency for double-taps).
            // IMPORTANT: do this BEFORE inserting the new card. If we
            // detach the old card first and then call
            // `$newCard.insertBefore($card)`, the new card has no
            // document context to attach to and silently ends up in
            // limbo — that's how the in-place COD refresh used to drop
            // the card from the In Progress tab.
            $destination.children('.routew-order-card[data-order-id="' + $card.data('order-id') + '"]').remove();
            if (isCashConfirm) {
                // Same tab: drop the new card where the old one was
                // (top of the destination panel — they were just sorted
                // by the server).
                $destination.prepend($newCard);
            } else {
                // Moving tabs: new card on top of the destination list.
                $destination.prepend($newCard);
            }
            $newCard.addClass('routew-card--arriving');
        }

        // 3) Remove the old card (animated), then fix empty states.
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function removeOldCard() {
            if ($card.length && $card.parent().length && !isCashConfirm) {
                $card.addClass('routew-card--removing');
                if (reducedMotion) {
                    $card.remove();
                    refreshEmptyStates();
                } else {
                    $card.css({ opacity: 0, transform: 'scale(0.97)' });
                    setTimeout(function () {
                        $card.remove();
                        refreshEmptyStates();
                    }, 180);
                }
            } else {
                // Old card already detached by the idempotency remove
                // above (cash confirm path) — nothing left to animate.
                refreshEmptyStates();
            }
        }

        // 4) Switch to the tab the order now lives in — the "follow your
        // order" behaviour. For the COD cash gate the card stays put, so
        // the current tab stays selected.
        if (!isCashConfirm && destinationTab !== currentPanelId) {
            switchTab(destinationTab);
        }

        requestAnimationFrame(removeOldCard);

        // 5) Toast the result.
        var message = data.message || '';
        if (data.tab === 'in-progress') {
            showToast(agentI18n('picked_up_toast', 'Order picked up — now in progress'));
        } else if (data.tab === 'delivered') {
            showToast(agentI18n('delivered_toast', 'Order delivered. Great job!'));
        } else if (message) {
            showToast(message);
        }

        $(document).trigger('routew:action-end');
    }

    // --- Cash hand-over: confirm before the POST ---------------------------
    $(document).on('submit', 'form', function () {
        if ('routew_settle_agent_cash' !== String($(this).find('input[name="action"]').val() || '')) {
            return;
        }
        var amount = $(this).find('.routew-settle-btn').data('routew-settle-amount') || '';
        var message = agentI18n('settle_confirm', 'Send this cash hand-over to the manager for approval?');
        if (amount) {
            message = agentI18n('settle_confirm_amount', 'Send {amount} hand-over request to the manager for approval?').replace('{amount}', String(amount));
        }
        return window.confirm(message);
    });

    // --- Online / offline awareness ----------------------------------------
    window.addEventListener('offline', function () {
        showToast(agentI18n('offline', 'You are offline — orders will refresh when the connection returns'));
    });
    window.addEventListener('online', function () {
        showToast(agentI18n('online', 'Back online'));
    });

    // --- Service worker (offline shell + static asset cache) ---------------
    (function registerServiceWorker() {
        var config = agentConfig();
        if (!config || !config.swUrl || !('serviceWorker' in navigator)) {
            return;
        }
        if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
            return; // SW requires a secure context.
        }
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(config.swUrl, { scope: '/' }).catch(function (error) {
                console.warn('FXW agent service worker registration failed:', error);
            });
        });
    })();

    // --- Heartbeat: reload on external changes (new assignment, etc.) ------
    // Actions the agent themself takes update the UI in place (above) — the
    // heartbeat only catches changes from OUTSIDE (manager assigns an
    // order, admin edits one). A signature change there still reloads,
    // because inserting a NEW order card needs data the poll payload
    // doesn't carry; the reload preserves the selected tab via the hash.
    var actionInFlight = false;
    $(document)
        .on('routew:action-start', function () { actionInFlight = true; })
        .on('routew:action-end', function () { actionInFlight = false; });

    (function heartbeat() {
        var config = agentConfig();
        if (!config || !config.ajaxUrl || !config.stateNonce) {
            return;
        }

        var STORAGE_KEY = 'fxwAgentStateSig';
        var STORAGE_TAB_KEY = 'fxwAgentActiveTab';
        var $shell = $('.routew-app-dashboard');

        // The signature we last considered "current". Always re-read from
        // the shell's `data-routew-state` so an action the agent JUST took
        // (which updates the data attribute via applyActionResponse)
        // immediately becomes the new baseline — otherwise the next poll
        // would see a "new" signature (the server's order-modified
        // timestamp moved) and trigger a hard reload for the agent's own
        // action. (Fixes the "feels like hard reload" bug after Cash
        // Collected / Mark Picked Up / Mark Delivered.)
        function getKnownSignature() {
            var fromShell = ($shell.data('routew-state') || '') + '';
            if (fromShell) {
                return fromShell;
            }
            try {
                return (sessionStorage.getItem(STORAGE_KEY) || '') + '';
            } catch (e) {
                return '';
            }
        }

        // Persist the initial signature so the first server poll doesn't
        // immediately reload (we just rendered with this signature).
        try {
            var initial = getKnownSignature();
            if (initial) {
                sessionStorage.setItem(STORAGE_KEY, initial);
            }
        } catch (e) { /* private mode */ }

        var nonceExpired = false;

        function poll() {
            if (document.hidden || actionInFlight || nonceExpired) {
                return;
            }
            $.get(config.ajaxUrl, {
                action: 'routew_agent_dashboard_state',
                nonce: config.stateNonce
            }).done(function (response) {
                if (!response || !response.success || !response.data) {
                    return;
                }
                var signature = response.data.signature;
                var known = getKnownSignature();
                if (!signature || signature === known) {
                    return;
                }
                try {
                    sessionStorage.setItem(STORAGE_KEY, signature);
                } catch (e) { /* private mode */ }
                // Remember the active tab so the reload lands the agent
                // where they were — NOT back on the New tab (the bug this
                // fixes: an external change pulled them home mid-shift).
                var activeTab = $('.routew-tab-link.active').data('routew-tab');
                if (activeTab) {
                    try {
                        sessionStorage.setItem(STORAGE_TAB_KEY, String(activeTab));
                    } catch (e) { /* private mode */ }
                }
                window.location.reload();
            }).fail(function (xhr) {
                if (xhr && xhr.status === 403) {
                    // Nonce expired (long-lived installed app): one silent
                    // reload picks up a fresh nonce from the server render.
                    nonceExpired = true;
                    window.location.reload();
                }
            });
        }

        var interval = parseInt(config.pollIntervalMs, 10) || 30000;
        setInterval(poll, interval);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                poll(); // refresh instantly when the agent returns to the app
            }
        });
    })();

    // --- Restore the active tab after a heartbeat reload --------------------
    (function restoreActiveTab() {
        var tabId = null;
        try {
            tabId = sessionStorage.getItem('fxwAgentActiveTab');
            sessionStorage.removeItem('fxwAgentActiveTab');
        } catch (e) { /* private mode */ }
        if (tabId && /^[a-zA-Z0-9_-]+$/.test(tabId) && document.getElementById(tabId)) {
            switchTab(tabId, { noScroll: true });
        }
    })();
});
