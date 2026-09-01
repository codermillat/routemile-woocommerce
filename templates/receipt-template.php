<?php
/**
 * Restaurant Bill Style Receipt Template for RouteMile Orders
 *
 * @package RouteMile
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Every variable below is a TEMPLATE-LOCAL (`$order_id`, `$store_name`,
// `$receipt_logo`, etc.) — declared with `=` at file scope inside this
// include-only template, never reassigned from a function. PHPCS sees
// them at file scope and flags them as "global"; they're not. The receipt
// template is included exactly once (after a nonce + capability check)
// by the renderer and exits immediately, so a global namespace collision
// is impossible. Re-enabled at EOF.

// Security check
if (!defined('ABSPATH')) {
    exit;
}

// Get order data - must be set by print_receipt_template() after nonce/capability check.
// Do not fall back to $_GET; this template is only included after verification.
global $order;

// If still no order, exit with error
if (!$order || !is_a($order, 'WC_Order')) {
    wp_die(esc_html__('Invalid order.', 'routemile-for-woocommerce'));
}

// Get order data
$order_id = $order->get_id();
$order_number = $order->get_order_number();
$order_date = $order->get_date_created();
$billing_address = $order->get_formatted_billing_address();
$shipping_address = $order->get_formatted_shipping_address();
$payment_method = $order->get_payment_method_title();
$order_total = $order->get_total();
$currency_symbol = get_woocommerce_currency_symbol($order->get_currency());

/**
 * Expand a state/province code to its full name for the printed address
 * (WC stores the code, e.g. "UP", while bills should read "Uttar Pradesh").
 *
 * @param string $state State code stored on the order.
 * @param string $country Country code stored on the order.
 * @return string Full state name, or the raw value when unknown.
 */
if (!function_exists('routew_receipt_state_name')) {
    function routew_receipt_state_name($state, $country)
    {
        if ($state && function_exists('WC') && WC()->countries) {
            $states = WC()->countries->get_states($country);
            if (is_array($states) && isset($states[$state])) {
                return $states[$state];
            }
        }
        return $state;
    }
}

// Compose the delivery address with explicit ", " separators. WC's formatted
// address drops its separators when city/postcode are empty (FXW hides those
// fields at checkout), which rendered as "USE NAMEGammaUttar Pradesh".
$delivery_address_parts = array_filter(array(
    $order->get_shipping_address_1(),
    $order->get_shipping_address_2(),
    $order->get_shipping_city(),
    routew_receipt_state_name($order->get_shipping_state(), $order->get_shipping_country()),
    $order->get_shipping_postcode(),
));
$delivery_address_line = implode(', ', $delivery_address_parts);
if ('' === $delivery_address_line && $shipping_address) {
    $delivery_address_line = str_replace(
        array('<br>', '<br/>', '<br />'),
        ', ',
        wp_strip_all_tags($shipping_address)
    );
}

// Items subtotal before discounts (drives the bill breakdown below).
$items_subtotal = 0.0;

// Get delivery boy info
$delivery_boy_id = $order->get_meta('_routew_delivery_boy_id');
$delivery_boy = $delivery_boy_id ? get_user_by('id', $delivery_boy_id) : null;

// Get RouteMile receipt branding settings
$routew_options = get_option('routew_settings', array());

// Receipt Logo
$receipt_logo = isset($routew_options['routew_receipt_logo']) ? $routew_options['routew_receipt_logo'] : '';

// Restaurant Name - prioritize FXW setting, fallback to WooCommerce, then site name
$store_name = isset($routew_options['routew_receipt_restaurant_name']) && !empty($routew_options['routew_receipt_restaurant_name'])
    ? $routew_options['routew_receipt_restaurant_name']
    : get_bloginfo('name');

// Restaurant Address - prioritize FXW setting, fallback to WooCommerce store address
$receipt_address = isset($routew_options['routew_receipt_address']) && !empty($routew_options['routew_receipt_address'])
    ? $routew_options['routew_receipt_address']
    : '';

// If no FXW address, build from WooCommerce settings
if (empty($receipt_address)) {
    $store_address = get_option('woocommerce_store_address');
    $store_city = get_option('woocommerce_store_city');
    $store_postcode = get_option('woocommerce_store_postcode');
    $receipt_address = implode(', ', array_filter(array($store_address, $store_city, $store_postcode)));
}

// Restaurant Phone - prioritize FXW setting
$store_phone = isset($routew_options['routew_receipt_phone']) && !empty($routew_options['routew_receipt_phone'])
    ? $routew_options['routew_receipt_phone']
    : get_option('woocommerce_store_phone');

// Tagline (optional)
$receipt_tagline = isset($routew_options['routew_receipt_tagline']) ? $routew_options['routew_receipt_tagline'] : '';

// Footer Message
$receipt_footer = isset($routew_options['routew_receipt_footer_message']) && !empty($routew_options['routew_receipt_footer_message'])
    ? $routew_options['routew_receipt_footer_message']
    : __('Thank You! Have a great day!', 'routemile-for-woocommerce');

// Get unit/flat info
$unit = $order->get_meta('_routew_address_unit');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php printf(
        /* translators: %s: order number. */
        esc_html__('Bill - Order #%s', 'routemile-for-woocommerce'),
        esc_html($order_number)
    ); ?></title>
    <?php
    // The receipt is a standalone printable page (rendered via include_once
    // + exit after a nonce + capability check). It is NOT inside wp_head,
    // so wp_head/wp_footer do not fire here. Register the assets, enqueue
    // them, then call wp_print_styles() / wp_print_scripts() to emit the
    // <link>/<script> tags — these are the documented WP APIs that PHPCS
    // recognises as compliant (replaces raw <link>/<script> tags).
    $receipt_css_url = ROUTEW_PLUGIN_URL . 'assets/css/receipt.css';
    $receipt_js_url  = ROUTEW_PLUGIN_URL . 'assets/js/receipt-print.js';
    wp_register_style( 'routew-receipt', $receipt_css_url, array(), ROUTEW_VERSION );
    wp_register_script( 'routew-receipt', $receipt_js_url, array(), ROUTEW_VERSION, false );
    wp_enqueue_style( 'routew-receipt' );
    wp_enqueue_script( 'routew-receipt' );
    wp_print_styles( 'routew-receipt' );
    wp_print_scripts( 'routew-receipt' );
    ?>
</head>

<body>
    <div class="bill-container">
        <!-- Restaurant Header -->
        <div class="bill-header">
            <?php if ($receipt_logo): ?>
                <img src="<?php echo esc_url($receipt_logo); ?>" alt="<?php echo esc_attr($store_name); ?>"
                    class="receipt-logo" style="max-width: 180px; max-height: 60px; margin-bottom: 10px;">
            <?php endif; ?>
            <div class="restaurant-name"><?php echo esc_html($store_name); ?></div>
            <?php if ($receipt_tagline): ?>
                <div class="restaurant-tagline" style="font-size: 11px; font-style: italic; margin-bottom: 5px;">
                    <?php echo esc_html($receipt_tagline); ?></div>
            <?php endif; ?>
            <?php if ($receipt_address): ?>
                <div class="restaurant-address"><?php echo esc_html($receipt_address); ?></div>
            <?php endif; ?>
            <?php if ($store_phone): ?>
                <div class="restaurant-address">
                    <?php printf(
                        /* translators: %s: store phone number. */
                        esc_html__('Phone: %s', 'routemile-for-woocommerce'),
                        esc_html($store_phone)
                    ); ?></div>
            <?php endif; ?>
            <div class="bill-title"><?php esc_html_e('Delivery Bill', 'routemile-for-woocommerce'); ?></div>
        </div>

        <!-- Bill Information -->
        <div class="bill-info">
            <div class="bill-row">
                <span><?php esc_html_e('Order No:', 'routemile-for-woocommerce'); ?></span>
                <span><?php echo esc_html($order_number); ?></span>
            </div>
            <div class="bill-row">
                <span><?php esc_html_e('Date:', 'routemile-for-woocommerce'); ?></span>
                <span><?php echo $order_date ? esc_html($order_date->format('j M Y g:i A')) : esc_html__('N/A', 'routemile-for-woocommerce'); ?></span>
            </div>
            <?php if ($delivery_boy): ?>
                <div class="bill-row">
                    <span><?php esc_html_e('Delivery By:', 'routemile-for-woocommerce'); ?></span>
                    <span><?php echo esc_html($delivery_boy->display_name); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Customer Information -->
        <div class="customer-info">
            <div class="section-title"><?php esc_html_e('Customer Details', 'routemile-for-woocommerce'); ?></div>
            <div class="customer-details">
                <div><strong><?php echo esc_html($order->get_formatted_billing_full_name()); ?></strong></div>
                <?php if ($order->get_billing_phone()): ?>
                    <div><?php echo esc_html($order->get_billing_phone()); ?></div>
                <?php endif; ?>
                <br>
                <div><strong><?php esc_html_e('Delivery Address:', 'routemile-for-woocommerce'); ?></strong></div>
                <div><?php echo esc_html($delivery_address_line); ?></div>
                <?php if ($unit): ?>
                    <div><?php printf(
                        /* translators: %s: unit or flat number. */
                        esc_html__('Unit/Flat: %s', 'routemile-for-woocommerce'),
                        esc_html($unit)
                    ); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items -->
        <div class="items-section">
            <div class="section-title"><?php esc_html_e('Items Ordered', 'routemile-for-woocommerce'); ?></div>
            <div class="separator">━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</div>

            <?php foreach ($order->get_items() as $item_id => $item): ?>
                <?php
                $quantity = $item->get_quantity();
                $unit_price = $quantity > 0 ? (float) $item->get_total() / $quantity : 0;
                $line_total = wc_price($item->get_total(), array('currency' => $order->get_currency()));
                $items_subtotal += method_exists($item, 'get_subtotal') ? (float) $item->get_subtotal() : (float) $item->get_total();
                ?>
                <div class="item-row">
                    <div style="flex: 1;">
                        <div class="item-name"><?php echo esc_html($item->get_name()); ?></div>
                        <?php
                        // Show item meta (variations, add-ons, etc.)
                        $item_meta = wc_display_item_meta($item, array(
                            'before' => '',
                            'after' => '',
                            'separator' => ', ',
                            'echo' => false,
                        ));
                        if ($item_meta) {
                            echo '<div class="item-meta">' . esc_html(wp_strip_all_tags($item_meta)) . '</div>';
                        }
                        ?>
                    </div>
                    <div class="item-qty-price">
                        <div><?php printf(
                            /* translators: 1: item quantity, 2: formatted unit price. */
                            esc_html__('%1$d × %2$s', 'routemile-for-woocommerce'),
                            (int) $quantity,
                            wp_kses_post(wc_price($unit_price, array('currency' => $order->get_currency())))
                        ); ?></div>
                        <div class="item-total"><?php echo wp_kses_post($line_total); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Bill Totals -->
        <div class="bill-totals">
            <div class="total-row">
                <span><?php esc_html_e('Subtotal:', 'routemile-for-woocommerce'); ?></span>
                <span><?php echo wp_kses_post(wc_price($items_subtotal, array('currency' => $order->get_currency()))); ?></span>
            </div>

            <?php foreach ($order->get_order_item_totals() as $key => $total): ?>
                <?php if (in_array($key, array('order_total', 'payment_method', 'cart_subtotal'), true))
                    continue; // Subtotal shown above; total + payment shown below ?>
                <div class="total-row">
                    <span><?php echo esc_html('shipping' === $key ? __('Delivery Fee:', 'routemile-for-woocommerce') : $total['label']); ?></span>
                    <span><?php echo wp_kses_post($total['value']); ?></span>
                </div>
            <?php endforeach; ?>

            <div class="total-row final">
                <span><?php esc_html_e('TOTAL AMOUNT:', 'routemile-for-woocommerce'); ?></span>
                <span><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
            </div>
        </div>

        <!-- Payment Information -->
        <?php if ('cod' === $order->get_payment_method()): ?>
            <div class="payment-section">
                <div class="payment-method"><?php esc_html_e('Cash on Delivery', 'routemile-for-woocommerce'); ?></div>
                <div class="amount-to-collect">
                    <?php printf(
                        /* translators: %s: formatted order total to collect. */
                        esc_html__('COLLECT: %s', 'routemile-for-woocommerce'),
                        wp_kses_post($order->get_formatted_order_total())
                    ); ?>
                </div>
            </div>
        <?php else: ?>
            <div class="payment-section">
                <div class="payment-method"><?php echo esc_html(strtoupper($payment_method)); ?></div>
                <div><?php esc_html_e('PAYMENT COMPLETED', 'routemile-for-woocommerce'); ?></div>
            </div>
        <?php endif; ?>

        <?php
        // Show customer note if any
        $customer_note = $order->get_customer_note();
        if ($customer_note):
            ?>
            <div class="delivery-section">
                <div class="section-title"><?php esc_html_e('Special Instructions', 'routemile-for-woocommerce'); ?></div>
                <div style="font-size: 12px;"><?php echo esc_html($customer_note); ?></div>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="bill-footer">
            <div class="thank-you"><?php echo esc_html($receipt_footer); ?></div>
            <br>
            <div><?php printf(
                /* translators: %s: formatted print timestamp. */
                esc_html__('Printed: %s', 'routemile-for-woocommerce'),
                esc_html(current_time('j M Y g:i A'))
            ); ?></div>
        </div>
    </div>
</body>

</html>
<?php // phpcs:enable ?>