<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * A simple rate-limiting service using WordPress transients.
 *
 * @since      1.0.1
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Rate_Limiter
{

    /**
     * Check and enforce a rate limit for a given action and identifier.
     *
     * @param string $action    A unique name for the action being limited (e.g., 'geocode_request').
     * @param int    $limit     The number of allowed requests per period.
     * @param int    $period    The time period in seconds.
     * @return bool|WP_Error    True if the request is within the limit, otherwise a WP_Error object.
     */
    public static function check_rate_limit($action, $limit = 20, $period = MINUTE_IN_SECONDS)
    {
        $ip_address = self::get_ip_address();

        $transient_key = 'routew_rl_' . $action . '_' . md5($ip_address);
        $requests = get_transient($transient_key);

        if (false === $requests) {
            $requests = array();
        }

        $current_time = time();
        // Remove requests older than the defined period
        $requests = array_filter($requests, function ($timestamp) use ($current_time, $period) {
            return ($current_time - $timestamp) < $period;
        });

        $current_count = count($requests);

        // Log warnings at thresholds (80% and 90% of limit)
        $usage_percent = ($limit > 0) ? ($current_count / $limit) : 0;
        if ($usage_percent >= 0.8 && $usage_percent < 1.0) {
            self::log_rate_limit_warning($action, $usage_percent);
        }

        if ($current_count >= $limit) {
            // Log when limit is exceeded
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    sprintf('Rate limit exceeded for action "%s" (IP: %s, limit: %d/%ds)', $action, $ip_address, $limit, $period),
                    array('source' => 'routemile-rate-limiter')
                );
            }
            return new WP_Error('rate_limit_exceeded', __('You are making too many requests. Please wait a moment and try again.', 'routemile-for-woocommerce'));
        }

        // Add current request timestamp
        $requests[] = $current_time;
        set_transient($transient_key, $requests, $period);

        return true;
    }

    /**
     * Get remaining request quota for a given action without consuming a request.
     *
     * @deprecated 1.5.0 No internal callers; safe to remove in v1.6.0.
     * @param string $action A unique name for the action being limited.
     * @param int    $limit  The number of allowed requests per period.
     * @param int    $period The time period in seconds.
     * @return int Number of remaining requests in the current period.
     */
    public static function get_remaining_quota($action, $limit = 20, $period = MINUTE_IN_SECONDS)
    {
        unset($action, $period);
        _doing_it_wrong(
            __METHOD__,
            esc_html__('ROUTEW_Rate_Limiter::get_remaining_quota() is deprecated and a no-op as of 1.5.0.', 'routemile-for-woocommerce'),
            '1.5.0'
        );
        return (int) $limit;
    }

    /**
     * Log warning when approaching rate limit thresholds.
     *
     * @param string $action        The action being rate limited.
     * @param float  $usage_percent The current usage as a percentage (0.0 to 1.0).
     * @return void
     */
    public static function log_rate_limit_warning($action, $usage_percent)
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $percent_display = round($usage_percent * 100);
        $ip_address = self::get_ip_address();

        // Only log at specific thresholds to avoid spam
        if ($usage_percent >= 0.9) {
            wc_get_logger()->warning(
                sprintf('Rate limit WARNING: %d%% quota used for action "%s" (IP: %s)', $percent_display, $action, $ip_address),
                array('source' => 'routemile-rate-limiter')
            );
        } elseif ($usage_percent >= 0.8) {
            wc_get_logger()->info(
                sprintf('Rate limit notice: %d%% quota used for action "%s" (IP: %s)', $percent_display, $action, $ip_address),
                array('source' => 'routemile-rate-limiter')
            );
        }
    }

    /**
     * Get the user's IP address.
     *
     * Security: Only uses REMOTE_ADDR to prevent IP spoofing via headers.
     * If behind a trusted proxy/CDN, configure it to pass real IP via REMOTE_ADDR.
     *
     * When REMOTE_ADDR is missing (CLI, very old WP configs, sandboxed calls)
     * returns a stable anonymous identifier so rate limiting still applies
     * instead of letting every request through untracked.
     *
     * @return string The user's IP address or a stable anonymous identifier.
     */
    private static function get_ip_address()
    {
        // Only use REMOTE_ADDR to prevent IP spoofing.
        // Headers like HTTP_X_FORWARDED_FOR can be easily forged by attackers.
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        if (filter_var($ip_address, FILTER_VALIDATE_IP)) {
            return $ip_address;
        }

        // Fall back to a stable, opaque identifier so anonymous callers
        // still share a single rate-limit bucket rather than bypassing
        // the limit entirely. Logged so the operator can spot it.
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info(
                'Rate limiter could not determine REMOTE_ADDR; falling back to anon bucket.',
                array('source' => 'routemile-rate-limiter')
            );
        }
        return 'anon-' . sha1('routew-anon-bucket');
    }
}
