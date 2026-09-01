<?php
/**
 * Address Validator Service
 *
 * Heuristic delivery-address completeness check used to give customers
 * actionable feedback when their address is too vague for the delivery
 * agent to find them. Pure (no instance state, no WordPress hooks) — call
 * as a static method.
 *
 * The static helper class is retained for forward compatibility (multi-outlet
 * editor planned for v1.6.0); the validator body was removed in 1.5.0 as
 * no production caller remained. If you were calling this from a 3rd-party
 * extension, switch to the shipping-method's per-checkout validation hook.
 *
 * @since      1.2.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ROUTEW_Address_Validator
 *
 * Stateless service. Originally a private method on ROUTEW_Checkout; promoted
 * to a service in 1.2.0 to be reusable by the multi-outlet editor (Phase 7)
 * and any future address-validating surface.
 *
 * @since 1.2.0
 */
class ROUTEW_Address_Validator
{

    /**
     * Validate address completeness for delivery with detailed feedback.
     *
     * @deprecated 1.5.0 No internal callers; safe to remove in v1.6.0.
     * @param string $address The delivery address to validate.
     * @return array Array with 'is_complete' boolean and 'message' string.
     * @since 1.0.0
     */
    public static function validate_address_completeness($address)
    {
        _doing_it_wrong(
            __METHOD__,
            esc_html__('ROUTEW_Address_Validator::validate_address_completeness() is deprecated and a no-op as of 1.5.0.', 'routemile-for-woocommerce'),
            '1.5.0'
        );
        unset($address);
        return array(
            'is_complete' => true,
            'message'     => '',
        );
    }
}
