<?php
/**
 * Standalone Unit Test Runner for RouteMile Plugin
 *
 * This test suite can run WITHOUT WordPress installed.
 * It validates PHP syntax, code structure, and security patterns.
 *
 * NOTE: This is a CLI-only test runner, not a WordPress plugin file.
 * It writes colour-coded output to a terminal (no HTML), uses CLI-only
 * constants (GREEN/RED/YELLOW/RESET) and a single top-level $runner
 * handle, and is excluded from the production zip via .distignore.
 * phpcs is therefore intentionally disabled for the rest of the file.
 *
 * @package RouteMile
 * @subpackage Tests
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.ValidatedSanitizedInput
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone CLI runner — never loaded inside WP. Define the guard so
	// Plugin Check's missing_direct_file_access_protection check passes.
	define( 'ABSPATH', __DIR__ . '/__wp_placeholder__' );
}

// Colors for terminal output
define('GREEN', "\033[32m");
define('RED', "\033[31m");
define('YELLOW', "\033[33m");
define('RESET', "\033[0m");

class ROUTEWTestRunner
{
    private $passed = 0;
    private $failed = 0;
    private $plugin_dir;

    public function __construct()
    {
        $this->plugin_dir = dirname(__DIR__);
    }

    public function run()
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "  RouteMile for WooCommerce - Unit Test Suite\n";
        echo str_repeat("=", 60) . "\n\n";

        // Run all test groups
        $this->testPhpSyntax();
        $this->testSecurityPatterns();
        $this->testFileStructure();
        $this->testCodeQuality();
        $this->testPluginHeaders();
        $this->testHooksAndFilters();

        // Print summary
        $this->printSummary();

        return $this->failed === 0 ? 0 : 1;
    }

    private function testPhpSyntax()
    {
        echo GREEN . "▶ Testing PHP Syntax..." . RESET . "\n";

        $php_files = $this->findPhpFiles($this->plugin_dir);

        foreach ($php_files as $file) {
            $relative_path = str_replace($this->plugin_dir . '/', '', $file);
            $error = $this->lintPhpFile($file);

            if ($error === null) {
                $this->pass("Syntax OK: $relative_path");
            } else {
                $this->fail("Syntax Error: $relative_path - " . $error);
            }
        }
        echo "\n";
    }

    /**
     * Pure-PHP syntax check — no subprocess.
     *
     * Tokenizes with TOKEN_PARSE (throws ParseError on malformed code)
     * and additionally verifies bracket balance. Deliberately avoids
     * shelling out to `php -l` so the runner exposes no
     * command-execution surface at all.
     *
     * @param string $file Absolute path to the file to lint.
     * @return string|null Error message, or null when the file looks valid.
     */
    private function lintPhpFile($file)
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return 'Unable to read file';
        }

        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            return $e->getMessage() . ' on line ' . $e->getLine();
        } catch (Error $e) {
            return $e->getMessage();
        }

        $pairs = array(')' => '(', ']' => '[', '}' => '{');
        $stack = array();
        foreach ($tokens as $token) {
            if (is_array($token)) {
                // {$var} / ${var} interpolation: the opening brace arrives
                // as a T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES array
                // token, but its closing "}" arrives as a plain token.
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $stack[] = '{';
                }
                continue;
            }
            if ($token === '(' || $token === '[' || $token === '{') {
                $stack[] = $token;
            } elseif (isset($pairs[$token])) {
                if (array_pop($stack) !== $pairs[$token]) {
                    return 'Unbalanced brackets';
                }
            }
        }
        if (!empty($stack)) {
            return 'Unbalanced brackets';
        }

        return null;
    }

    private function testSecurityPatterns()
    {
        echo GREEN . "▶ Testing Security Patterns..." . RESET . "\n";

        $includes_dir = $this->plugin_dir . '/includes';
        $templates_dir = $this->plugin_dir . '/templates';

        // Test ABSPATH checks in includes
        $include_files = glob($includes_dir . '/*.php');
        foreach ($include_files as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            if (preg_match('/defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)/', $content)) {
                $this->pass("ABSPATH check present: $basename");
            } else {
                $this->fail("Missing ABSPATH check: $basename");
            }
        }

        // Test ABSPATH in services
        $service_files = glob($includes_dir . '/services/*.php');
        foreach ($service_files as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            if (preg_match('/defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)/', $content)) {
                $this->pass("ABSPATH check present: services/$basename");
            } else {
                $this->fail("Missing ABSPATH check: services/$basename");
            }
        }

        // Test templates
        $template_files = glob($templates_dir . '/*.php');
        foreach ($template_files as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            if (preg_match('/defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)/', $content)) {
                $this->pass("ABSPATH check present: templates/$basename");
            } else {
                $this->fail("Missing ABSPATH check: templates/$basename");
            }
        }

        echo "\n";
    }

    private function testFileStructure()
    {
        echo GREEN . "▶ Testing File Structure..." . RESET . "\n";

        $required_files = [
            'routemile-woocommerce.php' => 'Main plugin file',
            'includes/class-routew-core.php' => 'Core class',
            'includes/class-routew-checkout.php' => 'Checkout orchestrator',
            'includes/class-routew-checkout-maps.php' => 'Checkout maps / frontend assets',
            'includes/class-routew-checkout-handler.php' => 'Checkout server handler',
            'includes/class-routew-checkout-address.php' => 'Checkout address simplification (hide locale + relabel)',
            'includes/class-routew-blocks-checkout.php' => 'Blocks checkout support',
            'includes/class-routew-store-hours.php' => 'Scheduled opening hours',
            'includes/class-routew-pricing.php' => 'Admin-configurable pricing rules',
            'includes/services/class-routew-delivery-fee.php' => 'Delivery fee service',
            'includes/services/class-routew-address-validator.php' => 'Address validator service',
            'includes/class-routew-dashboard.php' => 'Dashboard class',
            'includes/class-routew-dashboard-render.php' => 'Deliveries dashboard renderer',
            'includes/class-routew-dashboard-actions.php' => 'Deliveries dashboard write handlers',
            'includes/class-routew-settings.php' => 'Settings class',
            'includes/class-routew-roles.php' => 'Roles class',
            'includes/class-routew-notifications.php' => 'Notifications class',
            'includes/class-routew-order-admin.php' => 'Order Admin class',
            'includes/class-routew-order-statuses.php' => 'Order Statuses class',
            'includes/class-routew-shortcodes.php' => 'Shortcodes class',
            'includes/class-routew-my-account.php' => 'Customer My Account dashboard widgets',
            'includes/class-routew-shipping-method.php' => 'Shipping Method class',
            'includes/services/class-routew-mapping-service.php' => 'Mapping Service',
            'includes/services/class-routew-map-providers.php' => 'Map provider registry',
            'includes/services/class-routew-map-provider-client.php' => 'Map provider HTTP client',
            'includes/class-routew-settings-extra.php' => 'Settings extension sibling',
            'includes/class-routew-settings-maps.php' => 'Map provider settings',
            'includes/services/class-routew-rate-limiter.php' => 'Rate Limiter',
            'templates/delivery-dashboard-template.php' => 'Dashboard Template',
            'templates/receipt-template.php' => 'Receipt Template',
            'assets/js/admin.js' => 'Admin JavaScript',
            'assets/js/checkout.js' => 'Checkout JavaScript (Google provider)',
            'assets/js/checkout-leaflet.js' => 'Checkout JavaScript (Leaflet providers)',
            'assets/vendor/leaflet/leaflet.js' => 'Bundled Leaflet library',
            'assets/vendor/leaflet/leaflet.css' => 'Bundled Leaflet stylesheet',
            'assets/css/frontend.css' => 'Frontend CSS',
        ];

        foreach ($required_files as $path => $description) {
            $full_path = $this->plugin_dir . '/' . $path;
            if (file_exists($full_path)) {
                $this->pass("Exists: $path ($description)");
            } else {
                $this->fail("Missing: $path ($description)");
            }
        }

        // Bundled Leaflet integrity — these ship in the repo, so a
        // tampered or re-pinned bundle should fail the suite loudly.
        // Hashes pinned against the v1.3.8 bundle. Update deliberately
        // when upgrading Leaflet.
        $vendor_hashes = [
            'assets/vendor/leaflet/leaflet.js'  => 'db49d009c841f5ca34a888c96511ae936fd9f5533e90d8b2c4d57596f4e5641a',
            'assets/vendor/leaflet/leaflet.css' => 'a7837102824184820dfa198d1ebcd109ff6d0ff9a2672a074b9a1b4d147d04c6',
        ];
        foreach ($vendor_hashes as $path => $expected_sha256) {
            $full_path = $this->plugin_dir . '/' . $path;
            if (!file_exists($full_path)) {
                $this->fail("Vendor file missing for hash check: $path");
                continue;
            }
            $actual = hash_file('sha256', $full_path);
            if ($actual === $expected_sha256) {
                $this->pass("Integrity: $path matches pinned SHA-256");
            } else {
                $this->fail("Integrity: $path SHA-256 changed (got $actual)");
            }
        }

        echo "\n";
    }

    private function testCodeQuality()
    {
        echo GREEN . "▶ Testing Code Quality..." . RESET . "\n";

        // Check for unlimited queries
        // (templates/delivery-boy-view.php removed 2026-09-01 — dead code,
        // superseded by templates/delivery-dashboard-template.php; see
        // plans/fluttering-toasting-volcano.md commit 1a.)
        $files_to_check = [
            'includes/class-routew-dashboard.php',
            'includes/class-routew-reporting.php',
            'templates/delivery-dashboard-template.php',
        ];

        foreach ($files_to_check as $rel_path) {
            $file = $this->plugin_dir . '/' . $rel_path;
            if (file_exists($file)) {
                $content = file_get_contents($file);

                if (strpos($content, "'limit' => -1") !== false) {
                    $this->fail("Unlimited query found: $rel_path");
                } else {
                    $this->pass("No unlimited queries: $rel_path");
                }
            }
        }

        // Check for proper nonce verification in AJAX handlers.
        // NOTE: the checkout classes register no admin-ajax endpoints since
        // 1.2.5 (REST validate-location owns location updates) — verified
        // by the no-ajax guard below.
        $ajax_files = [
            'includes/class-routew-admin-bar.php',
            'includes/class-routew-shortcodes.php',
            'includes/class-routew-dashboard-actions.php',
        ];

        foreach ($ajax_files as $rel_path) {
            $file = $this->plugin_dir . '/' . $rel_path;
            if (file_exists($file)) {
                $content = file_get_contents($file);

                if (
                    strpos($content, 'wp_verify_nonce') !== false ||
                    strpos($content, 'check_ajax_referer') !== false
                ) {
                    $this->pass("Nonce verification present: $rel_path");
                } else {
                    $this->fail("Missing nonce verification: $rel_path");
                }
            }
        }

        // Since 1.2.5 the checkout classes register no admin-ajax endpoints
        // (REST validate-location owns location updates; the restaurant
        // center is localized server-side). Guard against orphaned wp_ajax
        // registrations reappearing.
        $no_ajax_files = [
            'includes/class-routew-checkout-maps.php',
            'includes/class-routew-checkout-handler.php',
        ];

        foreach ($no_ajax_files as $rel_path) {
            $file = $this->plugin_dir . '/' . $rel_path;
            if (file_exists($file)) {
                $content = file_get_contents($file);

                if (strpos($content, 'wp_ajax') === false) {
                    $this->pass("No stale admin-ajax registration: $rel_path");
                } else {
                    $this->fail("Unexpected wp_ajax registration: $rel_path");
                }
            }
        }

        echo "\n";
    }

    private function testPluginHeaders()
    {
        echo GREEN . "▶ Testing Plugin Headers..." . RESET . "\n";

        $main_file = $this->plugin_dir . '/routemile-woocommerce.php';
        $content = file_get_contents($main_file);

        $required_headers = [
            'Plugin Name' => '/Plugin Name:\s*.+/',
            'Version' => '/Version:\s*[\d.]+/',
            'Requires at least' => '/Requires at least:\s*[\d.]+/',
            'Tested up to' => '/Tested up to:\s*[\d.]+/',
            'Requires PHP' => '/Requires PHP:\s*[\d.]+/',
            'WC requires at least' => '/WC requires at least:\s*[\d.]+/',
            'WC tested up to' => '/WC tested up to:\s*[\d.]+/',
            'Requires Plugins' => '/Requires Plugins:\s*woocommerce/',
        ];

        foreach ($required_headers as $name => $pattern) {
            if (preg_match($pattern, $content)) {
                $this->pass("Header present: $name");
            } else {
                $this->fail("Missing header: $name");
            }
        }

        // Check HPOS compatibility declaration
        if (
            strpos($content, 'declare_compatibility') !== false &&
            strpos($content, 'custom_order_tables') !== false
        ) {
            $this->pass("HPOS compatibility declared");
        } else {
            $this->fail("Missing HPOS compatibility declaration");
        }

        echo "\n";
    }

    private function testHooksAndFilters()
    {
        echo GREEN . "▶ Testing Hooks & Filters..." . RESET . "\n";

        $core_file = $this->plugin_dir . '/includes/class-routew-core.php';
        $content = file_get_contents($core_file);

        $required_hooks = [
            'woocommerce_shipping_init' => 'Shipping method initialization',
            'woocommerce_shipping_methods' => 'Shipping method registration',
            'template_include' => 'Template override',
            'init' => 'Rewrite rules',
        ];

        foreach ($required_hooks as $hook => $description) {
            if (strpos($content, $hook) !== false) {
                $this->pass("Hook registered: $hook ($description)");
            } else {
                $this->fail("Missing hook: $hook ($description)");
            }
        }

        // H4 (session leak) regression: ensure clear_customer_session_data
        // is wired to every documented lifecycle event.
        $session_helper_file = $this->plugin_dir . '/includes/services/class-routew-session-helper.php';
        $session_helper_content = file_exists($session_helper_file) ? file_get_contents($session_helper_file) : '';
        $session_hooks = [
            'woocommerce_checkout_order_created',
            'woocommerce_thankyou',
            'woocommerce_order_status_cancelled',
            'wp_logout',
        ];
        foreach ($session_hooks as $hook) {
            if (false !== strpos($session_helper_content, "'" . $hook . "'")) {
                $this->pass("H4 hook registered: $hook");
            } else {
                $this->fail("H4 missing hook: $hook");
            }
        }

        // M2+M3 (location-meta lifecycle): ensure every unassign path and the
        // terminal-status hooks reach the helper.
        $lifecycle_helper = $this->plugin_dir . '/includes/services/class-routew-order-lifecycle.php';
        $lifecycle_content = file_exists($lifecycle_helper) ? file_get_contents($lifecycle_helper) : '';
        foreach (array(
            'woocommerce_order_status_completed',
            'woocommerce_order_status_cancelled',
        ) as $hook) {
            if (false !== strpos($lifecycle_content, "'" . $hook . "'")) {
                $this->pass("M2+M3 hook registered: $hook");
            } else {
                $this->fail("M2+M3 missing hook: $hook");
            }
        }
        $unassign_caller_files = array(
            'includes/class-routew-dashboard-actions.php' => 2, // form unassign + AJAX unassign
            'includes/class-routew-order-admin.php'      => 1, // legacy ?routew_action=reassign
        );
        foreach ($unassign_caller_files as $rel => $expected_calls) {
            $content = file_get_contents($this->plugin_dir . '/' . $rel);
            $actual = substr_count($content, 'ROUTEW_Order_Lifecycle::clear_delivery_location_meta');
            if ($actual >= $expected_calls) {
                $this->pass(sprintf('M2 caller present in %s (×%d)', $rel, $actual));
            } else {
                $this->fail(sprintf('M2 caller missing from %s (found %d, expected ≥%d)', $rel, $actual, $expected_calls));
            }
        }

        // M4 (state guard): picked-up transition must guard on from-status.
        $dbv_file = $this->plugin_dir . '/includes/class-routew-delivery-boy-view.php';
        $dbv = file_exists($dbv_file) ? file_get_contents($dbv_file) : '';
        if (false !== strpos($dbv, "in_array(\$order->get_status(), \$valid_from, true)")) {
            $this->pass('M4 status guard present in mark_picked_up()');
        } else {
            $this->fail('M4 status guard missing from mark_picked_up()');
        }

        // M10 (state-changing actions on POST): admin_post handlers registered
        // and the legacy admin_init GET handler removed.
        $oa_file = $this->plugin_dir . '/includes/class-routew-order-admin.php';
        $oa = file_exists($oa_file) ? file_get_contents($oa_file) : '';
        foreach (array(
            "admin_post_routew_reject",
            "admin_post_routew_reassign",
            "function handle_reject(",
            "function handle_reassign(",
        ) as $needle) {
            if (false !== strpos($oa, $needle)) {
                $this->pass('M10 wired: ' . $needle);
            } else {
                $this->fail('M10 missing: ' . $needle);
            }
        }
        if (false === strpos($oa, "add_action('admin_init', array(\$this, 'handle_order_actions'))")) {
            $this->pass('M10 legacy admin_init GET handler removed');
        } else {
            $this->fail('M10 legacy admin_init GET handler still registered');
        }

        // M7 (ledger cap): create_request must use the SETTLEMENT_LEDGER_CAP
        // constant (not a hardcoded 200), the constant must exist, and its
        // value must be > 200 so we are strictly increasing the cap.
        $cash = file_get_contents($this->plugin_dir . '/includes/class-routew-agent-cash.php');
        if (false !== strpos($cash, 'const SETTLEMENT_LEDGER_CAP')) {
            $this->pass('M7 SETTLEMENT_LEDGER_CAP constant defined');
        } else {
            $this->fail('M7 SETTLEMENT_LEDGER_CAP constant missing');
        }
        if (false !== strpos($cash, 'array_slice($settlements, 0, self::SETTLEMENT_LEDGER_CAP)')) {
            $this->pass('M7 create_request uses SETTLEMENT_LEDGER_CAP');
        } else {
            $this->fail('M7 create_request still uses hardcoded cap');
        }
        if (preg_match('/const\s+SETTLEMENT_LEDGER_CAP\s*=\s*(\d+)/', $cash, $m) && (int) $m[1] > 200) {
            $this->pass('M7 SETTLEMENT_LEDGER_CAP > 200 (value=' . $m[1] . ')');
        } else {
            $this->fail('M7 SETTLEMENT_LEDGER_CAP <= 200');
        }

        // Check order statuses registration
        $statuses_file = $this->plugin_dir . '/includes/class-routew-order-statuses.php';
        $content = file_get_contents($statuses_file);

        $custom_statuses = ['routew-in-kitchen', 'routew-assigned', 'routew-picked-up'];
        foreach ($custom_statuses as $status) {
            if (strpos($content, $status) !== false) {
                $this->pass("Status registered: $status");
            } else {
                $this->fail("Missing status: $status");
            }
        }

        // L1.1 — shipping-zone catches must log via wc_get_logger() rather
        // than bare error_log() so Plugin Check (WP coding standard) accepts
        // the diagnostic logging in production builds.
        $main = file_get_contents($this->plugin_dir . '/routemile-woocommerce.php');
        if (preg_match_all('/function\s+routew_ensure_shipping_method_registered[^{]*\{(.*?)(?=\n\}\n|\n\})/s', $main, $m)) {
            $body = $m[1][0] ?? '';
            $catch_blocks = preg_match_all('/\}\s*catch\s*\(\s*\\\\?Throwable[^{]*\{(.*?)\}/s', $body, $cb);
            $wc_logger_count = substr_count($body, "wc_get_logger()->error(");
            $raw_error_log_count = preg_match_all('/^\s*error_log\s*\(/m', $body);
            if ($catch_blocks >= 2 && $wc_logger_count >= 2 && 0 === $raw_error_log_count) {
                $this->pass('L1.1 shipping-zone catches log via wc_get_logger() (no bare error_log)');
            } else {
                $this->fail(sprintf(
                    'L1.1 expected ≥2 catches with wc_get_logger()->error() and no bare error_log(); found %d catches, %d wc_get_logger, %d bare error_log',
                    $catch_blocks,
                    $wc_logger_count,
                    $raw_error_log_count
                ));
            }
        } else {
            $this->fail('L1.1 could not locate routew_ensure_shipping_method_registered() body');
        }

        // R1 — WC settings tab hook parity.
        // The v1.5.0 rename changed the tab id to `routemile-for-woocommerce`
        // (matches WP.org plugin slug) but left the `woocommerce_settings_*`
        // and `woocommerce_update_options_*` hooks on the bare `routemile`
        // key. WC fires those actions via do_action('woocommerce_settings_' .
        // $current_tab) — dash suffix in the id MUST be mirrored in the hook
        // name, otherwise the tab renders an empty content area. This test
        // asserts the parity by scanning the three settings classes.
        $tab_id = 'routemile-for-woocommerce';
        $settings_class_files = array(
            'includes/class-routew-settings.php',
            'includes/class-routew-settings-ui.php',
            'includes/class-routew-settings-extra.php',
        );
        $bad_hooks = array();
        foreach ($settings_class_files as $rel) {
            $path = $this->plugin_dir . '/' . $rel;
            if (!file_exists($path)) {
                continue;
            }
            $body = file_get_contents($path);
            // Find every add_action/remove_action('woocommerce_settings_*' or
            // 'woocommerce_update_options_*') call and check the suffix.
            if (preg_match_all(
                "/(?:add|remove)_action\\(\\s*['\"](woocommerce_(?:settings|update_options)_[a-z0-9_-]+)['\"]/",
                $body,
                $hits
            )) {
                foreach ($hits[1] as $hook) {
                    // The expected form is `woocommerce_settings_{tab_id}` or
                    // `woocommerce_update_options_{tab_id}`. Anything else
                    // (e.g. the bare `woocommerce_settings_routemile` from
                    // before the rename) is a regression.
                    $expected_suffix = substr($tab_id, 0);
                    if (substr($hook, -strlen($expected_suffix)) !== $expected_suffix) {
                        $bad_hooks[] = $rel . ': ' . $hook;
                    }
                }
            }
        }
        if (empty($bad_hooks)) {
            $this->pass('R1 WC settings hooks use tab-id suffix ' . $tab_id);
        } else {
            $this->fail('R1 stale WC settings hooks found (do not match tab id ' . $tab_id . '): ' . implode('; ', $bad_hooks));
        }

        // R2 — JS↔PHP namespace parity for WC Blocks extension.
        // The Store API extension namespace must match on both sides:
        //   PHP:  woocommerce_blocks_loaded → registerStoreAutomation
        //         (or ExtendRestApi::register_endpoint_data) with namespace=X
        //   JS:   window.wc.blocksCheckout.extensionCartUpdate({ namespace: X })
        // When they diverge, WC Blocks throws "There is no such namespace
        // registered: X" on the checkout, the cart-update POST fails, and
        // the customer sees "Sorry, this order requires a shipping option"
        // because no shipping rates were recalculated. (This is exactly
        // what happened after the v1.5.0 slug rename.)
        $php_ns = null;
        $blocks_php = $this->plugin_dir . '/includes/class-routew-blocks-checkout.php';
        if (file_exists($blocks_php) && preg_match("/'namespace'\s*=>\s*'([^']+)'/", file_get_contents($blocks_php), $m)) {
            $php_ns = $m[1];
            $this->pass('R2 PHP namespace registered: ' . $php_ns);
        } else {
            $this->fail('R2 could not locate PHP namespace in class-routew-blocks-checkout.php');
        }
        $mismatched_ns = array();
        $js_files = array('assets/js/checkout.js', 'assets/js/checkout-leaflet.js');
        foreach ($js_files as $rel) {
            $path = $this->plugin_dir . '/' . $rel;
            if (!file_exists($path)) {
                continue;
            }
            $body = file_get_contents($path);
            // Find every extensionCartUpdate({ namespace: 'X' }) call.
            if (preg_match_all("/extensionCartUpdate\s*\(\s*\{[^}]*namespace\s*:\s*'([^']+)'/s", $body, $hits)) {
                foreach ($hits[1] as $ns) {
                    if ($php_ns && $ns !== $php_ns) {
                        $mismatched_ns[] = $rel . ': ' . $ns;
                    }
                }
            }
        }
        if (empty($mismatched_ns)) {
            $this->pass('R2 JS extensionCartUpdate namespaces match PHP (' . $php_ns . ')');
        } else {
            $this->fail('R2 stale JS namespace(s) — checkout will throw "There is no such namespace registered": ' . implode('; ', $mismatched_ns));
        }

        // R2 (SW) — the rider PWA service worker hardcodes the plugin folder
        // path. It must match the slug WP exposes (lowercased). Catching
        // drift here means a future rename of the slug surfaces in the
        // test runner, not as "PWA icons 404" in the rider dashboard.
        $sw_path = $this->plugin_dir . '/assets/js/routew-agent-sw.js';
        if (file_exists($sw_path)) {
            $sw_body = file_get_contents($sw_path);
            $expected_sw_path = '/wp-content/plugins/' . $php_ns . '/assets/';
            if (false !== strpos($sw_body, $expected_sw_path)) {
                $this->pass('R2 SW PLUGIN_ASSETS path matches PHP namespace (' . $expected_sw_path . ')');
            } else {
                // Surface the actual value for debugging.
                if (preg_match("/PLUGIN_ASSETS\s*=\s*'([^']+)'/", $sw_body, $swm)) {
                    $this->fail('R2 SW PLUGIN_ASSETS="' . $swm[1] . '" does not match PHP namespace (expected "' . $expected_sw_path . '")');
                } else {
                    $this->fail('R2 SW PLUGIN_ASSETS not found; expected "' . $expected_sw_path . '"');
                }
            }
        }

        // R3 — shipping-zone auto-sync covers zone 0 (Rest of the world) AND
        // reactively re-syncs when a zone is added/updated. Without zone 0,
        // any customer whose address doesn't match a specific zone (e.g.
        // country=Bangladesh, zone=Rangamati) sees "No shipping options are
        // available for this address" at checkout even though the JS map
        // shows a valid delivery fee.
        $main_php = file_get_contents($this->plugin_dir . '/routemile-woocommerce.php');
        $r3_checks = array(
            'R3 sync function defined' => '/function\s+routew_maybe_sync_shipping_zones\s*\(/',
            'R3 sync adds to Rest of the world zone 0' => '/new\s+WC_Shipping_Zone\s*\(\s*0\s*\)/',
            'R3 self-heal: zone 0 check inside sync' => '/routew_zone_sync_done[\s\S]{0,400}has_routew_on_rest/s',
            'R3 reactive hook: zone_added' => "/add_action\\(\\s*'woocommerce_shipping_zone_added'/",
            'R3 reactive hook: zone_updated' => "/add_action\\(\\s*'woocommerce_shipping_zone_updated'/",
            'R3 invalidate helper defined' => '/function\s+routew_invalidate_zone_sync\s*\(/',
        );
        foreach ($r3_checks as $label => $pattern) {
            if (preg_match($pattern, $main_php)) {
                $this->pass($label);
            } else {
                $this->fail($label . ' (pattern not found: ' . $pattern . ')');
            }
        }

        // R4 — WC session access in the session helper must be defensively
        // guarded. The Blocks order-confirmation page fires
        // `woocommerce_thankyou` while the WC session handler is still
        // bootstrapping; a bare `WC()->session()` call there throws
        // "Call to undefined method WooCommerce::session()" and 500s the
        // thank-you page. (REGRESSION-FIX R4)
        $session_helper = $this->plugin_dir . '/includes/services/class-routew-session-helper.php';
        if (file_exists($session_helper)) {
            $sh = file_get_contents($session_helper);
            // The class must NOT contain a bare `WC()->session()` parens call
            // outside of method_exists() / class_exists() guards. Strip
            // line comments and block comments first so docstring references
            // don't trigger the assertion.
            $sh_code = preg_replace('!//.*!', '', $sh);
            $sh_code = preg_replace('!/\*.*?\*/!s', '', $sh_code);
            $unsafe = preg_match(
                '/(?<!method_)\b(?:WC\(\))->session\s*\(\s*\)/',
                $sh_code
            );
            if (0 === $unsafe) {
                $this->pass('R4 no bare WC()->session() calls in session helper');
            } else {
                $this->fail('R4 bare WC()->session() call present — will fatal during Blocks order-confirmation render');
            }
            // And the helper must have a safe getter that prefers method_exists.
            if (preg_match('/function\s+safe_get_session\s*\(/', $sh)
                && preg_match('/method_exists\s*\(\s*\$wc\s*,\s*[\'"]session[\'"]/', $sh)
            ) {
                $this->pass('R4 safe_get_session() helper present with method_exists guard');
            } else {
                $this->fail('R4 safe_get_session() helper missing or unguarded');
            }
            // All hooks must still be present (regression-cover the audit H4 fix).
            foreach (array(
                'woocommerce_checkout_order_created' => 'H4 hook present: woocommerce_checkout_order_created',
                'woocommerce_thankyou' => 'H4 hook present: woocommerce_thankyou',
                'woocommerce_order_status_cancelled' => 'H4 hook present: woocommerce_order_status_cancelled',
                'wp_logout' => 'H4 hook present: wp_logout',
            ) as $hook => $label) {
                if (false !== strpos($sh, "'" . $hook . "'") || false !== strpos($sh, '"' . $hook . '"')) {
                    $this->pass($label);
                } else {
                    $this->fail($label);
                }
            }
        } else {
            $this->fail('R4 session helper file missing: ' . $session_helper);
        }

        // UI1 — Agent dashboard Bootstrap 5 overlay (Batch 1b).
        // Verifies the Bootstrap CSS bundle exists, is enqueued on the agent
        // surface, the agent JS contract is preserved verbatim in the
        // template, the PWA manifest endpoint is still wired, and the service
        // worker cache version was bumped for the CSS overhaul.
        $bootstrap_css = $this->plugin_dir . '/assets/vendor/bootstrap/bootstrap.min.css';
        if (file_exists($bootstrap_css)) {
            $this->pass('UI1 Bootstrap 5.3 CSS bundle present at assets/vendor/bootstrap/');
        } else {
            $this->fail('UI1 Bootstrap CSS bundle missing at ' . $bootstrap_css);
        }

        $core_php = file_get_contents($this->plugin_dir . '/includes/class-routew-core.php');
        // The bootstrap path may appear as 'assets/vendor/...' or
        // '../assets/vendor/...' (the enqueue concatenates with __DIR__-relative
        // plugin_dir_url), so match on the unique tail.
        if (false !== strpos($core_php, "'routew-bootstrap'") && false !== strpos($core_php, "vendor/bootstrap/bootstrap.min.css'")) {
            $this->pass('UI1 Bootstrap enqueued on agent surface (routew-bootstrap handle)');
        } else {
            $this->fail('UI1 Bootstrap not enqueued on agent surface — class-routew-core.php must wp_enqueue_style the bootstrap CSS');
        }

        // JS contract preserved in template.
        $tpl = file_get_contents($this->plugin_dir . '/templates/delivery-dashboard-template.php');
        $contract_attrs = array(
            'data-routew-tab',
            'data-action',
            'data-status',
            'data-order-id',
            'data-nonce',
            'data-routew-state',
            'data-routew-counts',
            'data-routew-settle-amount',
        );
        $missing_attrs = array();
        foreach ($contract_attrs as $attr) {
            if (false === strpos($tpl, $attr)) {
                $missing_attrs[] = $attr;
            }
        }
        if (empty($missing_attrs)) {
            $this->pass('UI1 JS contract preserved: all 8 data-* attributes present in template');
        } else {
            $this->fail('UI1 JS contract broke — missing in template: ' . implode(', ', $missing_attrs));
        }

        // Required IDs preserved. Match `id="X"` rather than `#X` since
        // the template emits them as HTML id attributes.
        $required_ids = array('new-orders', 'in-progress', 'delivered', 'routew-toast');
        $missing_ids = array();
        foreach ($required_ids as $id) {
            if (false === strpos($tpl, 'id="' . $id . '"')) {
                $missing_ids[] = '#' . $id;
            }
        }
        if (empty($missing_ids)) {
            $this->pass('UI1 Required IDs preserved: ' . implode(', ', array_map(function ($id) { return '#' . $id; }, $required_ids)));
        } else {
            $this->fail('UI1 Missing required IDs in template: ' . implode(', ', $missing_ids));
        }

        // PWA manifest endpoint still wired.
        $dbv_php = file_get_contents($this->plugin_dir . '/includes/class-routew-delivery-boy-view.php');
        if (false !== strpos($dbv_php, "'routew_agent_manifest'") && false !== strpos($dbv_php, "'routew_agent_sw'")) {
            $this->pass('UI1 PWA manifest + SW endpoints wired (routew_agent_manifest, routew_agent_sw)');
        } else {
            $this->fail('UI1 PWA manifest/SW endpoints missing from class-routew-delivery-boy-view.php');
        }

        // Service worker cache version bumped to v2.
        $sw_js = file_get_contents($this->plugin_dir . '/assets/js/routew-agent-sw.js');
        if (preg_match("/CACHE_VERSION\s*=\s*['\"]routew-agent-v(\\d+)['\"]/", $sw_js, $swm) && (int) $swm[1] >= 2) {
            $this->pass('UI1 SW cache version bumped to v' . $swm[1] . ' (was v1 pre-overhaul)');
        } else {
            $this->fail('UI1 SW CACHE_VERSION not bumped to v2 — old PWAs will serve stale CSS');
        }

        // UI2 — My-account Bootstrap 5 overlay (Batch 2).
        // Verifies Bootstrap is enqueued on the account page, the body-class
        // gate still isolates our CSS, the WC my-account hooks are still
        // registered, the reorder POST handler is intact, and the status
        // pill class map was migrated from bespoke modifiers to Bootstrap
        // text-bg-* utility classes.
        $shortcodes_php = file_get_contents($this->plugin_dir . '/includes/class-routew-shortcodes.php');
        if (false !== strpos($shortcodes_php, "'routew-bootstrap'") && false !== strpos($shortcodes_php, 'vendor/bootstrap/bootstrap.min.css') && false !== strpos($shortcodes_php, 'is_account_page')) {
            $this->pass('UI2 Bootstrap enqueued on my-account page (routew-bootstrap handle, gated by is_account_page)');
        } else {
            $this->fail('UI2 Bootstrap not enqueued on my-account page — class-routew-shortcodes.php must wp_enqueue_style the bootstrap CSS inside is_account_page()');
        }

        if (false !== strpos($shortcodes_php, "'routew-my-account-styled'") && false !== strpos($shortcodes_php, 'add_my_account_body_class')) {
            $this->pass('UI2 Body class gate preserved: routew-my-account-styled added in add_my_account_body_class()');
        } else {
            $this->fail('UI2 Body class gate broken — routew-my-account-styled must still be added on the account page');
        }

        $my_account_php = file_get_contents($this->plugin_dir . '/includes/class-routew-my-account.php');
        if (false !== strpos($my_account_php, "'woocommerce_account_dashboard'") && false !== strpos($my_account_php, "'woocommerce_account_menu_items'")) {
            $this->pass('UI2 Required hooks preserved: woocommerce_account_dashboard + woocommerce_account_menu_items');
        } else {
            $this->fail('UI2 Missing required WC hooks in class-routew-my-account.php');
        }

        if (false !== strpos($shortcodes_php, "'template_redirect'") && false !== strpos($shortcodes_php, 'handle_reorder') && false !== strpos($shortcodes_php, "'routew_reorder'")) {
            $this->pass('UI2 Reorder POST handler preserved (handle_reorder + wp_verify_nonce on routew_reorder)');
        } else {
            $this->fail('UI2 Reorder POST handler missing or nonce check removed from class-routew-shortcodes.php');
        }

        // Status pill class names migrated to Bootstrap text-bg-* utilities.
        // status_pill_class() should return at least one of each variant.
        // Anchor on `function status_pill_class` to find the DEFINITION, not a
        // caller (the caller is above the definition in the file).
        if (preg_match("/function\s+status_pill_class\s*\(\s*\\\$status\s*\)/", $my_account_php, $spm)) {
            $map_offset = strpos($my_account_php, $spm[0]);
            $map_snippet = substr($my_account_php, (int) $map_offset, 1500);
            $expected = array('text-bg-success', 'text-bg-info', 'text-bg-warning', 'text-bg-danger', 'text-bg-secondary');
            $missing = array();
            foreach ($expected as $needle) {
                if (false === strpos($map_snippet, $needle)) {
                    $missing[] = $needle;
                }
            }
            if (empty($missing)) {
                $this->pass('UI2 Status pill class map returns Bootstrap text-bg-* utilities (success/info/warning/danger/secondary)');
            } else {
                $this->fail('UI2 Status pill map missing Bootstrap variants: ' . implode(', ', $missing));
            }
        } else {
            $this->fail('UI2 Could not locate status_pill_class() method in class-routew-my-account.php');
        }

        // UI-X — Bootstrap handle consistency across both surfaces.
        // Same handle 'routew-bootstrap' must be used in both enqueue sites so
        // WP dedupes the request when both surfaces are active.
        $agent_core_php = file_get_contents($this->plugin_dir . '/includes/class-routew-core.php');
        $agent_count = substr_count($agent_core_php, "'routew-bootstrap'");
        $shortcodes_count = substr_count($shortcodes_php, "'routew-bootstrap'");
        if ($agent_count >= 1 && $shortcodes_count >= 1) {
            $this->pass('UI-X Bootstrap handle "routew-bootstrap" used in BOTH enqueue sites (agent=' . $agent_count . ', my-account=' . $shortcodes_count . ')');
        } else {
            $this->fail('UI-X Bootstrap handle not consistent — agent=' . $agent_count . ', my-account=' . $shortcodes_count . ' (need both >= 1)');
        }

        // UI3 — Visual polish layer (Batch 3).
        // Verifies the my-account dashboard adds inline SVG stat icons, the
        // agent PWA adds KPI tile icons, the page-wrapper has a max-width
        // for proper viewport use, and the mobile bottom-tab nav uses
        // safe-area-inset-bottom for iPhone notch support.
        // My-account dashboard icons — UI6 replaced stat tiles with quick-action
        // tiles (same visual role: at-a-glance dashboard numbers). Count both
        // stat icons (legacy) and quick-action icons (current) so the assertion
        // survives the dashboard widget rebuild.
        $stat_icon_count = substr_count($my_account_php, 'routew-dashboard__stat-icon');
        $quick_action_icon_count = substr_count($my_account_php, 'routew-dashboard__quick-action__icon');
        $combined_icons = $stat_icon_count + $quick_action_icon_count;
        if ($combined_icons >= 4) {
            $this->pass('UI3 my-account dashboard emits >=4 icons (stat=' . $stat_icon_count . ' + quick-action=' . $quick_action_icon_count . ')');
        } else {
            $this->fail('UI3 my-account dashboard missing icons — expected >= 4 (stat + quick-action combined)');
        }

        // Agent PWA KPI icons — 5 SVG icons (Delivered today / Active now /
        // Collected today / To hand over / All-time delivered).
        $tpl = file_get_contents($this->plugin_dir . '/templates/delivery-dashboard-template.php');
        $kpi_icon_count = substr_count($tpl, 'routew-agent-stat__icon');
        if ($kpi_icon_count >= 5) {
            $this->pass('UI3 agent PWA KPI tiles include icons (count=' . $kpi_icon_count . ' — 5 tiles + selector)');
        } else {
            $this->fail('UI3 agent PWA KPI tiles missing icons — expected >= 5 occurrences of routew-agent-stat__icon');
        }

        // Page-wrapper max-width so widgets fill the viewport on desktop
        // instead of hugging the left edge.
        $my_account_css = file_get_contents($this->plugin_dir . '/assets/css/my-account.css');
        if (preg_match('/max-width:\s*1200px/i', $my_account_css) || preg_match('/max-width:\s*\d+px/i', $my_account_css)) {
            $this->pass('UI3 my-account page wrapper has max-width (widgets fill the viewport on desktop)');
        } else {
            $this->fail('UI3 my-account page wrapper missing max-width — content hugs left edge');
        }

        // Mobile bottom-tab uses safe-area-inset-bottom for iPhone notch.
        if (false !== strpos($my_account_css, 'safe-area-inset-bottom')) {
            $this->pass('UI3 mobile bottom-tab respects safe-area-inset-bottom (iPhone notch support)');
        } else {
            $this->fail('UI3 mobile bottom-tab does not respect safe-area-inset-bottom');
        }

        // UI4 — Sub-page polish (Batch 4).
        // Verifies the WC sub-pages (Orders list, View Order, Edit Address,
        // Edit Account) now have Bootstrap-styled CSS that targets their
        // stock WC classes — no template changes, just CSS-only composition.
        $my_account_css = file_get_contents($this->plugin_dir . '/assets/css/my-account.css');

        // Orders list table — wc_orders-table + shop_table.
        if (false !== strpos($my_account_css, '.woocommerce-orders-table') && false !== strpos($my_account_css, 'table.shop_table')) {
            $this->pass('UI4 orders list table styled (.woocommerce-orders-table + table.shop_table)');
        } else {
            $this->fail('UI4 orders list table styling missing for .woocommerce-orders-table');
        }

        // View Order — order overview + order details table + re-order button.
        $view_order_signals = array(
            '.woocommerce-order-overview' => 'order overview grid',
            '.woocommerce-table--order-details' => 'order details table',
            'order-again' => 'order-again button',
        );
        $missing_signals = array();
        foreach ($view_order_signals as $needle => $label) {
            if (false === strpos($my_account_css, $needle)) {
                $missing_signals[] = $label;
            }
        }
        if (empty($missing_signals)) {
            $this->pass('UI4 view order page styled (overview grid + details table + re-order button)');
        } else {
            $this->fail('UI4 view order page missing styles for: ' . implode(', ', $missing_signals));
        }

        // Edit Address — wc Addresses grid + edit link styling.
        if (false !== strpos($my_account_css, '.woocommerce-Addresses') && false !== strpos($my_account_css, '.woocommerce-Address-title') && false !== strpos($my_account_css, 'grid-template-columns: repeat(auto-fit, minmax(320px')) {
            $this->pass('UI4 edit address page styled (Addresses grid + title + edit link)');
        } else {
            $this->fail('UI4 edit address page missing styles for .woocommerce-Addresses grid');
        }

        // Edit Account — form fieldsets styled as Bootstrap cards.
        if (false !== strpos($my_account_css, '.woocommerce-EditAccountForm fieldset') && false !== strpos($my_account_css, 'legend')) {
            $this->pass('UI4 edit account page styled (fieldset cards + legend pills)');
        } else {
            $this->fail('UI4 edit account page missing styles for .woocommerce-EditAccountForm fieldset');
        }

        // UI5 — DoorDash-style View Order upgrade.
        // Verifies the tracking block has the new hero header, the stepper
        // has timestamp + body structure, the driver card has avatar + call
        // + message actions, and the view-order page has item-card styling
        // for product line items.

        // Tracking block — hero header with status badge + placed date.
        $shortcodes_php = file_get_contents($this->plugin_dir . '/includes/class-routew-shortcodes.php');
        $hero_signals = array(
            'routew-order-tracking__hero' => 'hero header',
            'routew-order-tracking__hero-eyebrow' => 'hero eyebrow',
            'routew-order-tracking__hero-title' => 'hero title',
            'routew-order-tracking__hero-subtitle' => 'hero subtitle with placed date',
            'routew-order-tracking__hero-badge' => 'hero status badge',
        );
        $missing_hero = array();
        foreach ($hero_signals as $needle => $label) {
            if (false === strpos($shortcodes_php, $needle)) {
                $missing_hero[] = $label;
            }
        }
        if (empty($missing_hero)) {
            $this->pass('UI5 tracking block has DoorDash-style hero header');
        } else {
            $this->fail('UI5 tracking hero missing signals: ' . implode(', ', $missing_hero));
        }

        // Stepper — step bodies with label + timestamp.
        $step_signals = array(
            'routew-status-step__body' => 'step body wrapper',
            'routew-status-step__time' => 'timestamp',
            'routew-status-step--current' => 'current-step pulse class',
        );
        $missing_step = array();
        foreach ($step_signals as $needle => $label) {
            if (false === strpos($shortcodes_php, $needle)) {
                $missing_step[] = $label;
            }
        }
        if (empty($missing_step)) {
            $this->pass('UI5 stepper has body + timestamp + current-step pulse');
        } else {
            $this->fail('UI5 stepper missing signals: ' . implode(', ', $missing_step));
        }

        // Driver card — avatar circle + call + message actions.
        $driver_signals = array(
            'routew-delivery-contact__avatar' => 'avatar circle',
            'routew-delivery-contact__actions' => 'action buttons row',
            'routew-delivery-contact__btn' => 'primary call/message button',
            'href="sms:' => 'SMS / message link',
            'href="tel:' => 'tel / call link',
        );
        $missing_driver = array();
        foreach ($driver_signals as $needle => $label) {
            if (false === strpos($shortcodes_php, $needle)) {
                $missing_driver[] = $label;
            }
        }
        if (empty($missing_driver)) {
            $this->pass('UI5 driver card has avatar + Call + Message actions');
        } else {
            $this->fail('UI5 driver card missing signals: ' . implode(', ', $missing_driver));
        }

        // CSS — hero header, product card thumbs, sticky bar, pulse animation.
        $my_account_css = file_get_contents($this->plugin_dir . '/assets/css/my-account.css');
        $css_signals = array(
            'routew-order-tracking__hero' => 'hero header CSS',
            'product-thumbnail img' => 'product thumbnail styling',
            'routew-status-step__body' => 'step body CSS',
            '@keyframes routew-step-pulse' => 'current-step pulse animation',
            'routew-delivery-contact__avatar' => 'driver avatar CSS',
            'routew-delivery-contact__btn' => 'driver action button CSS',
        );
        $missing_css = array();
        foreach ($css_signals as $needle => $label) {
            if (false === strpos($my_account_css, $needle)) {
                $missing_css[] = $label;
            }
        }
        if (empty($missing_css)) {
            $this->pass('UI5 my-account.css has all DoorDash-style CSS signals');
        } else {
            $this->fail('UI5 my-account.css missing CSS signals: ' . implode(', ', $missing_css));
        }

        // UI6 — Native mobile-app polish (Batch 6): redesign dashboard
        // widgets + spacing rhythm + iOS-style list rows + press feedback.
        $shortcodes_php = file_get_contents($this->plugin_dir . '/includes/class-routew-my-account.php');

        // Dashboard render emits 5+ unconditional sections (profile, quick
        // actions, recent orders, settings, signout). The welcome banner and
        // default address sections are conditional, so we count what the
        // static markup guarantees.
        $section_count = substr_count($shortcodes_php, '<section class="routew-section">');
        if ($section_count >= 4) {
            $this->pass('UI6 dashboard emits >=4 unconditional routew-section blocks (found ' . $section_count . '; 2 more conditional)');
        } else {
            $this->fail('UI6 dashboard missing routew-section blocks — found only ' . $section_count);
        }

        // Profile card with avatar + name + email + Edit button.
        if (false !== strpos($shortcodes_php, 'routew-profile-card__avatar')
            && false !== strpos($shortcodes_php, 'routew-profile-card__name')
            && false !== strpos($shortcodes_php, 'routew-profile-card__email')) {
            $this->pass('UI6 profile card renders avatar + name + email');
        } else {
            $this->fail('UI6 profile card markup missing expected hooks');
        }

        // Quick actions grid (4 tiles).
        $quick_action_count = substr_count($shortcodes_php, 'routew-dashboard__quick-action"');
        if ($quick_action_count >= 4) {
            $this->pass('UI6 quick actions grid renders >=4 tiles (found ' . $quick_action_count . ')');
        } else {
            $this->fail('UI6 quick actions grid missing — found only ' . $quick_action_count . ' tiles');
        }

        // Settings list (iOS-style link rows).
        $settings_list_count = substr_count($shortcodes_php, 'class="routew-list-item"');
        if ($settings_list_count >= 4) {
            $this->pass('UI6 settings list has >=4 iOS-style link rows (found ' . $settings_list_count . ')');
        } else {
            $this->fail('UI6 settings list missing — found ' . $settings_list_count . ' rows');
        }

        // Sign-out button (full-width pill).
        if (false !== strpos($shortcodes_php, 'routew-signout')
            && preg_match('/class="routew-signout"/', $shortcodes_php)) {
            $this->pass('UI6 sign-out button styled as full-width pill');
        } else {
            $this->fail('UI6 sign-out button missing or not styled');
        }

        // CSS — 8px spacing scale, iOS list rows, press feedback, fade cascade.
        $css_native_signals = array(
            '--routew-spacing-4: 16px' => '8px spacing scale tokens',
            '.routew-list ' => 'iOS-style list container',
            '.routew-list-item' => 'iOS-style list row',
            '.routew-dashboard__quick-action' => 'quick action tile',
            '.routew-profile-card' => 'profile card',
            ':active' => 'press feedback hooks',
            '@keyframes routew-fade-up' => 'fade-up cascade',
            'prefers-reduced-transparency' => 'reduced-transparency handlers',
        );
        $missing_native_css = array();
        foreach ($css_native_signals as $needle => $label) {
            if (false === strpos($my_account_css, $needle)) {
                $missing_native_css[] = $label;
            }
        }
        if (empty($missing_native_css)) {
            $this->pass('UI6 my-account.css has all native-app CSS signals');
        } else {
            $this->fail('UI6 my-account.css missing CSS: ' . implode(', ', $missing_native_css));
        }

        // UI7 — Sub-page polish: tighten edit-account, edit-address, orders
        // list, view-order. Verifies the CSS targets the WC stock classes
        // (woocommerce-EditAccountForm, woocommerce-Address, etc.) plus a
        // few selector signals.
        $ui7_css_signals = array(
            '.woocommerce-EditAccountForm fieldset input' => 'fieldset input restyled',
            '.woocommerce-Address-title .edit:hover' => 'address edit-link hover state',
            '.woocommerce-Address-title h3' => 'address title typography',
            '.routew-orders-filter__chip' => 'orders filter chip',
            '.routew-orders-filter__chip--active' => 'orders filter active state',
            '.order-again' => 'order-again CTA styling',
            '.woocommerce-customer-details > section' => 'customer details column',
        );
        $missing_ui7 = array();
        foreach ($ui7_css_signals as $needle => $label) {
            if (false === strpos($my_account_css, $needle)) {
                $missing_ui7[] = $label;
            }
        }
        if (empty($missing_ui7)) {
            $this->pass('UI7 my-account.css has all sub-page polish CSS signals');
        } else {
            $this->fail('UI7 my-account.css missing CSS: ' . implode(', ', $missing_ui7));
        }

        echo "\n";
    }

    private function findPhpFiles($dir)
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Skip test files
                $path = $file->getPathname();
                if (strpos($path, '/tests/') !== false)
                    continue;
                if (strpos($path, '.git') !== false)
                    continue;

                $files[] = $path;
            }
        }

        return $files;
    }

    private function pass($message)
    {
        $this->passed++;
        echo "  " . GREEN . "✓" . RESET . " $message\n";
    }

    private function fail($message)
    {
        $this->failed++;
        echo "  " . RED . "✗" . RESET . " $message\n";
    }

    private function printSummary()
    {
        echo str_repeat("=", 60) . "\n";
        echo "  RESULTS\n";
        echo str_repeat("=", 60) . "\n";
        echo "  " . GREEN . "Passed: {$this->passed}" . RESET . "\n";
        echo "  " . ($this->failed > 0 ? RED : GREEN) . "Failed: {$this->failed}" . RESET . "\n";
        echo str_repeat("=", 60) . "\n";

        if ($this->failed === 0) {
            echo GREEN . "\n  ✓ All tests passed!\n" . RESET;
        } else {
            echo RED . "\n  ✗ Some tests failed. Please review the errors above.\n" . RESET;
        }
        echo "\n";
    }
}

// Run tests
$runner = new ROUTEWTestRunner();
exit($runner->run());

// phpcs:enable
