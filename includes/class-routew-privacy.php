<?php
/**
 * Privacy-framework integration (WooCommerce + WordPress core).
 *
 * The plugin stores personal data in two places:
 *  - user meta `_routew_delivery_profile` (pin lat/lng, exact address,
 *    landmark, instructions) for logged-in customers
 *  - order meta (address fields, landmark, instructions, coordinates)
 *
 * This class surfaces both in WordPress privacy tools (Tools → Export /
 * Erase Personal Data) using only documented hooks:
 *  - wp_privacy_personal_data_exporters / _erasers (profile)
 *  - woocommerce_privacy_export_order_personal_data (order export rows)
 *  - woocommerce_privacy_before_remove_order_personal_data (order erasure)
 *
 * @since      1.2.6
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
    exit;
}

class ROUTEW_Privacy
{

    /**
     * Order meta keys treated as customer personal data, with export labels.
     * Includes the pre-1.2.2 structured-field keys so legacy orders are
     * covered by export/erasure too.
     *
     * @var array<string, string>
     */
    private $order_meta_keys = array(
        '_routew_address_details'    => 'Exact Delivery Address',
        '_routew_landmark'           => 'Delivery Landmark',
        '_routew_delivery_instructions' => 'Delivery Instructions',
        '_routew_delivery_address'   => 'Full Delivery Address',
        '_routew_delivery_lat'       => 'Delivery Location Latitude',
        '_routew_delivery_lng'       => 'Delivery Location Longitude',
        '_routew_house_flat_no'      => 'House / Flat No. (legacy)',
        '_routew_floor_no'           => 'Floor No. (legacy)',
        '_routew_society_building'   => 'Society / Building Name (legacy)',
        '_routew_block_tower_area'   => 'Block / Tower / Area (legacy)',
    );

    /**
     * Keys of the `_routew_delivery_profile` user meta, with export labels.
     *
     * @var array<string, string>
     */
    private $profile_keys = array(
        'address_details'        => 'Exact Delivery Address',
        'landmark'               => 'Delivery Landmark',
        'delivery_instructions'  => 'Delivery Instructions',
        'lat'                    => 'Delivery Location Latitude',
        'lng'                    => 'Delivery Location Longitude',
    );

    /**
     * Register privacy hooks.
     *
     * @since 1.2.6
     */
    public function __construct()
    {
        add_filter('wp_privacy_personal_data_exporters', array($this, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array($this, 'register_eraser'));
        add_filter('woocommerce_privacy_export_order_personal_data', array($this, 'export_order_meta'), 10, 2);
        add_action('woocommerce_privacy_before_remove_order_personal_data', array($this, 'remove_order_meta'));
    }

    /**
     * Add our profile exporter to the WordPress privacy exporters list.
     *
     * @param array $exporters Registered exporters.
     * @return array
     * @since 1.2.6
     */
    public function register_exporter($exporters)
    {
        $exporters['routemile-delivery-profile'] = array(
            'exporter_friendly_name' => __('RouteMile Delivery Profile', 'routemile-woocommerce'),
            'callback' => array($this, 'export_profile'),
        );
        return $exporters;
    }

    /**
     * Add our profile eraser to the WordPress privacy erasers list.
     *
     * @param array $erasers Registered erasers.
     * @return array
     * @since 1.2.6
     */
    public function register_eraser($erasers)
    {
        $erasers['routemile-delivery-profile'] = array(
            'eraser_friendly_name' => __('RouteMile Delivery Profile', 'routemile-woocommerce'),
            'callback' => array($this, 'erase_profile'),
        );
        return $erasers;
    }

    /**
     * Export the saved delivery profile for a given email address.
     *
     * @param string $email_address Customer email.
     * @param int    $page          Exporter page (not paged; always done).
     * @return array
     * @since 1.2.6
     */
    public function export_profile($email_address, $page = 1)
    {
        $data = array();
        $user = get_user_by('email', $email_address);

        if ($user) {
            $profile = get_user_meta($user->ID, '_routew_delivery_profile', true);
            if (is_array($profile)) {
                $rows = array();
                foreach ($this->profile_keys as $key => $label) {
                    if (isset($profile[$key]) && null !== $profile[$key] && '' !== $profile[$key]) {
                        $rows[] = array(
                            'name' => $label,
                            'value' => $profile[$key],
                        );
                    }
                }
                if (!empty($rows)) {
                    $data[] = array(
                        'group_id' => 'routemile-delivery-profile',
                        'group_label' => __('RouteMile Delivery Profile', 'routemile-woocommerce'),
                        'item_id' => 'user-' . $user->ID,
                        'data' => $rows,
                    );
                }
            }
        }

        return array(
            'data' => $data,
            'done' => true,
        );
    }

    /**
     * Erase the saved delivery profile for a given email address.
     *
     * @param string $email_address Customer email.
     * @param int    $page          Eraser page (not paged; always done).
     * @return array
     * @since 1.2.6
     */
    public function erase_profile($email_address, $page = 1)
    {
        $removed = false;
        $user = get_user_by('email', $email_address);

        if ($user) {
            $profile = get_user_meta($user->ID, '_routew_delivery_profile', true);
            if (!empty($profile)) {
                delete_user_meta($user->ID, '_routew_delivery_profile');
                $removed = true;
            }
        }

        return array(
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => array(),
            'done' => true,
        );
    }

    /**
     * Append RouteMile order meta to WooCommerce's order data export.
     *
     * @param array    $personal_data Existing export rows.
     * @param WC_Order $order         Order being exported.
     * @return array
     * @since 1.2.6
     */
    public function export_order_meta($personal_data, $order)
    {
        if (!is_a($order, 'WC_Order')) {
            return $personal_data;
        }

        foreach ($this->order_meta_keys as $meta_key => $label) {
            $value = $order->get_meta($meta_key, true);
            if ('' !== (string) $value) {
                $personal_data[] = array(
                    'name' => $label,
                    'value' => $value,
                );
            }
        }

        return $personal_data;
    }

    /**
     * Remove RouteMile personal data from an order being anonymized.
     *
     * Runs on woocommerce_privacy_before_remove_order_personal_data, the
     * documented action where extensions remove their own order data; core
     * persists the order afterwards.
     *
     * @param WC_Order $order Order being erased.
     * @since 1.2.6
     */
    public function remove_order_meta($order)
    {
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        foreach (array_keys($this->order_meta_keys) as $meta_key) {
            if ('' !== (string) $order->get_meta($meta_key, true)) {
                $order->delete_meta_data($meta_key);
            }
        }
    }
}

new ROUTEW_Privacy();
