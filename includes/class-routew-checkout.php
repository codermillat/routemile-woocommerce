<?php
/**
 * Checkout — Orchestrator
 *
 * Front-end integration surface for the checkout flow. Renders the
 * delivery fields on the WooCommerce checkout, customizes the default
 * field set, and pre-fills saved addresses for returning customers.
 *
 * As of 1.2.0, the larger checkout concerns live in two sibling classes
 * loaded below:
 *   - ROUTEW_Checkout_Maps   (frontend map assets + location AJAX)
 *   - ROUTEW_Checkout_Handler (validation, fees, save, label)
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-routew-checkout-maps.php';
require_once __DIR__ . '/class-routew-checkout-handler.php';
require_once __DIR__ . '/class-routew-blocks-checkout.php';

/**
 * Class ROUTEW_Checkout
 *
 * Orchestrates the checkout-page integration:
 *  - Renders the location picker and structured delivery-detail fields
 *  - Hides redundant WC billing/shipping fields
 *  - Pre-fills saved address for logged-in customers
 *
 * All hook registration for this class is in the constructor.
 *
 * @since 1.0.0
 */
class ROUTEW_Checkout
{

    /**
     * Register checkout-render hooks.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        // Must run on 'wp', not 'wp_loaded': is_checkout() needs the main
        // query parsed, which happens at 'wp' — at wp_loaded it was always
        // false, so the saved-address prefill silently never ran (1.2.15).
        add_action('wp', array($this, 'load_saved_address'));
        add_filter('woocommerce_checkout_fields', array($this, 'customize_checkout_fields'));
        add_action('woocommerce_before_checkout_billing_form', array($this, 'add_checkout_fields'));
        add_action('woocommerce_before_cart', array($this, 'render_store_closed_notice'));
        add_action('woocommerce_before_checkout_form', array($this, 'render_store_closed_notice'));
        // Block-based cart/checkout: the classic action hooks above don't
        // fire. Surface the same closed/minimum notices via the block
        // content filter so the `woocommerce/store-notices` block (always
        // present in WC cart/checkout templates) renders them on page load
        // (1.2.16). Bounded to is_cart()/is_checkout() so we don't touch
        // unrelated pages.
        add_filter('render_block', array($this, 'maybe_prepend_notices_to_blocks'), 10, 2);
    }

    /**
     * Is the store accepting delivery orders right now? Kept as the
     * checkout-facing name; the implementation lives in ROUTEW_Store_Hours,
     * which is loaded on every request (this class is not loaded in the
     * admin, so admin callers must use ROUTEW_Store_Hours directly).
     *
     * @return bool
     * @since 1.2.11
     */
    public static function is_store_open()
    {
        if (class_exists('ROUTEW_Store_Hours')) {
            return ROUTEW_Store_Hours::is_store_open();
        }

        $options = get_option('routew_settings');
        $is_open = isset($options['routew_is_open']) ? $options['routew_is_open'] : true;

        return is_bool($is_open) ? $is_open : in_array($is_open, array('yes', 'true', '1', 1), true);
    }

    /**
     * The Open/Closed switch controls order placement: when closed,
     * customers can still browse and fill their cart, but a clear
     * notice explains why they cannot order (and validation blocks
     * placement server-side). Rendered on the cart and classic
     * checkout pages; the blocks checkout gets its notice from
     * ROUTEW_Blocks_Checkout.
     *
     * @since 1.2.11
     */
    public function render_store_closed_notice()
    {
        if (self::is_store_open()) {
            return;
        }

        if (function_exists('wc_print_notice')) {
            $message = __('We are currently closed for deliveries. You can browse and fill your cart — ordering will be available as soon as we reopen.', 'routemile-for-woocommerce');
            if (class_exists('ROUTEW_Store_Hours')) {
                $hint = ROUTEW_Store_Hours::reopen_hint();
                if ('' !== $hint) {
                    $message .= ' ' . $hint;
                }
            }
            wc_print_notice($message, 'error', array('routew-store-closed'));
        }
    }

    /**
     * Prepend FXW notices (closed store / minimum order) to the
     * woocommerce/store-notices block on cart-block + checkout-block pages.
     * Bounded to is_cart()/is_checkout() and to the specific block so we
     * don't pollute other pages (1.2.16).
     *
     * @param string $block_content Rendered block HTML.
     * @param array  $block         Parsed block (blockName + attrs).
     * @return string
     */
    public function maybe_prepend_notices_to_blocks($block_content, $block)
    {
        if (!is_array($block) || empty($block['blockName'])) {
            return $block_content;
        }
        if ('woocommerce/store-notices' !== $block['blockName']) {
            return $block_content;
        }
        if (!function_exists('is_cart') || (!is_cart() && (!function_exists('is_checkout') || !is_checkout()))) {
            return $block_content;
        }
        if (!function_exists('wc_print_notice') || !function_exists('wc_get_notices')) {
            return $block_content;
        }

        // Capture printed notices, drain the queue so the block itself
        // doesn't render duplicates.
        $notices_html = '';
        if (!self::is_store_open()) {
            $message = __('We are currently closed for deliveries. You can browse and fill your cart — ordering will be available as soon as we reopen.', 'routemile-for-woocommerce');
            if (class_exists('ROUTEW_Store_Hours')) {
                $hint = ROUTEW_Store_Hours::reopen_hint();
                if ('' !== $hint) {
                    $message .= ' ' . $hint;
                }
            }
            ob_start();
            wc_print_notice($message, 'error', array('routew-store-closed'));
            $notices_html .= (string) ob_get_clean();
            wc_clear_notices();
        }
        if (class_exists('ROUTEW_Pricing')) {
            $min_error = ROUTEW_Pricing::minimum_order_error();
            if (null !== $min_error) {
                ob_start();
                wc_print_notice($min_error, 'notice', array('routew-minimum-order'));
                $notices_html .= (string) ob_get_clean();
                wc_clear_notices();
            }
        }

        return $notices_html . $block_content;
    }

    /**
     * Add custom fields to the classic checkout page.
     *
     * @since    1.0.0
     */
    public function add_checkout_fields()
    {
        // Get saved delivery details for logged-in users
        $saved_profile = array();
        if (is_user_logged_in()) {
            $saved_profile = get_user_meta(get_current_user_id(), '_routew_delivery_profile', true);
            if (!is_array($saved_profile)) {
                $saved_profile = array();
            }
        }

        self::render_location_picker();
        ?>
        <div id="routew-delivery-details-container" class="routew-ui routew-checkout-card routew-checkout-card--details">
            <div class="card-body routew-checkout-card__body">
                <div class="routew-checkout-step">
                    <span class="routew-checkout-step__num" aria-hidden="true">2</span>
                    <div class="routew-checkout-step__text">
                        <h3 class="routew-checkout-step__title"><?php esc_html_e('Address details', 'routemile-for-woocommerce'); ?></h3>
                        <p class="routew-checkout-step__sub"><?php esc_html_e('Help your rider find the exact door.', 'routemile-for-woocommerce'); ?></p>
                    </div>
                </div>

                <?php
                // Exact delivery address — single line, Zomato/Swiggy-style:
                // everything the agent needs to find the door (flat, house,
                // building, block) in one required field next to the map pin.
                woocommerce_form_field('routew_address_details', array(
                    'type' => 'text',
                    'class' => array('form-row-wide'),
                    'label_class' => array('form-label'),
                    'input_class' => array('form-control'),
                    'label' => __('House / Flat / Building No.', 'routemile-for-woocommerce'),
                    'placeholder' => __('e.g., Flat 4B, House 25, Tower A, Block 2', 'routemile-for-woocommerce'),
                    'required' => true,
                ), isset($saved_profile['address_details']) ? $saved_profile['address_details'] : WC()->checkout->get_value('routew_address_details'));

                // Landmark - Optional but helpful
                woocommerce_form_field('routew_landmark', array(
                    'type' => 'text',
                    'class' => array('form-row-wide'),
                    'label_class' => array('form-label'),
                    'input_class' => array('form-control'),
                    'label' => __('Nearby Landmark (optional)', 'routemile-for-woocommerce'),
                    'placeholder' => __('e.g., Near City Mall, Opposite Park', 'routemile-for-woocommerce'),
                    'required' => false,
                ), isset($saved_profile['landmark']) ? $saved_profile['landmark'] : WC()->checkout->get_value('routew_landmark'));

                // Delivery Instructions - Optional
                woocommerce_form_field('routew_delivery_instructions', array(
                    'type' => 'textarea',
                    'class' => array('form-row-wide'),
                    'label_class' => array('form-label'),
                    'input_class' => array('form-control'),
                    'label' => __('Delivery Instructions', 'routemile-for-woocommerce'),
                    'placeholder' => __('e.g., Ring the bell twice, Leave at the door, Call before arriving...', 'routemile-for-woocommerce'),
                    'required' => false,
                    'custom_attributes' => array(
                        'rows' => 2,
                    ),
                ), isset($saved_profile['delivery_instructions']) ? $saved_profile['delivery_instructions'] : WC()->checkout->get_value('routew_delivery_instructions'));
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Step-1 map location picker — shared by the classic
     * checkout (via the billing-form hook) and block-based checkout
     * pages (via ROUTEW_Blocks_Checkout's the_content filter). checkout.js
     * boots on the presence of #routew-map, so both surfaces behave
     * identically.
     *
     * @param bool $return Return the markup as a string instead of echoing.
     * @return string|null Markup when $return is true.
     * @since 1.2.9
     */
    public static function render_location_picker($return = false)
    {
        ob_start();
        ?>
        <div id="routew-location-picker-container" class="routew-ui routew-checkout-card routew-checkout-card--location">
            <div class="card-body routew-checkout-card__body">
                <div class="routew-checkout-step">
                    <span class="routew-checkout-step__num" aria-hidden="true">1</span>
                    <div class="routew-checkout-step__text">
                        <h3 class="routew-checkout-step__title"><?php esc_html_e('Where should we deliver?', 'routemile-for-woocommerce'); ?></h3>
                        <p class="routew-checkout-step__sub"><?php esc_html_e('Search your area, use your location, or drag the pin to set the exact drop point.', 'routemile-for-woocommerce'); ?></p>
                    </div>
                </div>

                <div class="routew-location-search-wrapper">
                    <svg class="routew-location-search-wrapper__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19Zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14Z"/></svg>
                    <input id="routew-location-search-input" type="text"
                        placeholder="<?php esc_attr_e('Search area or landmark…', 'routemile-for-woocommerce'); ?>" class="form-control"
                        value="" />
                    <button type="button" id="routew-get-location" class="btn routew-locate-btn">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm8.94 3A9 9 0 0 0 13 3.06V1h-2v2.06A9 9 0 0 0 3.06 11H1v2h2.06A9 9 0 0 0 11 20.94V23h2v-2.06A9 9 0 0 0 20.94 13H23v-2ZM12 19a7 7 0 1 1 7-7 7 7 0 0 1-7 7Z"/></svg>
                        <span class="routew-locate-btn__label"><?php esc_html_e('Locate me', 'routemile-for-woocommerce'); ?></span>
                    </button>
                </div>

                <div class="routew-map-shell">
                    <div id="routew-map" class="routew-map" role="application" aria-label="<?php esc_attr_e('Delivery location map', 'routemile-for-woocommerce'); ?>"></div>
                </div>

                <!-- Display selected location (read-only, from map). Kept as
                     display:none — checkout.js reveals it with slideDown(). -->
                <div id="routew-selected-location" class="routew-location-confirm" style="display:none;">
                    <span class="routew-location-confirm__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    </span>
                    <div class="routew-location-confirm__body">
                        <span class="routew-location-confirm__label"><?php esc_html_e('Delivering to', 'routemile-for-woocommerce'); ?></span>
                        <span id="routew-selected-address" class="routew-location-confirm__address"></span>
                    </div>
                </div>

                <!-- Hidden fields for POST fallback when session is empty (populated by checkout.js) -->
                <input type="hidden" name="routew_lat" id="routew_lat" value="" />
                <input type="hidden" name="routew_lng" id="routew_lng" value="" />
            </div>
        </div>
        <?php
        $markup = ob_get_clean();

        if ($return) {
            return $markup;
        }

        echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput -- static trusted markup only
        return null;
    }

    /**
     * Remove the default WC billing/shipping address fields — the map pin
     * (coordinates) plus the exact-address field carry everything the
     * store needs. Unsetting (not CSS-hiding) keeps WooCommerce from
     * validating or submitting them; fees depend on the pin only.
     *
     * @param array $fields Checkout fields.
     * @return array
     * @since 1.0.0
     */
    public function customize_checkout_fields($fields)
    {
        $removed_suffixes = array('address_1', 'address_2', 'city', 'state', 'postcode', 'country');
        foreach (array('billing', 'shipping') as $group) {
            foreach ($removed_suffixes as $suffix) {
                $key = $group . '_' . $suffix;
                if (isset($fields[$group][$key])) {
                    unset($fields[$group][$key]);
                }
            }
        }

        return $fields;
    }

    /**
     * Seed the checkout context from official WooCommerce state.
     *
     * 1. Default the customer's billing/shipping country to the store
     *    base country when empty (WC_Customer CRUD). The checkout no
     *    longer renders the country select, and WooCommerce's own
     *    posted-data/tax/gateway pipeline expects a country to exist.
     * 2. For logged-in customers, seed the session from the saved
     *    delivery profile so the saved pin + distance become the
     *    default without any interaction. The map and fields are also
     *    pre-filled from the same profile (see add_checkout_fields and
     *    the localized saved_address param).
     *
     * @since 1.0.0
     */
    public function load_saved_address()
    {
        if (!is_checkout()) {
            return;
        }

        if (WC()->customer) {
            $changed = false;
            if (function_exists('wc_get_base_location')) {
                $base = wc_get_base_location();
                $base_country = ($base && !empty($base->country)) ? $base->country : '';
                if ('' !== $base_country) {
                    if ('' === WC()->customer->get_billing_country()) {
                        WC()->customer->set_billing_country($base_country);
                        $changed = true;
                    }
                    if ('' === WC()->customer->get_shipping_country()) {
                        WC()->customer->set_shipping_country($base_country);
                        $changed = true;
                    }
                }
            }
            if ($changed && method_exists(WC()->customer, 'save')) {
                WC()->customer->save();
            }
        }

        if (WC()->session) {
            // Logged-in users: pull from user meta.
            if (is_user_logged_in()) {
                $profile = get_user_meta(get_current_user_id(), '_routew_delivery_profile', true);
            } else {
                // Guests returning in the same browser fall back to a session-
                // stored profile (class-routew-checkout-handler.php seeds it
                // when an email comes back on the checkout-submit step). Without
                // this branch the same-browser guest never sees their saved
                // profile again and re-types the address each checkout.
                // (AUDIT-FIXES L4)
                $profile = WC()->session->get('routew_delivery_profile');
            }
            if (!empty($profile) && !empty($profile['lat']) && !empty($profile['lng'])) {
                WC()->session->set('customer_lat', $profile['lat']);
                WC()->session->set('customer_lng', $profile['lng']);
                if (!empty($profile['distance_data'])) {
                    WC()->session->set('routew_distance_data', $profile['distance_data']);
                }
            }
        }
    }
}

new ROUTEW_Checkout();
