<?php
/**
 * Delivery Agent Dashboard — installable PWA shell.
 *
 * Native-app presentation: fixed app header with agent identity and the
 * cash-on-delivery collection summary, a bottom tab bar, 48px action
 * buttons, safe-area padding, service-worker offline support, and a
 * heartbeat that reloads the dashboard whenever an order is assigned or a
 * status changes. `routew_render_order_card()` must stay defined ABOVE the
 * render loop — conditionally-defined PHP functions are not hoisted.
 *
 * @package RouteMile
 */
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Rider dashboard: meta_key/meta_value lookups against `_routew_delivery_*`
// are how the rider's own order list is filtered. Documented WC pattern.
// Template-local variables (`$new_orders`, `$picked_up_orders`,
// `$delivered_orders`, `$reviewer`, etc.) are include-only locals, not real
// globals. Re-enabled at EOF.

if (!defined('ABSPATH')) {
    exit;
}
// Rider dashboard: meta_key/meta_value lookups against `_routew_delivery_*`
// are how the rider's own order list is filtered. Documented WC pattern.
// Re-enabled at EOF.

// Auth check: must be logged in with delivery access
if (!is_user_logged_in() || !current_user_can('routew_delivery_access')) {
    wp_safe_redirect(home_url());
    exit;
}

/**
 * One inline SVG icon for the agent interface (no emoji, no icon font).
 *
 * @param string $name Icon key: person|phone|home|ruler|card|cash|note|pin|box|flag|bell|clock.
 * @return string SVG markup (aria-hidden).
 * @since 1.4.0
 */
if (!function_exists('routew_agent_icon')) {
    function routew_agent_icon($name)
    {
        $paths = array(
            'person' => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-8 1.7-8 5v2h16v-2c0-3.3-4.7-5-8-5Z"/>',
            'phone' => '<path d="M6.6 10.8a15.6 15.6 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1.1-.2 1.2.4 2.6.6 3.9.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.4c.6 0 1 .4 1 1 0 1.3.2 2.7.6 3.9.1.4 0 .8-.2 1.1l-2.2 2.8Z"/>',
            'home' => '<path d="M12 3.2 3 11h2.4v9h5.1v-5.4h3v5.4h5.1v-9H21L12 3.2Z"/>',
            'ruler' => '<path d="M3.8 15.2 15.2 3.8a1 1 0 0 1 1.4 0l3.6 3.6a1 1 0 0 1 0 1.4L8.8 20.2a1 1 0 0 1-1.4 0l-3.6-3.6a1 1 0 0 1 0-1.4Zm3.6 1.4 1.4-1.4-1.1-1.1 1.4-1.4 1.1 1.1 1.4-1.4-1.1-1.1 1.4-1.4 1.1 1.1 1.4-1.4-1.1-1.1 1.4-1.4 3.3 3.3L7.4 19.7l-1.1-1.1Z"/>',
            'card' => '<path d="M4 5h16a1 1 0 0 1 1 1v3H3V6a1 1 0 0 1 1-1Zm-1 6h18v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-7Zm3 3v2h5v-2H6Z"/>',
            'cash' => '<path d="M3 6h18v12H3V6Zm9 2.5A3.5 3.5 0 1 0 12 15.5 3.5 3.5 0 0 0 12 8.5ZM5 8v2h2V8H5Zm12 6v2h2v-2h-2Z"/>',
            'note' => '<path d="M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 1.5V8h4.5L14 3.5ZM7 12h10v1.6H7V12Zm0 3.4h10V17H7v-1.6Z"/>',
            'pin' => '<path d="M12 2a7 7 0 0 1 7 7c0 5.2-7 13-7 13S5 14.2 5 9a7 7 0 0 1 7-7Zm0 9.6A2.6 2.6 0 1 0 12 6.4a2.6 2.6 0 0 0 0 5.2Z"/>',
            'box' => '<path d="M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.3 6.3 3.2L12 10.7 5.7 7.5 12 4.3Zm-7 4.9 6 3v7.2l-6-3V9.2Zm8 10.2v-7.2l6-3v7.2l-6 3Z"/>',
            'flag' => '<path d="M5 3v18h2v-7h10.6l-2.2-4 2.2-4H7V3H5Z"/>',
            'bell' => '<path d="M12 22a2.3 2.3 0 0 0 2.3-2.3H9.7A2.3 2.3 0 0 0 12 22Zm7-5.4v-1l-1.7-1.7v-4A5.5 5.5 0 0 0 13 4.6V3.5a1 1 0 0 0-2 0v1.1a5.5 5.5 0 0 0-4.3 5.3v4L5 15.6v1h14Z"/>',
            'clock' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 10.6 4 2.3-1 1.7-5-2.9V6h2v6.6Z"/>',
        );

        if (!isset($paths[$name])) {
            return '';
        }

        return '<svg class="routew-svg-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
    }
}

/**
 * Render one agent order card.
 *
 * @param WC_Order $order Order assigned to the current agent.
 * @param array    $args  Optional flags: 'history' renders the compact
 *                        delivered view (no status buttons, delivered date
 *                        and collected amount shown instead).
 * @since 1.0.0
 */
if (!function_exists('routew_render_order_card')) {
    function routew_render_order_card($order, $args = array())
    {
        $history = !empty($args['history']);
        $shipping_address = $order->get_formatted_shipping_address();

        // Get new delivery details
        $delivery_address = $order->get_meta('_routew_delivery_address', true);
        $delivery_lat = $order->get_meta('_routew_delivery_lat', true);
        $delivery_lng = $order->get_meta('_routew_delivery_lng', true);
        $delivery_distance = $order->get_meta('_routew_delivery_distance', true);

        // Fallback to old unit field for backward compatibility
        $unit = $order->get_meta('_routew_address_unit', true);
        $status = $order->get_status();
        $is_cod = 'cod' === $order->get_payment_method();

        // Delivery instructions ONLY for the agent. The checkout "order
        // note" (customer_note) is a kitchen note — the manager/admin reads
        // it on the order screen and passes it to the kitchen; it must not
        // reach the delivery agent's phone.
        $instructions = trim((string) $order->get_meta('_routew_delivery_instructions', true));

        $completed = $order->get_date_completed();
        ?>
            <?php
            // Map WC order status to Bootstrap status-pill color.
            $status_pill_class = 'text-bg-secondary';
            if (in_array($status, array('routew-assigned'), true)) {
                $status_pill_class = 'text-bg-warning';
            } elseif (in_array($status, array('routew-picked-up', 'routew-in-kitchen'), true)) {
                $status_pill_class = 'text-bg-info';
            } elseif (in_array($status, array('completed'), true)) {
                $status_pill_class = 'text-bg-success';
            } elseif (in_array($status, array('cancelled', 'failed', 'refunded'), true)) {
                $status_pill_class = 'text-bg-danger';
            }
            ?>
            <div class="card routew-order-card <?php echo $is_cod ? 'routew-order-card--cod' : ''; ?> <?php echo $history ? 'routew-order-card--history' : ''; ?>">
                <div class="card-header routew-card-header">
                    <h3 class="h6 mb-0">
                        <?php printf(
                            /* translators: %s: order number. */
                            esc_html__('Order #%s', 'routemile-for-woocommerce'),
                            esc_html($order->get_order_number())
                        ); ?>
                        <?php if ($history && $completed): ?>
                            <span class="routew-card-header__date small text-secondary ms-2">
                                <?php echo esc_html($completed->date_i18n(get_option('date_format') . ' ' . get_option('time_format'))); ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <span class="badge routew-status-badge <?php echo esc_attr($status_pill_class); ?>"><?php echo esc_html(wc_get_order_status_name($status)); ?></span>
                </div>
                <?php if ($is_cod): ?>
                    <div class="routew-cod-strip <?php echo $history ? 'routew-cod-strip--settled' : ''; ?>" role="status">
                        <?php echo routew_agent_icon('cash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                        <span class="routew-cod-strip__label"><?php echo $history ? esc_html__('Collected on delivery', 'routemile-for-woocommerce') : esc_html__('Collect on delivery', 'routemile-for-woocommerce'); ?></span>
                        <span class="routew-cod-strip__amount"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
                        <span class="routew-cod-strip__method"><?php echo esc_html($order->get_payment_method_title()); ?></span>
                    </div>
                <?php else: ?>
                    <div class="routew-prepaid-strip" role="status">
                        <?php echo routew_agent_icon('card'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                        <span class="routew-prepaid-strip__label">
                            <?php
                            $routew_method_title = $order->get_payment_method_title();
                            if ('' !== $routew_method_title) {
                                // translators: %s = payment method title, e.g. "Direct bank transfer".
                                printf(esc_html__('Prepaid — %s. Nothing to collect.', 'routemile-for-woocommerce'), esc_html($routew_method_title));
                            } else {
                                esc_html_e('Prepaid. Nothing to collect.', 'routemile-for-woocommerce');
                            }
                            ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="card-body routew-card-body">
                    <div class="routew-card-section">
                        <p class="mb-2"><?php echo routew_agent_icon('person'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                            <strong><?php esc_html_e('Customer:', 'routemile-for-woocommerce'); ?></strong>
                            <?php echo esc_html($order->get_formatted_billing_full_name()); ?></p>
                        <p class="mb-2"><?php echo routew_agent_icon('phone'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                            <strong><?php esc_html_e('Phone:', 'routemile-for-woocommerce'); ?></strong>
                            <a class="routew-call-link"
                                href="tel:<?php echo esc_attr($order->get_billing_phone()); ?>"><?php echo esc_html($order->get_billing_phone()); ?></a>
                        </p>

                        <?php if ($delivery_address): ?>
                            <p class="mb-2"><?php echo routew_agent_icon('home'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                                <strong><?php esc_html_e('Delivery Address:', 'routemile-for-woocommerce'); ?></strong>
                                <?php echo esc_html($delivery_address); ?></p>
                            <?php if ($delivery_distance): ?>
                                <p class="mb-2"><?php echo routew_agent_icon('ruler'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                                    <strong><?php esc_html_e('Distance:', 'routemile-for-woocommerce'); ?></strong>
                                    <?php echo esc_html($delivery_distance); ?> km</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="mb-2"><?php echo routew_agent_icon('home'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                                <strong><?php esc_html_e('Address:', 'routemile-for-woocommerce'); ?></strong>
                                <?php echo wp_kses_post($shipping_address); ?></p>
                            <?php if ($unit): ?>
                                <p class="mb-2"><?php echo routew_agent_icon('box'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                                    <strong><?php esc_html_e('Unit:', 'routemile-for-woocommerce'); ?></strong>
                                    <?php echo esc_html($unit); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($instructions): ?>
                        <div class="routew-customer-note">
                            <div class="routew-customer-note__title">
                                <?php echo routew_agent_icon('note'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                                <?php esc_html_e('Delivery instructions', 'routemile-for-woocommerce'); ?>
                            </div>
                            <p class="routew-customer-note__line"><?php echo esc_html($instructions); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer routew-card-footer">
                    <?php
                    // Create precise map link using coordinates if available
                    if ($delivery_lat && $delivery_lng && is_numeric($delivery_lat) && is_numeric($delivery_lng)) {
                        // Use precise coordinate-based map link for exact pinpoint location
                        $map_url = "https://www.google.com/maps?q=" . urlencode(trim($delivery_lat) . ',' . trim($delivery_lng));
                        $map_label = __('Open Exact Location', 'routemile-for-woocommerce');
                        $map_class = 'routew-button-map-precise';
                    } elseif ($delivery_address) {
                        // Use delivery address if coordinates are missing but delivery address exists
                        $map_url = "https://www.google.com/maps/search/?api=1&query=" . urlencode(trim(wp_strip_all_tags($delivery_address)));
                        $map_label = __('Search Delivery Address', 'routemile-for-woocommerce');
                        $map_class = 'routew-button-map-address';
                    } else {
                        // Final fallback to shipping address
                        $map_url = "https://www.google.com/maps/search/?api=1&query=" . urlencode(trim(wp_strip_all_tags($shipping_address)));
                        $map_label = __('Search Location', 'routemile-for-woocommerce');
                        $map_class = 'routew-button-map-fallback';
                    }
                    ?>
                    <a href="<?php echo esc_url($map_url); ?>" target="_blank" rel="noopener noreferrer"
                        class="btn routew-button routew-button-map <?php echo esc_attr($map_class); ?>">
                        <?php echo routew_agent_icon('pin'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                        <?php echo esc_html($map_label); ?></a>
                    <a href="tel:<?php echo esc_attr($order->get_billing_phone()); ?>"
                        class="btn routew-button routew-button-call" aria-label="<?php echo esc_attr__('Call customer', 'routemile-for-woocommerce'); ?>">
                        <?php echo routew_agent_icon('phone'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                        <span class="routew-button-call__label"><?php esc_html_e('Call', 'routemile-for-woocommerce'); ?></span></a>
                    <?php if (!$history && 'routew-assigned' === $status): ?>
                        <button type="button" class="btn btn-primary routew-button routew-button-pickup routew-action-btn"
                            data-action="routew_update_delivery_status" data-status="routew-picked-up"
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('routew_delivery_action')); ?>">
                            <?php echo routew_agent_icon('box'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                            <?php esc_html_e('Mark Picked Up', 'routemile-for-woocommerce'); ?>
                        </button>
                    <?php endif; ?>
                    <?php if (!$history && 'routew-picked-up' === $status): ?>
                        <button type="button" class="btn btn-success routew-button routew-button-deliver routew-action-btn"
                            data-action="routew_update_delivery_status" data-status="completed"
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('routew_delivery_action')); ?>">
                            <?php echo routew_agent_icon('flag'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                            <?php esc_html_e('Mark Delivered', 'routemile-for-woocommerce'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php }
} // end function_exists guard

$routew_agent = wp_get_current_user();
$routew_state = ROUTEW_Delivery_Boy_View::build_dashboard_state(get_current_user_id());
$routew_cash = ROUTEW_Agent_Cash::get_agent_cash_summary(get_current_user_id());
$routew_delivery_boy_id = get_current_user_id();
$new_orders = wc_get_orders(array(
    'limit' => 50,
    'meta_key' => '_routew_delivery_boy_id',
    'meta_value' => $routew_delivery_boy_id,
    'status' => 'routew-assigned',
));
$picked_up_orders = wc_get_orders(array(
    'limit' => 50,
    'meta_key' => '_routew_delivery_boy_id',
    'meta_value' => $routew_delivery_boy_id,
    'status' => 'routew-picked-up',
));
$delivered_orders = wc_get_orders(array(
    'limit' => 50,
    'meta_key' => '_routew_delivery_boy_id',
    'meta_value' => $routew_delivery_boy_id,
    'status' => array('completed'),
    'orderby' => 'date',
    'order' => 'DESC',
));
// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
// Note: the NonPrefixedVariableFound disable stays active until EOF.
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#E85D04">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?php echo esc_url(add_query_arg('routew_agent_manifest', '1', home_url('/'))); ?>">
    <link rel="apple-touch-icon" href="<?php echo esc_url(ROUTEW_PLUGIN_URL . 'assets/img/agent-icon.svg'); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php esc_attr_e('FX Agent', 'routemile-for-woocommerce'); ?>">
    <?php wp_head(); ?>
</head>

<body <?php body_class('routew-delivery-dashboard-page'); ?>>
    <div class="routew-app-dashboard"
        data-routew-state="<?php echo esc_attr($routew_state['signature']); ?>"
        data-routew-counts="<?php echo esc_attr(wp_json_encode($routew_state['counts'])); ?>">
        <header class="routew-app-header">
            <div class="routew-app-header__row">
                <div class="routew-app-header__brand">
                    <span class="routew-app-header__logo" aria-hidden="true">
                        <?php echo routew_agent_icon('box'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                    </span>
                    <div class="routew-app-header__titles">
                        <h1><?php esc_html_e('RouteMile Agent', 'routemile-for-woocommerce'); ?></h1>
                        <p class="routew-app-header__agent">
                            <?php echo esc_html($routew_agent->display_name); ?>
                            <span class="routew-app-header__dot" aria-hidden="true"></span>
                            <span class="routew-app-header__date"><?php echo esc_html(date_i18n(get_option('date_format'))); ?></span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="routew-agent-stats" role="status">
                <div class="routew-agent-stat">
                    <span class="routew-agent-stat__value"><?php echo absint($routew_state['today']['delivered']); ?></span>
                    <span class="routew-agent-stat__label"><?php esc_html_e('Delivered today', 'routemile-for-woocommerce'); ?></span>
                </div>
                <div class="routew-agent-stat">
                    <span class="routew-agent-stat__value"><?php echo absint($routew_state['today']['active']); ?></span>
                    <span class="routew-agent-stat__label"><?php esc_html_e('Active now', 'routemile-for-woocommerce'); ?></span>
                </div>
                <div class="routew-agent-stat">
                    <span class="routew-agent-stat__value"><?php echo wp_kses_post(wc_price($routew_state['today']['collected'], array('currency' => get_woocommerce_currency()))); ?></span>
                    <span class="routew-agent-stat__label"><?php esc_html_e('Collected today', 'routemile-for-woocommerce'); ?></span>
                </div>
                <div class="routew-agent-stat routew-agent-stat--due">
                    <span class="routew-agent-stat__value"><?php echo wp_kses_post(wc_price($routew_cash['unsettled'], array('currency' => get_woocommerce_currency()))); ?></span>
                    <span class="routew-agent-stat__label"><?php esc_html_e('To hand over', 'routemile-for-woocommerce'); ?></span>
                </div>
                <div class="routew-agent-stat">
                    <span class="routew-agent-stat__value"><?php echo absint($routew_state['counts']['delivered']); ?></span>
                    <span class="routew-agent-stat__label"><?php esc_html_e('All-time delivered', 'routemile-for-woocommerce'); ?></span>
                </div>
            </div>
            <?php if ($routew_cash['pending']): ?>
                <div class="alert routew-settle-bar routew-settle-bar--pending" role="status">
                    <span class="routew-settle-bar__text">
                        <?php
                        // translators: 1: formatted amount.
                        printf(esc_html__('Hand-over of %1$s sent — waiting for manager approval.', 'routemile-for-woocommerce'),
                            wp_kses_post(wc_price($routew_cash['pending']['amount'], array('currency' => get_woocommerce_currency())))
                        );
                        ?>
                    </span>
                </div>
            <?php elseif ($routew_cash['unsettled'] > 0): ?>
                <div class="alert routew-settle-bar routew-settle-bar--active">
                    <span class="routew-settle-bar__text">
                        <?php
                        // translators: %s = formatted amount.
                        printf(esc_html__('You are holding %s of the store\'s cash.', 'routemile-for-woocommerce'),
                            wp_kses_post(wc_price($routew_cash['unsettled'], array('currency' => get_woocommerce_currency())))
                        );
                        ?>
                    </span>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('routew_settle_cash_' . get_current_user_id()); ?>
                        <input type="hidden" name="action" value="routew_settle_agent_cash" />
                        <input type="hidden" name="agent_id" value="<?php echo esc_attr(get_current_user_id()); ?>" />
                        <button type="submit" class="btn btn-primary routew-settle-btn" data-routew-settle-amount="<?php echo esc_attr(wp_strip_all_tags(wc_price($routew_cash['unsettled']))); ?>">
                            <?php echo routew_agent_icon('cash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                            <?php esc_html_e('Request hand-over', 'routemile-for-woocommerce'); ?>
                        </button>
                    </form>
                </div>
            <?php elseif ($routew_cash['last_accepted']): ?>
                <p class="routew-settle-last text-secondary small px-3">
                    <?php
                    $reviewer = get_user_by('id', absint($routew_cash['last_accepted']['reviewed_by']));
                    // translators: 1: formatted amount, 2: date/time, 3: approver name.
                    printf(esc_html__('Last hand-over: %1$s on %2$s, approved by %3$s. All settled.', 'routemile-for-woocommerce'),
                        wp_kses_post(wc_price($routew_cash['last_accepted']['amount'], array('currency' => get_woocommerce_currency()))),
                        esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $routew_cash['last_accepted']['reviewed_at'])),
                        esc_html($reviewer ? $reviewer->display_name : __('manager', 'routemile-for-woocommerce'))
                    );
                    ?>
                </p>
            <?php endif; ?>
            <?php if ($routew_state['cod']['count'] > 0): ?>
                <div class="routew-cod-summary" role="status">
                    <?php echo routew_agent_icon('cash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                    <span class="routew-cod-summary__text">
                        <?php
                        // translators: 1 = formatted amount, 2 = number of orders.
                        printf(esc_html__('Cash to collect: %1$s across %2$s COD order(s)', 'routemile-for-woocommerce'),
                            wp_kses_post(wc_price($routew_state['cod']['total'], array('currency' => get_woocommerce_currency()))),
                            absint($routew_state['cod']['count'])
                        );
                        ?>
                    </span>
                </div>
            <?php endif; ?>
        </header>

        <main class="routew-app-main">
            <div id="new-orders" class="routew-tab-content active" role="tabpanel" aria-labelledby="tab-new-orders">
                <?php if ($new_orders): ?>
                    <?php foreach ($new_orders as $order): ?>
                        <?php routew_render_order_card($order); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="routew-no-orders">
                        <?php echo routew_agent_icon('box'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                        <p><?php esc_html_e('No new deliveries assigned.', 'routemile-for-woocommerce'); ?></p>
                        <p class="routew-no-orders__hint"><?php esc_html_e('New orders appear here automatically — leave the app open.', 'routemile-for-woocommerce'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div id="in-progress" class="routew-tab-content" role="tabpanel" aria-labelledby="tab-in-progress">
                <?php if ($picked_up_orders): ?>
                    <?php foreach ($picked_up_orders as $order): ?>
                        <?php routew_render_order_card($order); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="routew-no-orders">
                        <?php echo routew_agent_icon('clock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                        <p><?php esc_html_e('No deliveries in progress.', 'routemile-for-woocommerce'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div id="delivered" class="routew-tab-content" role="tabpanel" aria-labelledby="tab-delivered">
                <?php if ($delivered_orders): ?>
                    <?php foreach ($delivered_orders as $order): ?>
                        <?php routew_render_order_card($order, array('history' => true)); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="routew-no-orders">
                        <?php echo routew_agent_icon('flag'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                        <p><?php esc_html_e('No delivered orders yet.', 'routemile-for-woocommerce'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <nav class="routew-tabbar" role="tablist" aria-label="<?php esc_attr_e('Delivery status filters', 'routemile-for-woocommerce'); ?>">
            <button type="button" id="tab-new-orders" class="routew-tab-link active" role="tab" aria-selected="true"
                aria-controls="new-orders" data-routew-tab="new-orders">
                <?php echo routew_agent_icon('box'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                <span class="routew-tabbar__label"><?php esc_html_e('New', 'routemile-for-woocommerce'); ?></span>
                <span class="routew-tabbar__count"><?php echo count($new_orders); ?></span>
            </button>
            <button type="button" id="tab-in-progress" class="routew-tab-link" role="tab" aria-selected="false"
                aria-controls="in-progress" data-routew-tab="in-progress">
                <?php echo routew_agent_icon('clock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                <span class="routew-tabbar__label"><?php esc_html_e('In Progress', 'routemile-for-woocommerce'); ?></span>
                <span class="routew-tabbar__count"><?php echo count($picked_up_orders); ?></span>
            </button>
            <button type="button" id="tab-delivered" class="routew-tab-link" role="tab" aria-selected="false"
                aria-controls="delivered" data-routew-tab="delivered">
                <?php echo routew_agent_icon('flag'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
                <span class="routew-tabbar__label"><?php esc_html_e('Delivered', 'routemile-for-woocommerce'); ?></span>
                <span class="routew-tabbar__count"><?php echo count($delivered_orders); ?></span>
            </button>
        </nav>

        <div id="routew-toast" class="routew-toast" role="status" aria-live="polite" hidden></div>
    </div>

    <?php wp_footer(); ?>
</body>

</html>
<?php // phpcs:enable ?>
