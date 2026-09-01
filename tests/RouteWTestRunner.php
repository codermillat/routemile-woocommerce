<?php
/**
 * Standalone Unit Test Runner for RouteMile Plugin
 * 
 * This test suite can run WITHOUT WordPress installed.
 * It validates PHP syntax, code structure, and security patterns.
 * 
 * @package RouteMile
 * @subpackage Tests
 */

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
