<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order Picked Up Email.
 *
 * Sent when an order status changes to "Picked Up" (out for delivery).
 *
 * @since      1.1.0
 * @package    RouteMile
 * @extends    WC_Email
 */
class ROUTEW_Email_Picked_Up extends WC_Email
{

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->id = 'routew_order_picked_up';
        $this->customer_email = true;
        $this->title = __('Order Picked Up', 'routemile-for-woocommerce');
        $this->description = __('This email is sent when the order is picked up and out for delivery.', 'routemile-for-woocommerce');
        $this->heading = __('Your order is on its way!', 'routemile-for-woocommerce');
        $this->subject = __('{site_title}: Your order #{order_number} is out for delivery', 'routemile-for-woocommerce');

        $this->template_html = 'emails/routew-order-status.php';
        $this->template_plain = 'emails/plain/routew-order-status.php';
        $this->template_base = ROUTEW_PLUGIN_DIR . 'templates/';

        $this->placeholders = array(
            '{site_title}' => '',
            '{order_date}' => '',
            '{order_number}' => '',
        );

        // Triggers for this email
        add_action('woocommerce_order_status_routew-picked-up', array($this, 'trigger'), 10, 2);

        parent::__construct();
    }

    /**
     * Get email subject.
     *
     * @return string
     */
    public function get_default_subject()
    {
        return __('{site_title}: Your order #{order_number} is out for delivery', 'routemile-for-woocommerce');
    }

    /**
     * Get email heading.
     *
     * @return string
     */
    public function get_default_heading()
    {
        return __('Your order is on its way! 🎉', 'routemile-for-woocommerce');
    }

    /**
     * Trigger the sending of this email.
     *
     * @param int            $order_id The order ID.
     * @param WC_Order|false $order Order object.
     */
    public function trigger($order_id, $order = false)
    {
        $this->setup_locale();

        if ($order_id && !is_a($order, 'WC_Order')) {
            $order = wc_get_order($order_id);
        }

        if (is_a($order, 'WC_Order')) {
            $this->object = $order;
            $this->recipient = $this->object->get_billing_email();
            $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
            $this->placeholders['{order_number}'] = $this->object->get_order_number();
        }

        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }

        $this->restore_locale();
    }

    /**
     * Get content HTML.
     *
     * @return string
     */
    public function get_content_html()
    {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'status_message' => __('Your order has been picked up and is on its way to you!', 'routemile-for-woocommerce'),
                'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $this,
            ),
            '',
            $this->template_base
        );
    }

    /**
     * Get content plain.
     *
     * @return string
     */
    public function get_content_plain()
    {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'status_message' => __('Your order has been picked up and is on its way to you!', 'routemile-for-woocommerce'),
                'sent_to_admin' => false,
                'plain_text' => true,
                'email' => $this,
            ),
            '',
            $this->template_base
        );
    }

    /**
     * Default content to show below main email content.
     *
     * @return string
     */
    public function get_default_additional_content()
    {
        return __('Get ready to receive your delicious order!', 'routemile-for-woocommerce');
    }
}
