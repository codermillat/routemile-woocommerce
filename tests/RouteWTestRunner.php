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
            'templates/woocommerce/myaccount/dashboard.php' => 'WC template override: my-account dashboard',
            'templates/woocommerce/myaccount/my-address.php' => 'WC template override: my-account addresses',
            'assets/js/admin.js' => 'Admin JavaScript',
            'assets/js/checkout.js' => 'Checkout JavaScript (Google provider)',
            'assets/js/checkout-leaflet.js' => 'Checkout JavaScript (Leaflet providers)',
            'assets/vendor/leaflet/leaflet.js' => 'Bundled Leaflet library',
            'assets/vendor/leaflet/leaflet.css' => 'Bundled Leaflet stylesheet',
            'assets/css/routew-ui.css' => 'Scoped Bootstrap design system (Mile Zero)',
            'assets/css/my-account.css' => 'My Account shell CSS',
            'assets/css/my-account-dashboard.css' => 'My Account dashboard widgets CSS',
            'assets/css/checkout.css' => 'Checkout location picker + delivery fields CSS',
            'assets/css/tracking.css' => 'Order tracking CSS',
            'assets/css/delivery-dashboard.css' => 'Agent PWA CSS',
            'assets/fonts/routew-sans-var.woff2' => 'Bundled RouteMile Sans (Plus Jakarta Sans, OFL) variable font',
            'assets/fonts/OFL.txt' => 'Font license (SIL OFL 1.1)',
            'tools/ui/build.mjs' => 'Design-system build script (Node)',
            'tools/ui/postcss.config.js' => 'Scope-prefixing postcss config',
            'tools/ui/scss/routew-bootstrap.scss' => 'Scoped Bootstrap build entry',
            'tools/ui/scss/_tokens.scss' => 'Design tokens source (single source of truth)',
            'tools/ui/scss/_components.scss' => 'Shared component layer source',
            'assets/css-src/my-account.scss' => 'My Account shell source',
            'assets/css-src/my-account-dashboard.scss' => 'Dashboard widgets source',
            'assets/css-src/checkout.scss' => 'Checkout surface source',
            'assets/css-src/tracking.scss' => 'Tracking surface source',
            'assets/css-src/agent.scss' => 'Agent PWA source',
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

        // =====================================================================
        // Mile Zero design system (v1.6.0). The UI1–UI12 Bootstrap-overlay
        // tests are superseded: the plugin now ships a COMPILED, SCOPED
        // Bootstrap 5.3 re-skin (assets/css/routew-ui.css) built from
        // tools/ui/ sources, plus per-surface stylesheets. These tests lock
        // the new contract: scoping, the enqueue graph, markup/JS contracts,
        // and the token-driven component vocabulary.
        // =====================================================================

        // DS1 — routew-ui.css exists and is fully scoped: every rule applies
        // only under .routew-ui, so the storefront theme can never collide
        // with Bootstrap's globals (the root bug the old overlay had).
        $ui_css_path = $this->plugin_dir . '/assets/css/routew-ui.css';
        if (file_exists($ui_css_path)) {
            $ui_css = file_get_contents($ui_css_path);
            $this->pass('DS1 routew-ui.css present (' . round(strlen($ui_css) / 1024) . ' KB compiled)');

            // Bundled font wired into the build.
            if (false !== strpos($ui_css, "font-family:RouteMile Sans") && false !== strpos($ui_css, '../fonts/routew-sans-var.woff2')) {
                $this->pass('DS1 bundled RouteMile Sans @font-face wired (self-hosted, no CDN)');
            } else {
                $this->fail('DS1 bundled font @font-face missing from routew-ui.css');
            }

            // Tokens emitted on the scope root (not global :root).
            if (false !== strpos($ui_css, '.routew-ui{--rm-brand:')) {
                $this->pass('DS1 design tokens emitted on the .routew-ui scope root');
            } else {
                $this->fail('DS1 design tokens not scoped — expected .routew-ui{--rm-brand:…} at build head');
            }

            // No unscoped page-level selectors survive (Reboot's html/body
            // must have been rewritten to the scope class by postcss).
            if (preg_match('/[^.\w#-](html|body)\s*\{/', $ui_css) === 0 && false === strpos($ui_css, ':root{')) {
                $this->pass('DS1 scoping airtight — no html/body/:root rules escape the .routew-ui prefix');
            } else {
                $this->fail('DS1 unscoped rules found in routew-ui.css — theme styles will break');
            }
        } else {
            $this->fail('DS1 routew-ui.css missing — run node tools/ui/build.mjs');
        }

        // DS2 — No unscoped Bootstrap bundle anywhere: the raw
        // assets/vendor/bootstrap/ overlay is retired.
        if (!is_dir($this->plugin_dir . '/assets/vendor/bootstrap')) {
            $this->pass('DS2 raw Bootstrap vendor bundle removed (no unscoped framework CSS)');
        } else {
            $this->fail('DS2 assets/vendor/bootstrap/ still exists — must not ship alongside the scoped build');
        }
        $bootstrap_refs = 0;
        foreach (glob($this->plugin_dir . '/includes/*.php') as $inc) {
            $bootstrap_refs += substr_count(file_get_contents($inc), 'vendor/bootstrap');
        }
        if (0 === $bootstrap_refs) {
            $this->pass('DS2 no PHP enqueue references the old vendor/bootstrap path');
        } else {
            $this->fail('DS2 ' . $bootstrap_refs . ' enqueue reference(s) to vendor/bootstrap remain');
        }

        // DS3 — Enqueue graph: every surface loads the scoped build under
        // the shared 'routew-ui' handle, with surface CSS depending on it.
        $core_php = file_get_contents($this->plugin_dir . '/includes/class-routew-core.php');
        $shortcodes_php = file_get_contents($this->plugin_dir . '/includes/class-routew-shortcodes.php');
        $maps_php = file_get_contents($this->plugin_dir . '/includes/class-routew-checkout-maps.php');
        $my_account_php = file_get_contents($this->plugin_dir . '/includes/class-routew-my-account.php');

        if (false !== strpos($core_php, "'routew-ui'") && false !== strpos($core_php, 'routew-ui.css') && false !== strpos($core_php, "array('routew-ui')")) {
            $this->pass('DS3 agent PWA enqueues routew-ui + delivery-dashboard (dep: routew-ui)');
        } else {
            $this->fail('DS3 agent PWA enqueue graph broken in class-routew-core.php');
        }

        if (false !== strpos($shortcodes_php, "'routew-ui'")
            && false !== strpos($shortcodes_php, 'is_account_page')
            && false !== strpos($shortcodes_php, 'routew-my-account')
            && false !== strpos($shortcodes_php, 'routew-tracking')) {
            $this->pass('DS3 my-account enqueues routew-ui + my-account + tracking (gated by is_account_page)');
        } else {
            $this->fail('DS3 my-account enqueue graph broken in class-routew-shortcodes.php');
        }

        if (false !== strpos($maps_php, "'routew-ui'") && false !== strpos($maps_php, 'routew-checkout') && false !== strpos($maps_php, 'checkout.css')) {
            $this->pass('DS3 checkout enqueues routew-ui + checkout surface CSS');
        } else {
            $this->fail('DS3 checkout enqueue graph broken in class-routew-checkout-maps.php');
        }

        // Old global handles retired.
        if (false === strpos($shortcodes_php, 'routew-frontend') && false === strpos($maps_php, 'frontend.css')) {
            $this->pass('DS3 retired routew-frontend / frontend.css global handle');
        } else {
            $this->fail('DS3 frontend.css global handle still enqueued somewhere');
        }

        // DS4 — Account scope wrapper: .routew-ui opens before any endpoint
        // output (priority 0) and closes after everything (9999), so WC's
        // own endpoint markup (orders table, forms) is styled by the scoped
        // build without touching the theme. On the dashboard root the
        // wrapper also carries routew-account--dashboard, which the CSS
        // uses to suppress WC's default "Hello user" greeting.
        if (false !== strpos($shortcodes_php, 'open_account_ui_scope') && false !== strpos($shortcodes_php, 'close_account_ui_scope')
            && false !== strpos($shortcodes_php, "'woocommerce_account_content', array(\$this, 'open_account_ui_scope'), 0")
            && false !== strpos($shortcodes_php, "'woocommerce_account_content', array(\$this, 'close_account_ui_scope'), 9999")
            && false !== strpos($shortcodes_php, 'routew-account--dashboard')
            && false !== strpos(file_get_contents($this->plugin_dir . '/assets/css/my-account.css'), '.routew-ui.routew-account--dashboard>p{display:none}')) {
            $this->pass('DS4 account scope wrapper (0/9999) + dashboard greeting suppression wired');
        } else {
            $this->fail('DS4 account scope wrapper hooks or greeting suppression missing');
        }

        // DS4b — Scope-root same-element selectors. Components that live ON
        // the .routew-ui wrapper element (account wrapper, checkout cards,
        // agent app shell, track page) MUST be matched with `.routew-ui.foo`
        // (same element). The descendant form `.routew-ui .foo` never matches
        // (an element cannot be its own descendant) — the exact bug that
        // made v1.6.0-dev render endpoint tables, checkout cards, and the
        // agent shell unstyled.
        $same_element_specs = array(
            'assets/css/my-account.css'         => '.routew-ui.routew-account',
            'assets/css/checkout.css'           => '.routew-ui.routew-checkout-card',
            'assets/css/delivery-dashboard.css' => '.routew-ui.routew-app-dashboard',
            'assets/css/tracking.css'           => '.routew-ui.routew-track-order-page',
        );
        foreach ($same_element_specs as $rel => $needle) {
            $css = file_get_contents($this->plugin_dir . '/' . $rel);
            if (false !== strpos($css, $needle)) {
                $this->pass('DS4b ' . basename($rel) . ' matches the scope root with a same-element selector (' . $needle . ')');
            } else {
                $this->fail('DS4b ' . basename($rel) . ' lost the same-element scope-root selector — ' . $needle . ' missing');
            }
        }

        // The broken descendant forms must NOT exist (scope-root classes
        // reached via `.routew-ui .x` never match). Match the rule-opening
        // brace or a trailing space so legitimate child selectors like
        // `.routew-ui .routew-checkout-card__body` are not flagged.
        $broken_descendants = array(
            'assets/css/my-account.css'         => array('.routew-ui .routew-account{', '.routew-ui .routew-account '),
            'assets/css/checkout.css'           => array('.routew-ui .routew-checkout-card{', '.routew-ui .routew-checkout-card '),
            'assets/css/delivery-dashboard.css' => array('.routew-ui .routew-app-dashboard{', '.routew-ui .routew-app-dashboard '),
            'assets/css/tracking.css'           => array('.routew-ui .routew-track-order-page{', '.routew-ui .routew-track-order-page '),
        );
        foreach ($broken_descendants as $rel => $needles) {
            $css = file_get_contents($this->plugin_dir . '/' . $rel);
            $hits = array();
            foreach ($needles as $needle) {
                if (false !== strpos($css, $needle)) {
                    $hits[] = $needle;
                }
            }
            if (empty($hits)) {
                $this->pass('DS4b ' . basename($rel) . ' has no broken descendant selector for the scope root');
            } else {
                $this->fail('DS4b ' . basename($rel) . ' still contains broken descendant selector(s): ' . implode(', ', $hits));
            }
        }

        // DS5 — Agent PWA JS contract preserved verbatim (delivery-dashboard.js
        // depends on these attributes + IDs) and the PWA shell is scoped.
        // The order-card markup lives in includes/agent-template-helpers.php
        // (extracted so AJAX handlers can render a single card) — the
        // contract spans both files.
        $tpl = file_get_contents($this->plugin_dir . '/templates/delivery-dashboard-template.php');
        $agent_helpers = file_get_contents($this->plugin_dir . '/includes/agent-template-helpers.php');
        $tpl_all = $tpl . $agent_helpers;
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
            if (false === strpos($tpl_all, $attr)) {
                $missing_attrs[] = $attr;
            }
        }
        if (empty($missing_attrs)) {
            $this->pass('DS5 agent JS contract preserved: all 8 data-* attributes present');
        } else {
            $this->fail('DS5 agent JS contract broke — missing: ' . implode(', ', $missing_attrs));
        }

        $required_ids = array('new-orders', 'in-progress', 'delivered', 'routew-toast');
        $missing_ids = array();
        foreach ($required_ids as $id) {
            if (false === strpos($tpl, 'id="' . $id . '"')) {
                $missing_ids[] = '#' . $id;
            }
        }
        if (empty($missing_ids)) {
            $this->pass('DS5 agent required IDs preserved (' . implode(', ', array_map(function ($id) { return '#' . $id; }, $required_ids)) . ')');
        } else {
            $this->fail('DS5 missing agent IDs: ' . implode(', ', $missing_ids));
        }

        if (false !== strpos($tpl, 'routew-ui routew-app-dashboard')) {
            $this->pass('DS5 agent PWA root carries the .routew-ui scope class');
        } else {
            $this->fail('DS5 agent PWA root missing .routew-ui scope class');
        }

        // Agent order cards use the tinted pill variants.
        if (false !== strpos($tpl_all, "routew-pill routew-pill--")) {
            $this->pass('DS5 agent order cards use design-system status pills (routew-pill--*)');
        } else {
            $this->fail('DS5 agent order cards not using routew-pill--* variants');
        }

        // PWA manifest + SW endpoints still wired.
        $dbv_php = file_get_contents($this->plugin_dir . '/includes/class-routew-delivery-boy-view.php');
        if (false !== strpos($dbv_php, "'routew_agent_manifest'") && false !== strpos($dbv_php, "'routew_agent_sw'")) {
            $this->pass('DS5 PWA manifest + SW endpoints wired');
        } else {
            $this->fail('DS5 PWA manifest/SW endpoints missing from class-routew-delivery-boy-view.php');
        }

        // SW cache bumped for the design-system CSS swap.
        $sw_js = file_get_contents($this->plugin_dir . '/assets/js/routew-agent-sw.js');
        if (preg_match("/CACHE_VERSION\s*=\s*['\"]routew-agent-v(\\d+)['\"]/", $sw_js, $swm) && (int) $swm[1] >= 3) {
            $this->pass('DS5 SW cache version bumped to v' . $swm[1] . ' (CSS filename set changed)');
        } else {
            $this->fail('DS5 SW CACHE_VERSION not bumped to v3 — installed PWAs will serve stale CSS');
        }

        // DS6 — My-account dashboard markup: greeting hero, quick actions,
        // delivery-native pills, order rows with item summary.
        $dash_signals = array(
            'routew-hero__eyebrow'        => 'greeting hero',
            'routew-quick-action"'        => 'quick action tile',
            'routew-order-row'            => 'recent order row',
            'routew-pill routew-pill--'   => 'delivery-native status pill',
            'routew-avatar'               => 'avatar component',
            'routew-btn-signout'          => 'sign-out button',
            'routew-reorder-banner'       => 'reorder banner',
            'routew-empty__'              => 'empty state',
        );
        $missing_dash = array();
        foreach ($dash_signals as $needle => $label) {
            if (false === strpos($my_account_php, $needle)) {
                $missing_dash[] = $label;
            }
        }
        if (empty($missing_dash)) {
            $this->pass('DS6 dashboard emits the full component vocabulary (hero, tiles, rows, pills)');
        } else {
            $this->fail('DS6 dashboard missing components: ' . implode(', ', $missing_dash));
        }

        // Four quick-action tiles.
        $quick_action_count = substr_count($my_account_php, 'class="routew-quick-action"');
        if ($quick_action_count >= 4) {
            $this->pass('DS6 quick actions grid renders >=4 tiles (found ' . $quick_action_count . ')');
        } else {
            $this->fail('DS6 quick actions grid missing tiles (found ' . $quick_action_count . ')');
        }

        // Status pills speak delivery language, not WC internals. Since
        // the stage-labels feature the pill map is delegated to
        // ROUTEW_Stage_Labels (admin-renameable), so the vocabulary is
        // verified on the helper + the delegation wiring.
        if (preg_match("/function\s+order_status_pill\s*\(\s*\\\$status\s*\)/", $my_account_php, $spm)) {
            $map_snippet = substr($my_account_php, (int) strpos($my_account_php, $spm[0]), 800);
            $stage_labels_php = file_get_contents($this->plugin_dir . '/includes/class-routew-stage-labels.php');
            $delegation_ok = false !== strpos($map_snippet, 'ROUTEW_Stage_Labels::status_colour')
                && false !== strpos($map_snippet, 'ROUTEW_Stage_Labels::status_label');
            $expected_variants = array('placed', 'preparing', 'assigned', 'transit', 'delivered', 'cancelled');
            $expected_labels = array('On the way', 'In the kitchen', 'Rider assigned', 'Delivered');
            $missing_variants = array();
            foreach (array_merge($expected_variants, $expected_labels) as $needle) {
                if (false === strpos($stage_labels_php, $needle)) {
                    $missing_variants[] = $needle;
                }
            }
            if ($delegation_ok && empty($missing_variants)) {
                $this->pass('DS6 order_status_pill() maps delivery variants + customer-facing labels (via ROUTEW_Stage_Labels)');
            } else {
                $this->fail('DS6 order_status_pill() missing: ' . implode(', ', $missing_variants) . (!$delegation_ok ? ' + stage-labels delegation' : ''));
            }
        } else {
            $this->fail('DS6 could not locate order_status_pill() in class-routew-my-account.php');
        }

        // Order summary line ("Chicken Biryani ×2 +1 more").
        if (false !== strpos($my_account_php, 'order_item_summary')) {
            $this->pass('DS6 recent orders show a first-item summary line (order_item_summary)');
        } else {
            $this->fail('DS6 order_item_summary() missing — rows lose the food-app item preview');
        }

        // Required WC hooks + chip filter preserved through the redesign.
        if (false !== strpos($my_account_php, "'woocommerce_account_dashboard'") && false !== strpos($my_account_php, "'woocommerce_account_menu_items'")) {
            $this->pass('DS6 required WC hooks preserved (dashboard + menu items)');
        } else {
            $this->fail('DS6 missing required WC hooks in class-routew-my-account.php');
        }
        if (false !== strpos($my_account_php, "add_filter('woocommerce_my_account_my_orders_query'")
            && false !== strpos($my_account_php, 'filter_my_orders_by_chip_status')
            && preg_match("/'cancelled'\s*=>\s*array\('cancelled',\s*'failed',\s*'refunded'\)/", $my_account_php)) {
            $this->pass('DS6 ?status= chip still filters the orders query');
        } else {
            $this->fail('DS6 chip filter not wired (orders table shows unfiltered list)');
        }

        // Reorder POST handler + body-class gate preserved.
        if (false !== strpos($shortcodes_php, "'template_redirect'") && false !== strpos($shortcodes_php, 'handle_reorder') && false !== strpos($shortcodes_php, "'routew_reorder'")) {
            $this->pass('DS6 reorder POST handler preserved (nonce-verified)');
        } else {
            $this->fail('DS6 reorder POST handler missing from class-routew-shortcodes.php');
        }
        if (false !== strpos($shortcodes_php, "'routew-my-account-styled'") && false !== strpos($shortcodes_php, 'add_my_account_body_class')) {
            $this->pass('DS6 body-class gate preserved (routew-my-account-styled)');
        } else {
            $this->fail('DS6 body-class gate broken');
        }

        // DS7 — Tracking UI: hero in delivery language, pulsing current
        // step, rider card with call/message actions.
        $tracking_signals = array(
            'routew-tracking-hero__eyebrow' => 'hero eyebrow',
            'routew-tracking-hero__title'   => 'hero title',
            'routew-status-stepper'         => 'stepper',
            'routew-status-step--current'   => 'current-step class',
            'routew-status-step__time--live' => 'live in-progress timestamp',
            'routew-rider-card__btn--call'  => 'call action',
            'routew-rider-card__btn--msg'   => 'message action',
            'href="tel:'                    => 'tel link',
            'href="sms:'                    => 'sms link',
        );
        $missing_tracking = array();
        foreach ($tracking_signals as $needle => $label) {
            if (false === strpos($shortcodes_php, $needle)) {
                $missing_tracking[] = $label;
            }
        }
        if (empty($missing_tracking)) {
            $this->pass('DS7 tracking UI complete (hero, stepper, live step, rider card, tel/sms)');
        } else {
            $this->fail('DS7 tracking UI missing: ' . implode(', ', $missing_tracking));
        }

        $tracking_css = file_get_contents($this->plugin_dir . '/assets/css/tracking.css');
        if (false !== strpos($tracking_css, 'routew-step-pulse') && false !== strpos($tracking_css, 'prefers-reduced-motion')) {
            $this->pass('DS7 tracking CSS has current-step pulse + reduced-motion guard');
        } else {
            $this->fail('DS7 tracking CSS missing pulse animation or reduced-motion guard');
        }

        // DS8 — Checkout contract: numbered step cards, all JS-bound IDs,
        // no inline layout styles.
        $checkout_php = file_get_contents($this->plugin_dir . '/includes/class-routew-checkout.php');
        $checkout_ids = array(
            'id="routew-location-picker-container"',
            'id="routew-location-search-input"',
            'id="routew-get-location"',
            'id="routew-map"',
            'id="routew-selected-location"',
            'id="routew-selected-address"',
            'id="routew_lat"',
            'id="routew_lng"',
            'id="routew-delivery-details-container"',
        );
        $missing_checkout_ids = array();
        foreach ($checkout_ids as $needle) {
            if (false === strpos($checkout_php, $needle)) {
                $missing_checkout_ids[] = $needle;
            }
        }
        if (empty($missing_checkout_ids)) {
            $this->pass('DS8 checkout JS contract preserved (all 9 IDs)');
        } else {
            $this->fail('DS8 checkout JS contract broke — missing: ' . implode(', ', $missing_checkout_ids));
        }

        // checkout.js reveals #routew-selected-location via slideDown(), so
        // it must start hidden without relying on CSS.
        if (false !== strpos($checkout_php, 'id="routew-selected-location" class="routew-location-confirm" style="display:none;"')) {
            $this->pass('DS8 selected-location banner starts display:none (slideDown contract)');
        } else {
            $this->fail('DS8 selected-location banner must keep style="display:none;" for the slideDown contract');
        }

        // Numbered step chips + no inline layout styles on the map.
        if (false !== strpos($checkout_php, 'routew-checkout-step__num" aria-hidden="true">1<') && false !== strpos($checkout_php, '>2<')
            && false === strpos($checkout_php, 'style="height: 300px')) {
            $this->pass('DS8 checkout renders numbered step cards (no inline layout CSS)');
        } else {
            $this->fail('DS8 checkout step markup broken or inline styles returned');
        }

        $checkout_css = file_get_contents($this->plugin_dir . '/assets/css/checkout.css');
        if (false !== strpos($checkout_css, 'routew-skeleton-loading') && false !== strpos($checkout_css, '#routew-map')) {
            $this->pass('DS8 checkout CSS covers map shell + skeleton shimmer');
        } else {
            $this->fail('DS8 checkout CSS missing map/skeleton coverage');
        }

        // DS9 — Surface stylesheets keep their key selectors (regression
        // guard for the compiled artifacts).
        $surface_expectations = array(
            'assets/css/my-account.css' => array(
                '.woocommerce-MyAccount-navigation' => 'account nav rail',
                '.routew-orders-filter__chip--active' => 'filter chip active state',
                'table.woocommerce-orders-table' => 'orders table styling',
                '.routew-subpage-header' => 'sub-page header',
            ),
            'assets/css/my-account-dashboard.css' => array(
                '.routew-hero' => 'greeting hero',
                '.routew-quick-actions' => 'quick actions grid',
                '.routew-order-row' => 'order row',
                '.routew-reorder-banner' => 'reorder banner',
            ),
            'assets/css/delivery-dashboard.css' => array(
                '.routew-app-header' => 'agent app header',
                '.routew-tabbar' => 'bottom tab bar',
                '.routew-cod-strip' => 'COD strip',
                'safe-area-inset-bottom' => 'iPhone safe-area support',
            ),
        );
        foreach ($surface_expectations as $rel => $signals) {
            $css = file_get_contents($this->plugin_dir . '/' . $rel);
            $missing = array();
            foreach ($signals as $needle => $label) {
                if (false === strpos($css, $needle)) {
                    $missing[] = $label;
                }
            }
            if (empty($missing)) {
                $this->pass('DS9 ' . basename($rel) . ' keeps all key selectors');
            } else {
                $this->fail('DS9 ' . basename($rel) . ' missing selectors: ' . implode(', ', $missing));
            }
        }

        // DS10 — Text-domain hygiene: the redesign must not regress the
        // translation domain (the plugin uses routemile-for-woocommerce).
        $domain_typos = 0;
        foreach (glob($this->plugin_dir . '/includes/*.php') as $inc) {
            $domain_typos += substr_count(file_get_contents($inc), "'routemile-woocommerce'");
        }
        foreach (glob($this->plugin_dir . '/templates/*.php') as $tpl_file) {
            $domain_typos += substr_count(file_get_contents($tpl_file), "'routemile-woocommerce'");
        }
        if (0 === $domain_typos) {
            $this->pass('DS10 no misspelled text domains (routemile-woocommerce) in includes/ or templates/');
        } else {
            $this->fail('DS10 found ' . $domain_typos . ' misspelled text-domain string(s)');
        }

        // =====================================================================
        // DS11 — WooCommerce template overrides (the "fix WordPress itself"
        // layer). The plugin ships its own copies of two WC my-account
        // templates and redirects WC to them via the
        // `woocommerce_locate_template` filter. This locks the contract:
        // the filter is registered, the templates mirror WC 9.3's API
        // surface exactly (no array_replace-on-strings fatal, correct
        // edit URLs, empty-state handling, preserved actions), and the
        // CSS targets the plugin-owned address classes.
        // =====================================================================

        $sc_php_ds11 = file_get_contents($this->plugin_dir . '/includes/class-routew-shortcodes.php');
        if (false !== strpos($sc_php_ds11, "add_filter('woocommerce_locate_template', array(\$this, 'override_wc_templates'), 10, 3)")
            && false !== strpos($sc_php_ds11, 'function override_wc_templates')) {
            $this->pass('DS11 woocommerce_locate_template filter registered + override_wc_templates() defined');
        } else {
            $this->fail('DS11 template-override filter not wired in class-routew-shortcodes.php');
        }

        $dash_tpl = file_get_contents($this->plugin_dir . '/templates/woocommerce/myaccount/dashboard.php');
        $dash_signals = array(
            "do_action( 'woocommerce_account_dashboard' )"   => 'dashboard action preserved',
            "do_action( 'woocommerce_before_my_account' )"   => 'deprecated before-action preserved',
            "do_action( 'woocommerce_after_my_account' )"    => 'deprecated after-action preserved',
            'defined( \'ABSPATH\' )'                          => 'ABSPATH guard',
        );
        $missing_dash = array();
        foreach ($dash_signals as $needle => $label) {
            if (false === strpos($dash_tpl, $needle)) {
                $missing_dash[] = $label;
            }
        }
        if (false !== strpos($dash_tpl, 'Hello %1$s')) {
            $missing_dash[] = 'still prints the core Hello greeting (override pointless)';
        }
        if (empty($missing_dash)) {
            $this->pass('DS11 dashboard override: 3 actions preserved, core greeting paragraphs stripped');
        } else {
            $this->fail('DS11 dashboard override broken: ' . implode(', ', $missing_dash));
        }

        $addr_tpl = file_get_contents($this->plugin_dir . '/templates/woocommerce/myaccount/my-address.php');
        $addr_signals = array(
            'wc_get_account_formatted_address'               => 'correct WC 9.3 address API',
            "wc_get_endpoint_url( 'edit-address', \$routew_address_name )" => 'pretty edit URL (not ?name)',
            'woocommerce_my_account_get_addresses'           => 'addresses filter preserved',
            'woocommerce_my_account_my_address_description'  => 'description filter preserved',
            'woocommerce_my_account_after_my_address'        => 'after-address action preserved',
            'You have not set up this type of address yet'   => 'empty-state handling',
            'routew-address-grid'                            => 'plugin-owned grid markup',
            'routew-address-col--'                           => 'typed card classes',
            'defined( \'ABSPATH\' )'                          => 'ABSPATH guard',
        );
        $missing_addr = array();
        foreach ($addr_signals as $needle => $label) {
            if (false === strpos($addr_tpl, $needle)) {
                $missing_addr[] = $label;
            }
        }
        // The fatal from the first attempt: array_replace() on the filter's
        // title strings. Guard against it ever coming back.
        if (false !== strpos($addr_tpl, 'array_replace')) {
            $missing_addr[] = 'array_replace present (fatals on WC 9.3 title-map data)';
        }
        if (empty($missing_addr)) {
            $this->pass('DS11 my-address override mirrors WC 9.3 API (9 signals, no array_replace)');
        } else {
            $this->fail('DS11 my-address override broken: ' . implode(', ', $missing_addr));
        }

        // CSS targets the plugin-owned selectors (grid + typed cards).
        $ma_css_ds11 = file_get_contents($this->plugin_dir . '/assets/css/my-account.css');
        foreach (array('.routew-address-grid', '.routew-address-col', '.routew-address-edit', '.routew-lead') as $needle) {
            if (false === strpos($ma_css_ds11, $needle)) {
                $this->fail('DS11 compiled my-account.css missing selector: ' . $needle);
                $missing_addr[] = $needle;
            }
        }
        if (empty($missing_addr)) {
            $this->pass('DS11 compiled CSS targets the plugin-owned address classes');
        }

        // =====================================================================
        // DS12 — Accurate order tracking + mandatory phone (2026-09 fix
        // round). Locks four contracts:
        //  A. Every status transition stamps a `_routew_status_at_{status}`
        //     GMT timestamp (via the official woocommerce_order_status_changed
        //     hook) — the tracking stepper reads these for real step times.
        //  B. The tracking UI maps EVERY WC + routew-* status to its own
        //     pill/hero/step (pending orders no longer render "In the
        //     kitchen"), renders 5 steps with real timestamps, and never
        //     shows "Pending" on a finished order's completed steps.
        //  C. The customer phone is required on both checkout surfaces
        //     (option filter for the Checkout block + billing fields filter
        //     for classic) and format-validated server-side.
        //  D. The view-order page styles the Re-order action and WC's
        //     status <mark> with the design-system pill vocabulary.
        // =====================================================================

        // --- A: status timestamp recording ---
        $statuses_php = file_get_contents($this->plugin_dir . '/includes/class-routew-order-statuses.php');
        if (false !== strpos($statuses_php, "add_action('woocommerce_order_status_changed', array(\$this, 'record_status_timestamp'), 10, 4)")
            && false !== strpos($statuses_php, 'function record_status_timestamp')
            && false !== strpos($statuses_php, "_routew_status_at_' . \$status_to")
            && false !== strpos($statuses_php, '$order->save()')) {
            $this->pass('DS12 status transitions record _routew_status_at_* meta (official hook + save)');
        } else {
            $this->fail('DS12 status timestamp recording missing in class-routew-order-statuses.php');
        }

        // --- B: tracking state map + step times ---
        $sc_php_ds12 = file_get_contents($this->plugin_dir . '/includes/class-routew-shortcodes.php');
        $ds12_states = array(
            "'pending' =>"        => 'pending state defined',
            "'on-hold' =>"        => 'on-hold state defined',
            "'processing' =>"     => 'processing state defined',
            "'routew-in-kitchen' =>" => 'in-kitchen state defined',
            "'routew-assigned' =>"   => 'assigned state defined',
            "'routew-picked-up' =>"  => 'picked-up state defined',
            "'completed' =>"      => 'completed state defined',
            "'cancelled' =>"      => 'cancelled state defined',
            "'failed' =>"         => 'failed state defined',
            "'refunded' =>"       => 'refunded state defined',
        );
        $missing_states = array();
        foreach ($ds12_states as $needle => $label) {
            if (false === strpos($sc_php_ds12, $needle)) {
                $missing_states[] = $label;
            }
        }
        if (empty($missing_states)) {
            $this->pass('DS12 tracking map covers all 10 WC + routew-* statuses');
        } else {
            $this->fail('DS12 tracking map missing states: ' . implode(', ', $missing_states));
        }

        // Pending maps to its own step 0 ("Order placed"), NOT the kitchen
        // step — the old map collapsed pending/on-hold/processing onto
        // step 0 = "In the kitchen" and lied about pending orders.
        // Round 8: the timeline is 4 sequential steps; "Rider assigned"
        // is NOT a stepper row (assigned orders are still in the
        // kitchen) — completed = step 3.
        if (false !== strpos($sc_php_ds12, "__('Order placed', 'routemile-for-woocommerce')")
            && false !== strpos($sc_php_ds12, "'step' => 3")
            && false !== strpos($sc_php_ds12, "'step' => -1")
            && false === strpos($sc_php_ds12, "'pending' => 0,")) {
            $this->pass('DS12 4-step sequential timeline (Order placed first; assigned = still in kitchen)');
        } else {
            $this->fail('DS12 step map regressed — pending orders must not map to the kitchen step');
        }

        // Step times read the recorded meta; finished steps without data
        // render an em dash, never "Pending".
        if (false !== strpos($sc_php_ds12, '_routew_status_at_')
            && false !== strpos($sc_php_ds12, 'function step_datetime')
            && false !== strpos($sc_php_ds12, 'wc_timezone_string()')
            && false !== strpos($sc_php_ds12, '&mdash;')) {
            $this->pass('DS12 step times from recorded meta; unknown times render — (never Pending)');
        } else {
            $this->fail('DS12 step timestamp resolution broken in output_tracking_ui()');
        }

        // Rider info is still surfaced, plus honest placeholders when no
        // rider is linked (assigned / delivered-by-store notes).
        if (false !== strpos($sc_php_ds12, 'routew-rider-card')
            && false !== strpos($sc_php_ds12, 'routew-rider-note')
            && false !== strpos($sc_php_ds12, 'routew_agent_phone')) {
            $this->pass('DS12 rider card + no-rider notes rendered with agent phone lookup');
        } else {
            $this->fail('DS12 rider card markup regressed');
        }

        // --- C: mandatory phone ---
        $addr_php_ds12 = file_get_contents($this->plugin_dir . '/includes/class-routew-checkout-address.php');
        if (false !== strpos($addr_php_ds12, "add_filter('option_woocommerce_checkout_phone_field', array(\$this, 'force_phone_required'))")
            && false !== strpos($addr_php_ds12, "add_filter('default_option_woocommerce_checkout_phone_field', array(\$this, 'force_phone_required'))")
            && false !== strpos($addr_php_ds12, "add_filter('woocommerce_billing_fields', array(\$this, 'require_phone_field'), 20)")
            && false !== strpos($addr_php_ds12, "return 'required';")) {
            $this->pass('DS12 phone forced required on both checkout surfaces (option + billing-fields filters)');
        } else {
            $this->fail('DS12 phone-required wiring missing in class-routew-checkout-address.php');
        }

        $handler_php_ds12 = file_get_contents($this->plugin_dir . '/includes/class-routew-checkout-handler.php');
        if (false !== strpos($handler_php_ds12, "add_action('woocommerce_after_checkout_validation', array(\$this, 'validate_phone_number'), 20, 2)")
            && false !== strpos($handler_php_ds12, 'function validate_phone_number')
            && false !== strpos($handler_php_ds12, 'strlen($digits) < 7 || strlen($digits) > 15')) {
            $this->pass('DS12 phone format validated server-side (7-15 digits)');
        } else {
            $this->fail('DS12 phone format validation missing in class-routew-checkout-handler.php');
        }

        // --- D: view-order styling ---
        $ds12_css_signals = array(
            'td a.order-actions-button' => 'quiet action pill',
            'td a.routew_reorder'       => 'brand Re-order CTA',
            'mark.order-status'         => 'WC status mark pill',
            'status-routew-in-kitchen'  => 'kitchen mark variant',
            'status-routew-picked-up'   => 'picked-up mark variant',
            'status-completed'          => 'completed mark variant',
            'status-cancelled'          => 'cancelled mark variant',
        );
        $missing_css = array();
        foreach ($ds12_css_signals as $needle => $label) {
            if (false === strpos($ma_css_ds11, $needle)) {
                $missing_css[] = $label;
            }
        }
        if (empty($missing_css)) {
            $this->pass('DS12 view-order CSS: Re-order CTA + status-mark pill variants present');
        } else {
            $this->fail('DS12 compiled my-account.css missing: ' . implode(', ', $missing_css));
        }

        $tracking_css_ds12 = file_get_contents($this->plugin_dir . '/assets/css/tracking.css');
        if (false !== strpos($tracking_css_ds12, '.routew-rider-note')) {
            $this->pass('DS12 tracking CSS covers the rider-note strip');
        } else {
            $this->fail('DS12 compiled tracking.css missing .routew-rider-note');
        }

        // =====================================================================
        // DS13 — Round 8: creation-time stamps, COD cash gate, thank-you
        // scope. Locks four contracts:
        //  A. The status an order is CREATED with also gets a timestamp —
        //     WooCommerce never fires woocommerce_order_status_changed for
        //     the initial assignment (WC_Order::set_status records no
        //     transition without a previous status), so a COD order created
        //     directly as processing needed its own hook.
        //  B. COD orders cannot be marked delivered until the agent
        //     confirms the cash was collected (UI button + server-side
        //     gates on BOTH the AJAX and admin_post paths).
        //  C. The order-received page is scoped (.routew-ui) so the
        //     tracking block + WC markup render styled, with a thank-you
        //     hero for the success notice.
        //  D. The rider card shows the recorded assignment time; a current
        //     stepper step shows "Started <time>" when a stamp exists.
        // =====================================================================

        // --- A: creation-time stamping ---
        if (false !== strpos($statuses_php, "add_action('woocommerce_new_order', array(\$this, 'record_initial_status_timestamp'), 10, 2)")
            && false !== strpos($statuses_php, 'function record_initial_status_timestamp')
            && false !== strpos($statuses_php, "in_array(\$status, array('new', 'auto-draft', 'checkout-draft', 'draft'), true)")) {
            $this->pass('DS13 creation status also stamped (woocommerce_new_order, draft statuses skipped)');
        } else {
            $this->fail('DS13 initial-status stamping missing in class-routew-order-statuses.php');
        }

        // --- B: COD cash gate ---
        $agent_php_ds13 = file_get_contents($this->plugin_dir . '/includes/class-routew-delivery-boy-view.php');
        if (false !== strpos($agent_php_ds13, "add_action('wp_ajax_routew_confirm_cash_collected', array(\$this, 'ajax_confirm_cash_collected'))")
            && false !== strpos($agent_php_ds13, 'function is_cash_collected')
            && false !== strpos($agent_php_ds13, "_routew_cash_collected")
            && false !== strpos($agent_php_ds13, 'set_date_paid')) {
            $this->pass('DS13 cash-collected AJAX handler records meta + paid date');
        } else {
            $this->fail('DS13 cash confirmation handler missing in class-routew-delivery-boy-view.php');
        }

        $gate_count = substr_count($agent_php_ds13, "is_cash_collected(\$order)");
        if ($gate_count >= 3) {
            $this->pass('DS13 COD gate enforced on AJAX + admin_post paths (' . $gate_count . ' checks)');
        } else {
            $this->fail('DS13 COD gate must cover ajax_update_delivery_status AND mark_delivered (found ' . $gate_count . ' is_cash_collected call sites)');
        }

        $agent_tpl_ds13 = file_get_contents($this->plugin_dir . '/templates/delivery-dashboard-template.php')
            . file_get_contents($this->plugin_dir . '/includes/agent-template-helpers.php');
        if (false !== strpos($agent_tpl_ds13, 'routew-button-cash')
            && false !== strpos($agent_tpl_ds13, 'data-action="routew_confirm_cash_collected"')
            && false !== strpos($agent_tpl_ds13, 'routew-button-deliver--locked')
            && false !== strpos($agent_tpl_ds13, 'routew-cash-hint')
            && false !== strpos($agent_tpl_ds13, 'routew-cod-strip--collected')) {
            $this->pass('DS13 agent card renders cash CTA + locked deliver + collected strip');
        } else {
            $this->fail('DS13 agent template missing the COD cash-gate markup');
        }

        $agent_js_ds13 = file_get_contents($this->plugin_dir . '/assets/js/delivery-dashboard.js');
        if (false !== strpos($agent_js_ds13, "data('action')")
            && false !== strpos($agent_js_ds13, "data('confirm')")
            && false !== strpos($agent_js_ds13, 'function applyActionResponse')
            && false !== strpos($agent_js_ds13, 'applyActionResponse($button, response.data)')) {
            $this->pass('DS13 agent JS dispatches data-action endpoints + applies responses in place');
        } else {
            $this->fail('DS13 agent JS still hardcodes the single status endpoint or reloads after actions');
        }

        $agent_css_ds13 = file_get_contents($this->plugin_dir . '/assets/css/delivery-dashboard.css');
        foreach (array('.routew-button-cash', '.routew-cash-hint', 'routew-button-deliver--locked', 'routew-cod-strip--collected') as $needle) {
            if (false === strpos($agent_css_ds13, $needle)) {
                $this->fail('DS13 compiled delivery-dashboard.css missing selector: ' . $needle);
            }
        }
        $this->pass('DS13 compiled agent CSS covers the cash-gate components');

        // --- C: thank-you scope (blockified template) ---
        // The live site renders order-received via WC's BLOCKIFIED
        // order-confirmation template: every block (status, summary,
        // totals, addresses) renders independently, so the classic
        // before_thankyou/thankyou hooks fired INSIDE the status and
        // additional-information blocks and split the wrapper across
        // siblings. The fix injects the scope classes onto each block's
        // own wrapper div via the render_block filter.
        if (false !== strpos($sc_php_ds12, "add_filter('render_block', array(\$this, 'wrap_thankyou_block'), 10, 2)")
            && false !== strpos($sc_php_ds12, 'function wrap_thankyou_block')
            && false !== strpos($sc_php_ds12, "strpos(\$name, 'woocommerce/order-confirmation')")
            && false !== strpos($sc_php_ds12, 'routew-account--thankyou')
            && false !== strpos($sc_php_ds12, 'is_order_received_context()')) {
            $this->pass('DS13 render_block filter scopes every order-confirmation block');
        } else {
            $this->fail('DS13 thank-you render_block filter missing in class-routew-shortcodes.php');
        }

        // The classic-hook approach must NOT return (it split wrappers
        // on blockified templates — the bug this round fixed).
        if (false === strpos($sc_php_ds12, "add_action('woocommerce_before_thankyou', array(\$this, 'open_thankyou_scope')")
            && false === strpos($sc_php_ds12, "add_action('woocommerce_thankyou', array(\$this, 'close_thankyou_scope')")) {
            $this->pass('DS13 classic split-wrapper hooks retired');
        } else {
            $this->fail('DS13 classic thankyou hooks still present — they split the wrapper on blockified templates');
        }

        $ma_css_ds13 = file_get_contents($this->plugin_dir . '/assets/css/my-account.css');
        if (false !== strpos($ma_css_ds13, 'routew-account--thankyou')
            && false !== strpos($ma_css_ds13, 'woocommerce-thankyou-order-received')
            && false !== strpos($ma_css_ds13, 'wc-block-order-confirmation-status')
            && false !== strpos($ma_css_ds13, 'wc-block-order-confirmation-summary-list')
            && false !== strpos($ma_css_ds13, 'wc-block-order-confirmation-totals__table')
            && false !== strpos($ma_css_ds13, 'wc-block-order-confirmation-shipping-wrapper')) {
            $this->pass('DS13 thank-you hero + blockified order-confirmation styles compiled');
        } else {
            $this->fail('DS13 compiled my-account.css missing the thank-you / blockified blocks');
        }

        // Block-root components must use SAME-ELEMENT selectors (DS4b):
        // the scope classes are injected ON the block wrapper divs, so
        // `.routew-ui.routew-account--thankyou.wc-block-…` is required —
        // a descendant selector never matches (the round-8.1 regression).
        if (false !== strpos($ma_css_ds13, '.routew-ui.routew-account--thankyou.wc-block-order-confirmation-status{')
            && false !== strpos($ma_css_ds13, '.routew-ui.routew-account--thankyou.wc-block-order-confirmation-shipping-wrapper')) {
            $this->pass('DS13 block-root thank-you components use same-element scope selectors (DS4b)');
        } else {
            $this->fail('DS13 block-root thank-you selectors regressed to descendant form — will never match');
        }

        // --- D: rider assignment time + current-step start time ---
        if (false !== strpos($sc_php_ds12, 'routew-rider-card__assigned')
            && false !== strpos($sc_php_ds12, "__('Assigned %s', 'routemile-for-woocommerce')")
            && false !== strpos($sc_php_ds12, "__('Started %s', 'routemile-for-woocommerce')")) {
            $this->pass('DS13 rider card shows assignment time; current step shows start time');
        } else {
            $this->fail('DS13 assignment/start-time rendering missing in output_tracking_ui()');
        }

        // =====================================================================
        // DS14 — Agent PWA native-app flow (2026-09 round 9). The bug: every
        // order action reloaded the whole page, landing the agent back on
        // the New tab while their order moved elsewhere. The fix: actions
        // return a rich payload (fresh card + destination tab + counters +
        // signature) and the client moves the card, updates the counters,
        // switches to the destination tab and toasts — no reload. External
        // changes (heartbeat) still reload, but restore the active tab.
        // =====================================================================

        $helpers_php_ds14 = file_get_contents($this->plugin_dir . '/includes/agent-template-helpers.php');
        $agent_js_ds14 = file_get_contents($this->plugin_dir . '/assets/js/delivery-dashboard.js');

        // A. Card renderer extracted + AJAX-callable (returns a string).
        if (false !== strpos($helpers_php_ds14, "function routew_render_order_card")
            && false !== strpos($helpers_php_ds14, 'return (string) ob_get_clean();')
            && false !== strpos($helpers_php_ds14, 'data-order-id=')
            && false !== strpos($helpers_php_ds14, 'data-order-status=')
            && false !== strpos($tpl, "require_once ROUTEW_PLUGIN_DIR . 'includes/agent-template-helpers.php'")) {
            $this->pass('DS14 order-card renderer extracted to includes/agent-template-helpers.php (AJAX-callable)');
        } else {
            $this->fail('DS14 order-card extraction incomplete — template/AJAX cannot share the renderer');
        }

        // B. AJAX handlers return the rich payload.
        if (false !== strpos($agent_php_ds13, 'function build_action_response')
            && false !== strpos($agent_php_ds13, "'tab'")
            && false !== strpos($agent_php_ds13, "'card'")
            && false !== strpos($agent_php_ds13, "'counts'")
            && false !== strpos($agent_php_ds13, "'cod'")
            && false !== strpos($agent_php_ds13, "'cash'")
            && false !== strpos($agent_php_ds13, "'signature'")) {
            $this->pass('DS14 action responses carry card + tab + counts + cod + cash + signature');
        } else {
            $this->fail('DS14 build_action_response() payload incomplete in class-routew-delivery-boy-view.php');
        }

        // C. The client moves the card to its destination tab and follows it.
        if (false !== strpos($agent_js_ds14, 'destinationTab')
            && false !== strpos($agent_js_ds14, 'switchTab(destinationTab)')
            && false !== strpos($agent_js_ds14, 'refreshEmptyStates()')
            && false !== strpos($agent_js_ds14, 'pulseBadge')) {
            $this->pass('DS14 JS moves cards to destination tab + follows + live counters');
        } else {
            $this->fail('DS14 agent JS missing the in-place card-move flow');
        }

        // D. Same-tab refresh for the COD gate (cash confirm stays put).
        if (false !== strpos($agent_js_ds14, 'isCashConfirm')
            && false !== strpos($agent_js_ds14, "destinationTab === currentPanelId")) {
            $this->pass('DS14 COD cash confirmation refreshes the card in place (same tab)');
        } else {
            $this->fail('DS14 COD gate same-tab refresh missing');
        }

        // E. Heartbeat reloads restore the active tab (not home).
        if (false !== strpos($agent_js_ds14, 'fxwAgentActiveTab')
            && false !== strpos($agent_js_ds14, 'function restoreActiveTab')) {
            $this->pass('DS14 heartbeat reload restores the agent\'s active tab');
        } else {
            $this->fail('DS14 active-tab restore missing — heartbeat reloads still land on New');
        }

        // E2. Heartbeat re-reads the signature from the shell on every poll,
        // so it does NOT reload right after the agent's own action (which
        // updates data-routew-state in place — without the re-read, the
        // captured-by-closure `knownSignature` stays stale and the next
        // poll sees the server's new signature as "external" → hard reload).
        if (false !== strpos($agent_js_ds14, 'function getKnownSignature')
            && false !== strpos($agent_js_ds14, '$shell.data(\'routew-state\')')) {
            $this->pass('DS14 heartbeat re-reads signature from shell (no spurious reload after own action)');
        } else {
            $this->fail('DS14 heartbeat still uses a stale closure signature — Cash Collected will hard-reload');
        }

        // E3. Cash-collected orders are excluded from the live COD
        // "Cash to collect" total on the server side.
        if (false !== strpos($agent_php_ds13, 'is_cash_collected')
            && false !== strpos($agent_php_ds13, '_routew_cash_collected')
            && false !== strpos($agent_php_ds13, 'no longer "to collect"')) {
            $this->pass('DS14 build_dashboard_state excludes cash-collected orders from COD summary');
        } else {
            $this->fail('DS14 "Cash to collect" banner still counts orders the agent has already collected');
        }

        // E4. JS updates the "Cash to collect" + "You are holding" banners
        // in place from the AJAX response (no full reload needed).
        if (false !== strpos($agent_js_ds14, "'.routew-cod-summary'")
            && false !== strpos($agent_js_ds14, "'.routew-settle-bar--active'")
            && false !== strpos($agent_js_ds14, 'cash_to_collect')) {
            $this->pass('DS14 in-place banner refresh for COD summary + unsettled cash');
        } else {
            $this->fail('DS14 in-place banner refresh missing — agent still sees stale totals until reload');
        }

        // F. Arrival/removal animations + badge pulse compiled.
        $agent_css_ds14 = file_get_contents($this->plugin_dir . '/assets/css/delivery-dashboard.css');
        foreach (array('routew-card--arriving', 'routew-card--removing', 'routew-badge-pop', 'routew-tabbar__count--pulse') as $needle) {
            if (false === strpos($agent_css_ds14, $needle)) {
                $this->fail('DS14 compiled agent CSS missing animation: ' . $needle);
            }
        }
        $this->pass('DS14 card arrival/removal + badge-pulse animations compiled');

        // =====================================================================
        // DS15 — Admin-configurable order stage labels (2026-09 round 12).
        // The feature: store admins rename the 5 delivery stages ("Sent to
        // kitchen", "Out for delivery"…) and pick a colour + icon each;
        // the rename is display-only (slugs + `_routew_status_at_*` metas
        // untouched). Every surface (WC dropdown, agent card, tracking
        // stepper, my-account pills, dashboard) must read from the shared
        // helper, and the save path must whitelist-validate everything.
        // =====================================================================

        $stage_php_ds15 = file_get_contents($this->plugin_dir . '/includes/class-routew-stage-labels.php');
        $statuses_php_ds15 = file_get_contents($this->plugin_dir . '/includes/class-routew-order-statuses.php');
        $shortcodes_php_ds15 = file_get_contents($this->plugin_dir . '/includes/class-routew-shortcodes.php');
        $core_php_ds15 = file_get_contents($this->plugin_dir . '/includes/class-routew-core.php');
        $settings_js_ds15 = file_get_contents($this->plugin_dir . '/assets/js/admin-settings.js');
        $settings_css_ds15 = file_get_contents($this->plugin_dir . '/assets/css/admin-settings.css');

        // A. Helper exists, is loaded on all requests, and carries the
        // five renameable stages with whitelisted palettes.
        $stage_keys = array("'placed'", "'kitchen'", "'assigned'", "'picked_up'", "'delivered'");
        $missing_stages = array();
        foreach ($stage_keys as $needle) {
            if (false === strpos($stage_php_ds15, $needle)) {
                $missing_stages[] = $needle;
            }
        }
        if (empty($missing_stages)
            && false !== strpos($stage_php_ds15, 'function defaults')
            && false !== strpos($stage_php_ds15, 'function colour_palette')
            && false !== strpos($stage_php_ds15, 'function icon_palette')
            && false !== strpos($core_php_ds15, "class-routew-stage-labels.php")) {
            $this->pass('DS15 stage-labels helper: 5 stages + colour/icon palettes, loaded on all requests');
        } else {
            $this->fail('DS15 stage-labels helper incomplete (missing: ' . implode(', ', $missing_stages) . ')');
        }

        // B. Display-only contract: the sanitize output never writes the
        // status→stage mapping from the form (only label/colour/icon),
        // and reading validates against the same whitelists the save
        // path uses.
        if (false === strpos($stage_php_ds15, "'statuses' => \$clean")
            && false !== strpos($stage_php_ds15, "array_key_exists(\$colour, self::colour_palette())")
            && false !== strpos($stage_php_ds15, "array_key_exists(\$icon, self::icon_palette())")) {
            $this->pass('DS15 stage reads validate colour/icon + statuses map is never form-writable');
        } else {
            $this->fail('DS15 stage reads missing whitelist validation (or statuses map became writable)');
        }

        // C. Save path: registered through the settings extension points,
        // sanitizes via sanitize_text_field + palette whitelists, and
        // falls back to the default label when empty.
        if (false !== strpos($stage_php_ds15, "routew_settings_register_extra_fields")
            && false !== strpos($stage_php_ds15, "routew_sanitize_settings_extra")
            && false !== strpos($stage_php_ds15, 'sanitize_text_field((string) $row[\'label\'])')
            && false !== strpos($stage_php_ds15, 'sanitize_key((string) $row[\'colour\'])')
            && false !== strpos($stage_php_ds15, 'sanitize_key((string) $row[\'icon\'])')
            && false !== strpos($stage_php_ds15, "\$label = \$default['label'];")) {
            $this->pass('DS15 stage save path: Settings API extension points + full sanitization + empty→default fallback');
        } else {
            $this->fail('DS15 stage save path incomplete — check sanitization / extension-point wiring');
        }

        // D. WC status registration + dropdown read the stage labels
        // (so an admin rename shows in the admin order list + filters).
        if (false !== strpos($statuses_php_ds15, "ROUTEW_Stage_Labels::get('kitchen')")
            && false !== strpos($statuses_php_ds15, "ROUTEW_Stage_Labels::get('assigned')")
            && false !== strpos($statuses_php_ds15, "ROUTEW_Stage_Labels::get('picked_up')")
            && false === strpos($statuses_php_ds15, "_x('Assigned'")) {
            $this->pass('DS15 WC status registration + dropdown use admin-renameable stage labels');
        } else {
            $this->fail('DS15 WC status registration still hard-codes stage labels');
        }

        // E. Agent PWA card pill reads colour + label from the helper.
        if (false !== strpos($helpers_php_ds14, 'ROUTEW_Stage_Labels::status_colour($status)')
            && false !== strpos($helpers_php_ds14, 'ROUTEW_Stage_Labels::status_label($status)')
            && false === strpos($helpers_php_ds14, 'wc_get_order_status_name($status)')) {
            $this->pass('DS15 agent card pill: stage colour + label from the helper');
        } else {
            $this->fail('DS15 agent card pill not fully wired to ROUTEW_Stage_Labels');
        }

        // F. Customer tracking stepper labels + icons + status pill map
        // read the stages (rename mirrors onto the timeline).
        if (false !== strpos($shortcodes_php_ds15, "ROUTEW_Stage_Labels::stages()")
            && false !== strpos($shortcodes_php_ds15, "ROUTEW_Stage_Labels::get('placed')")
            && false !== strpos($shortcodes_php_ds15, "ROUTEW_Stage_Labels::get('kitchen')")
            && false !== strpos($shortcodes_php_ds15, "ROUTEW_Stage_Labels::get('picked_up')")
            && false !== strpos($shortcodes_php_ds15, "ROUTEW_Stage_Labels::get('delivered')")
            && false !== strpos($shortcodes_php_ds15, 'ROUTEW_Stage_Labels::icon_palette()')) {
            $this->pass('DS15 tracking stepper: labels + icons + pills from stage labels');
        } else {
            $this->fail('DS15 tracking stepper not wired to stage labels');
        }

        // G. My-account pills delegate to the helper (verified in DS6 too,
        // here for the feature suite).
        if (false !== strpos($my_account_php, 'ROUTEW_Stage_Labels::status_colour($status)')) {
            $this->pass('DS15 my-account status pills delegate to the helper');
        } else {
            $this->fail('DS15 my-account status pills not delegated');
        }

        // H. Settings UI: field names nest under routew_settings, the
        // preview pill + live-preview JS + admin CSS are in place.
        if (false !== strpos($stage_php_ds15, 'routew_settings[routew_stage_labels]')
            && false !== strpos($stage_php_ds15, 'data-routew-stage-preview')
            && false !== strpos($settings_js_ds15, 'data-routew-stage-label-input')
            && false !== strpos($settings_css_ds15, 'routew-stage-row__preview')) {
            $this->pass('DS15 settings UI: nested field names + live preview pill (PHP+JS+CSS)');
        } else {
            $this->fail('DS15 settings UI incomplete — preview pill wiring missing');
        }

        // I. PCP hygiene for the new file: ABSPATH guard + escaped output
        // on every echo/print in the renderer.
        if (0 === strpos($stage_php_ds15, "<?php\n/**\n * Admin-configurable order stage")
            && false !== strpos($stage_php_ds15, "if (!defined('ABSPATH'))")
            && false !== strpos($stage_php_ds15, "esc_attr(\$key)")
            && false !== strpos($stage_php_ds15, "esc_html(\$stage['label'])")) {
            $this->pass('DS15 stage-labels file: ABSPATH guard + escaped render output');
        } else {
            $this->fail('DS15 stage-labels file missing ABSPATH guard or escaping');
        }

        // J. Manager "Accept Order" action: a newly placed (pending)
        // order is accepted by the manager and moves to the kitchen
        // stage — the first step of the delivery workflow. Reuses the
        // nonce-verified routew_update_order_status handler (no new
        // endpoint, no new capability).
        $dash_render_ds15 = file_get_contents($this->plugin_dir . '/includes/class-routew-dashboard-render.php');
        if (false !== strpos($dash_render_ds15, "'pending' === \$order->get_status()")
            && false !== strpos($dash_render_ds15, 'value="routew-in-kitchen"')
            && false !== strpos($dash_render_ds15, "wp_nonce_field('routew_update_status')")
            && false !== strpos($dash_render_ds15, 'value="routew_update_order_status"')) {
            $this->pass('DS15 manager Accept Order action on pending orders (reuses nonce-verified status handler)');
        } else {
            $this->fail('DS15 Accept Order action missing on the deliveries dashboard');
        }

        // =====================================================================
        // DS16 — Admin-configurable brand color (2026-09 round 13).
        // The feature: ONE admin-picked hex re-derives the full design-
        // system brand ramp and is injected as an inline custom-property
        // override on the shared routew-ui handle. Root prerequisite: the
        // compiled stylesheets must CONSUME var(--rm-brand*) rather than
        // baked literals — otherwise no override can recolour anything.
        // =====================================================================

        $brand_php_ds16 = file_get_contents($this->plugin_dir . '/includes/class-routew-brand-color.php');
        $ui_css_ds16 = file_get_contents($this->plugin_dir . '/assets/css/routew-ui.css');
        $ma_css_ds16 = file_get_contents($this->plugin_dir . '/assets/css/my-account.css');
        $dd_css_ds16 = file_get_contents($this->plugin_dir . '/assets/css/delivery-dashboard.css');
        $settings_js_ds16 = file_get_contents($this->plugin_dir . '/assets/js/admin-settings.js');
        $settings_css_ds16 = file_get_contents($this->plugin_dir . '/assets/css/admin-settings.css');

        // A. Helper: settings field + sanitizer + ramp derivation +
        // contrast guardrail + inline-style output, registered through
        // the Settings API extension points.
        if (false !== strpos($brand_php_ds16, 'routew_settings_register_extra_fields')
            && false !== strpos($brand_php_ds16, 'routew_sanitize_settings_extra')
            && false !== strpos($brand_php_ds16, 'sanitize_hex_color')
            && false !== strpos($brand_php_ds16, 'function derive_ramp')
            && false !== strpos($brand_php_ds16, 'function on_brand_text')
            && false !== strpos($brand_php_ds16, 'wp_add_inline_style')) {
            $this->pass('DS16 brand-color helper: Settings API wiring + sanitizer + ramp + contrast guardrail');
        } else {
            $this->fail('DS16 brand-color helper incomplete');
        }

        // B. The compiled stylesheets actually consume the brand tokens
        // (the root fix — before this the tokens were declared but never
        // referenced, so nothing could recolour at runtime).
        $ma_brand_vars = substr_count($ma_css_ds16, 'var(--rm-brand');
        $dd_brand_vars = substr_count($dd_css_ds16, 'var(--rm-brand');
        $ui_brand_vars = substr_count($ui_css_ds16, 'var(--rm-brand');
        if ($ma_brand_vars >= 30 && $dd_brand_vars >= 15 && $ui_brand_vars >= 20) {
            $this->pass("DS16 compiled CSS consumes brand tokens (my-account {$ma_brand_vars}, agent {$dd_brand_vars}, ui {$ui_brand_vars} references)");
        } else {
            $this->fail("DS16 compiled CSS still brand-literal (my-account {$ma_brand_vars}, agent {$dd_brand_vars}, ui {$ui_brand_vars} var references)");
        }

        // C. Solid brand fills use the contrast-safe text color.
        if (substr_count($ui_css_ds16, 'var(--rm-on-brand') >= 4
            && substr_count($ma_css_ds16, 'var(--rm-on-brand') >= 6
            && substr_count($dd_css_ds16, 'var(--rm-on-brand') >= 2) {
            $this->pass('DS16 on-brand text color wired on solid brand fills (btn-brand + surface buttons + agent pickup)');
        } else {
            $this->fail('DS16 on-brand text color missing on solid brand fills');
        }

        // D. Alpha brand usage compiles to color-mix (modern browsers) —
        // the rgba($rm-brand, .N) Sass literal could not be overridden.
        if (false !== strpos($dd_css_ds16, 'color-mix(in srgb, var(--rm-brand)')
            && false !== strpos($ma_css_ds16, 'color-mix(in srgb, var(--rm-brand')) {
            $this->pass('DS16 alpha brand usage compiles to color-mix with var()');
        } else {
            $this->fail('DS16 alpha brand usage still literal rgba');
        }

        // E. The token block still declares the DEFAULT ramp (identical
        // rendering when no override is saved) + the new canvas-wash token.
        foreach (array('--rm-brand:#e85d04', '--rm-brand-strong:#c2410c', '--rm-brand-deep:#9a3412', '--rm-brand-soft:#fff1e7', '--rm-brand-line:#ffd9be', '--rm-brand-canvas-wash:#fff6ef') as $needle) {
            if (false === strpos($ui_css_ds16, $needle)) {
                $this->fail('DS16 default token ramp missing from routew-ui.css: ' . $needle);
            }
        }
        $this->pass('DS16 default token ramp intact in routew-ui.css (no-overrides = identical rendering)');

        // F. PWA: manifest + theme-color read the admin color.
        $dbv_php_ds16 = file_get_contents($this->plugin_dir . '/includes/class-routew-delivery-boy-view.php');
        $dd_tpl_ds16 = file_get_contents($this->plugin_dir . '/templates/delivery-dashboard-template.php');
        if (false !== strpos($dbv_php_ds16, 'ROUTEW_Brand_Color::pwa_color()')
            && false !== strpos($dd_tpl_ds16, 'ROUTEW_Brand_Color::pwa_color()')
            && false === strpos($dd_tpl_ds16, 'content="#E85D04"')) {
            $this->pass('DS16 PWA manifest theme_color + dashboard meta read the admin brand');
        } else {
            $this->fail('DS16 PWA colors still hard-coded');
        }

        // G. Settings UI: color input + reset + live preview (PHP + JS + CSS).
        if (false !== strpos($brand_php_ds16, "routew_settings[routew_brand_color]")
            && false !== strpos($brand_php_ds16, 'data-routew-brand-preview-button')
            && false !== strpos($settings_js_ds16, 'brandPreview')
            && false !== strpos($settings_css_ds16, 'routew-brand-color-preview')) {
            $this->pass('DS16 settings UI: color picker + reset + live preview swatches');
        } else {
            $this->fail('DS16 settings UI incomplete');
        }

        // H. PCP hygiene: ABSPATH guard + the emitted CSS contains only
        // derived hex values (never reflects raw user input).
        if (0 === strpos($brand_php_ds16, "<?php\n/**\n * Admin-configurable brand color")
            && false !== strpos($brand_php_ds16, "if (!defined('ABSPATH'))")
            && false !== strpos($brand_php_ds16, "preg_match('/^#[0-9A-F]{6}$/', \$hex)")) {
            $this->pass('DS16 brand-color file: ABSPATH guard + hex-validated output');
        } else {
            $this->fail('DS16 brand-color file missing ABSPATH guard or hex validation');
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
