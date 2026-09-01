<?php
/**
 * RouteMile Order Status Email Template (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/routew-order-status.php
 *
 * @package RouteMile/Templates/Emails
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action('woocommerce_email_header', $email_heading, $email);
?>

<p><?php echo wp_kses_post($status_message); ?></p>

<h2>
    <?php
    printf(
        /* translators: %s: Order number */
        esc_html__('Order #%s', 'routemile-woocommerce'),
        esc_html($order->get_order_number())
    );
    ?>
</h2>

<div style="margin-bottom: 40px;">
    <table class="td" cellspacing="0" cellpadding="6"
        style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
        <thead>
            <tr>
                <th class="td" scope="col" style="text-align:left;"><?php esc_html_e('Product', 'routemile-woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:left;"><?php esc_html_e('Quantity', 'routemile-woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:left;"><?php esc_html_e('Price', 'routemile-woocommerce'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            echo wc_get_email_order_items(
                $order,
                array(
                    'show_sku' => false,
                    'show_image' => false,
                    'image_size' => array(32, 32),
                    'plain_text' => $plain_text,
                    'sent_to_admin' => $sent_to_admin,
                )
            );
            ?>
        </tbody>
        <tfoot>
            <?php
            $item_totals = $order->get_order_item_totals();
            if ($item_totals) {
                $i = 0;
                foreach ($item_totals as $total) {
                    $i++;
                    ?>
                    <tr>
                        <th class="td" scope="row" colspan="2"
                            style="text-align:left; <?php echo (1 === $i) ? 'border-top-width: 4px;' : ''; ?>">
                            <?php echo wp_kses_post($total['label']); ?></th>
                        <td class="td" style="text-align:left; <?php echo (1 === $i) ? 'border-top-width: 4px;' : ''; ?>">
                            <?php echo wp_kses_post($total['value']); ?></td>
                    </tr>
                    <?php
                }
            }
            ?>
        </tfoot>
    </table>
</div>

<?php
/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 */
// Note: We're using a simplified table above instead of full order_details hook

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);
?>

<?php if ($additional_content): ?>
    <p style="margin-top: 20px;"><?php echo wp_kses_post(wpautop(wptexturize($additional_content))); ?></p>
<?php endif; ?>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_email_footer', $email);
?>