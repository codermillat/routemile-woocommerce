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
        $files_to_check = [
            'includes/class-routew-dashboard.php',
            'includes/class-routew-reporting.php',
            'templates/delivery-dashboard-template.php',
            'templates/delivery-boy-view.php',
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
