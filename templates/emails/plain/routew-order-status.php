<?php
/**
 * RouteMile Order Status Email Template (Plain Text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/routew-order-status.php
 *
 * @package RouteMile/Templates/Emails
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html(wp_strip_all_tags($email_heading));
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo wp_strip_all_tags($status_message) . "\n\n";

echo "----------------------------------------\n";
printf(
    /* translators: %s: Order number */
    esc_html__('Order #%s', 'routemile-woocommerce'),
    esc_html($order->get_order_number())
);
echo "\n";
echo "(" . wc_format_datetime($order->get_date_created()) . ")\n";
echo "----------------------------------------\n\n";

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 */
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n----------------------------------------\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 */
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n----------------------------------------\n\n";

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)));
    echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
