jQuery(function ($) {
    // Tab switching - delegated, no passive needed for click
    $(document).on('click', '[data-routew-tab]', function () {
        var tabId = $(this).data('routew-tab');
        // Sanitise tabId to prevent DOM selector injection
        if (!/^[a-zA-Z0-9_-]+$/.test(tabId)) {
            return;
        }

        var $panel = document.getElementById(tabId);
        if (!$panel) {
            return;
        }

        $('.routew-tab-link').removeClass('active').attr('aria-selected', 'false');
        $(this).addClass('active').attr('aria-selected', 'true');
        $('.routew-tab-content').removeClass('active').hide();
        $($panel).addClass('active').show();
    });

    $(document).on('click', '.routew-print-receipt', function (e) {
        e.preventDefault();

        var $button = $(this);
        var orderId = $button.data('order-id');

        // Check if AJAX params are available
        if (typeof routew_checkout_params === 'undefined' || !routew_checkout_params.ajax_url) {
            alert('Print receipt functionality is not available. Please contact support.');
            return;
        }

        if (!orderId) {
            alert('Order ID is missing. Cannot print receipt.');
            return;
        }

        // Disable button and show loading state
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

            // Reset button after a short delay
            setTimeout(function () {
                $button.prop('disabled', false).text(originalText);
            }, 2000);

            // Handle the print window load event
            printWindow.onload = function () {
                // Small delay to ensure content is rendered
                setTimeout(function () {
                    if (printWindow && !printWindow.closed) {
                        printWindow.print();
                    }
                }, 500);
            };

            // Handle case where window fails to load
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

    // Also handle receipt printing from admin order pages
    $(document).on('click', 'a[href*="routew_print_receipt"]', function (e) {
        var $link = $(this);
        var originalText = $link.text();

        // Only handle if it's a JavaScript onclick, not a regular link
        if ($link.attr('onclick')) {
            $link.text('Opening receipt...');

            setTimeout(function () {
                $link.text(originalText);
            }, 2000);
        }
    });
    // AJAX handler for delivery status updates
    $(document).on('click', '.routew-action-btn', function (e) {
        e.preventDefault();

        var $button = $(this);
        var orderId = $button.data('order-id');
        var actionStatus = $button.data('status');
        var nonce = $button.data('nonce');
        var originalText = $button.html();

        if (!orderId || !actionStatus || !nonce) {
            alert('Missing data for this action.');
            return;
        }

        if (!confirm('Are you sure you want to update the order status?')) {
            return;
        }

        $(document).trigger('routew:action-start');
        $button.prop('disabled', true).text('Updating...');

        if (typeof routew_checkout_params === 'undefined' || !routew_checkout_params.ajax_url) {
            alert('Session expired. Please reload the page.');
            $button.prop('disabled', false).html(originalText);
            return;
        }

        $.ajax({
            url: routew_checkout_params.ajax_url,
            type: 'POST',
            data: {
                action: 'routew_update_delivery_status',
                order_id: orderId,
                status: actionStatus,
                nonce: nonce
            },
            success: function (response) {
                if (response.success) {
                    var $card = $button.closest('.routew-order-card');

                    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    var delay = reducedMotion ? 0 : 150;
                    $card.addClass('routew-card-removing');
                    requestAnimationFrame(function () {
                        if (!reducedMotion) {
                            $card.css({ opacity: 0, transform: 'scale(0.98)' });
                        }
                        setTimeout(function () {
                            // Reload so the order re-appears in its new tab
                            // (New → In Progress on pickup, In Progress → done
                            // on delivery) with fresh counters. Removing the
                            // card alone left it missing until a manual
                            // refresh.
                            window.location.reload();
                        }, delay);
                    });
                } else {
                    alert((response.data && response.data.message) ? response.data.message : 'Failed to update status.');
                    $button.prop('disabled', false).html(originalText);
                    $(document).trigger('routew:action-end');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', textStatus, errorThrown);
                alert('Connection error. Please try again.');
                $button.prop('disabled', false).html(originalText);
                $(document).trigger('routew:action-end');
            }
        });
    });

    /**
     * Agent PWA layer: toasts, service worker, heartbeat auto-reload,
     * and order notifications. Everything is feature-detected so the
     * dashboard degrades gracefully on old browsers.
     */
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
        if (!$toast.length) {
            return;
        }
        $toast.text(message).attr('hidden', false).addClass('routew-toast--visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            $toast.removeClass('routew-toast--visible').attr('hidden', true);
        }, 3200);
    }

    // Confirmations that arrive via query flags after a status change.
    if (window.location.search.indexOf('updated=1') !== -1) {
        showToast(agentI18n('picked_up_toast', 'Order picked up'));
    } else if (window.location.search.indexOf('delivered=1') !== -1) {
        showToast(agentI18n('delivered_toast', 'Order delivered. Great job!'));
    } else if (window.location.search.indexOf('settled=1') !== -1) {
        showToast(agentI18n('settled_toast', 'Hand-over request sent — waiting for manager approval'));
    }

    // Cash hand-over: confirm before the POST (the amount is computed
    // server-side; the dialog only restates what the button shows).
    // Match by the hidden action input — the form's action URL is plain
    // admin-post.php.
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

    // Online / offline awareness for the installed app.
    window.addEventListener('offline', function () {
        showToast(agentI18n('offline', 'You are offline — orders will refresh when the connection returns'));
    });
    window.addEventListener('online', function () {
        showToast(agentI18n('online', 'Back online'));
    });

    // --- Service worker (offline shell + static asset cache) ---
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

    // --- Heartbeat: auto-reload on new orders / status changes ---
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
        var $shell = $('.routew-app-dashboard');
        var knownSignature = ($shell.data('routew-state') || '') + '';

        // Survive reloads: once a signature has been rendered, remember it
        // so a reload does not trigger another reload.
        try {
            if (!knownSignature && sessionStorage.getItem(STORAGE_KEY)) {
                knownSignature = sessionStorage.getItem(STORAGE_KEY);
            }
            if (knownSignature) {
                sessionStorage.setItem(STORAGE_KEY, knownSignature);
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
                if (!signature || signature === knownSignature) {
                    return;
                }
                sessionStorage.setItem(STORAGE_KEY, signature);
                // All updates surface inside the order list itself: reload
                // and let the server-rendered cards tell the story.
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
});
