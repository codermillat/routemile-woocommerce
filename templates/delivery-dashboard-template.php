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

// Auth check: must be logged in with delivery access
if (!is_user_logged_in() || !current_user_can('routew_delivery_access')) {
    wp_safe_redirect(home_url());
    exit;
}


// Shared SVG icon + order-card helpers — extracted so the AJAX action
// handlers (class-routew-delivery-boy-view.php) can render a single
// order card on demand without loading this whole template.
require_once ROUTEW_PLUGIN_DIR . 'includes/agent-template-helpers.php';

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
    <meta name="theme-color" content="<?php echo esc_attr(ROUTEW_Brand_Color::pwa_color()); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?php echo esc_url(add_query_arg('routew_agent_manifest', '1', home_url('/'))); ?>">
    <link rel="apple-touch-icon" href="<?php echo esc_url(ROUTEW_PLUGIN_URL . 'assets/img/agent-icon.svg'); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php esc_attr_e('FX Agent', 'routemile-for-woocommerce'); ?>">
    <?php wp_head(); ?>
</head>

<body <?php body_class('routew-delivery-dashboard-page'); ?>>
    <div class="routew-ui routew-app-dashboard"
        data-routew-state="<?php echo esc_attr($routew_state['signature']); ?>"
        data-routew-counts="<?php echo esc_attr(wp_json_encode($routew_state['counts'])); ?>">
        <header class="routew-app-header">
            <div class="routew-app-header__row">
                <div class="routew-app-header__brand">
                    <span class="routew-app-header__logo" aria-hidden="true">
                        <?php routew_agent_icon_e('box'); // kses_post escaped SVG ?>
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
                    <span class="routew-agent-stat__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    </span>
                    <span class="routew-agent-stat__value"><?php echo absint($routew_state['today']['delivered']); ?></span>
                    <span class="routew-agent-stat__label"><?php esc_html_e('Delivered today', 'routemile-for-woocommerce'); ?></span>
                </div>
                <div class="routew-agent-stat">
                    <span class="routew-agent-stat__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 10.6 4 2.3-1 1.7-5-2.9V6h2v6.6Z"/></svg>
                    </span>
                    <span class="routew-agent-stat__value"><?php echo absint($routew_state['today']['active']); ?></span>
                    <span class="routew-agent-stat__label"><?php esc_html_e('Active now', 'routemile-for-woocommerce'); ?></span>
                </div>
                <div class="routew-agent-stat">
                    <span class="routew-agent-stat__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M3 6h18v12H3V6Zm9 2.5A3.5 3.5 0 1 0 12 15.5 3.5 3.5 0 0 0 12 8.5ZM5 8v2h2V8H5Zm12 6v2h2v-2h-2Z"/></svg>
                    </span>
                    <span class="routew-agent-stat__value"><?php echo wp_kses_post(wc_price($routew_state['today']['collected'], array('currency' => get_woocommerce_currency()))); ?></span>
                    <span class="routew-agent-stat__label"><?php esc_html_e('Collected today', 'routemile-for-woocommerce'); ?></span>
                </div>
                <div class="routew-agent-stat routew-agent-stat--due">
                    <span class="routew-agent-stat__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M5 3v18h2v-7h10.6l-2.2-4 2.2-4H7V3H5Z"/></svg>
                    </span>
                    <span class="routew-agent-stat__value"><?php echo wp_kses_post(wc_price($routew_cash['unsettled'], array('currency' => get_woocommerce_currency()))); ?></span>
                    <span class="routew-agent-stat__label"><?php esc_html_e('To hand over', 'routemile-for-woocommerce'); ?></span>
                </div>
                <div class="routew-agent-stat">
                    <span class="routew-agent-stat__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.3 6.3 3.2L12 10.7 5.7 7.5 12 4.3Z"/></svg>
                    </span>
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
                            <?php routew_agent_icon_e('cash'); // kses_post escaped SVG ?>
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
                    <?php routew_agent_icon_e('cash'); // kses_post escaped SVG ?>
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
                        <?php echo routew_render_order_card($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card template renders escaped markup ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="routew-no-orders">
                        <?php routew_agent_icon_e('box'); // kses_post escaped SVG ?>
                        <p><?php esc_html_e('No new deliveries assigned.', 'routemile-for-woocommerce'); ?></p>
                        <p class="routew-no-orders__hint"><?php esc_html_e('New orders appear here automatically — leave the app open.', 'routemile-for-woocommerce'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div id="in-progress" class="routew-tab-content" role="tabpanel" aria-labelledby="tab-in-progress">
                <?php if ($picked_up_orders): ?>
                    <?php foreach ($picked_up_orders as $order): ?>
                        <?php echo routew_render_order_card($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card template renders escaped markup ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="routew-no-orders">
                        <?php routew_agent_icon_e('clock'); // kses_post escaped SVG ?>
                        <p><?php esc_html_e('No deliveries in progress.', 'routemile-for-woocommerce'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div id="delivered" class="routew-tab-content" role="tabpanel" aria-labelledby="tab-delivered">
                <?php if ($delivered_orders): ?>
                    <?php foreach ($delivered_orders as $order): ?>
                        <?php echo routew_render_order_card($order, array('history' => true)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card template renders escaped markup ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="routew-no-orders">
                        <?php routew_agent_icon_e('flag'); // kses_post escaped SVG ?>
                        <p><?php esc_html_e('No delivered orders yet.', 'routemile-for-woocommerce'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <nav class="routew-tabbar" role="tablist" aria-label="<?php esc_attr_e('Delivery status filters', 'routemile-for-woocommerce'); ?>">
            <button type="button" id="tab-new-orders" class="routew-tab-link active" role="tab" aria-selected="true"
                aria-controls="new-orders" data-routew-tab="new-orders">
                <?php routew_agent_icon_e('box'); // kses_post escaped SVG ?>
                <span class="routew-tabbar__label"><?php esc_html_e('New', 'routemile-for-woocommerce'); ?></span>
                <span class="routew-tabbar__count"><?php echo count($new_orders); ?></span>
            </button>
            <button type="button" id="tab-in-progress" class="routew-tab-link" role="tab" aria-selected="false"
                aria-controls="in-progress" data-routew-tab="in-progress">
                <?php routew_agent_icon_e('clock'); // kses_post escaped SVG ?>
                <span class="routew-tabbar__label"><?php esc_html_e('In Progress', 'routemile-for-woocommerce'); ?></span>
                <span class="routew-tabbar__count"><?php echo count($picked_up_orders); ?></span>
            </button>
            <button type="button" id="tab-delivered" class="routew-tab-link" role="tab" aria-selected="false"
                aria-controls="delivered" data-routew-tab="delivered">
                <?php routew_agent_icon_e('flag'); // kses_post escaped SVG ?>
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
