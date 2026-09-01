<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Core
{

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies and define the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		$this->version = ROUTEW_VERSION;
		$this->plugin_name = 'routemile-for-woocommerce';

		$this->load_dependencies();
		$this->define_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies()
	{
		// Core services and configuration files
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-config.php';
		// Required explicitly (not just transitively via the mapping
		// service): the admin config warning, the blocks-checkout guard and
		// the settings UI all read the provider registry directly.
		require_once ROUTEW_PLUGIN_DIR . 'includes/services/class-routew-map-providers.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/services/class-routew-mapping-service.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/services/class-routew-rate-limiter.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/services/class-routew-session-helper.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/api/class-routew-rest-checkout-controller.php';

		// Core files - loaded on both admin and frontend
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-roles.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-order-statuses.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-notifications.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-privacy.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-store-hours.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-pricing.php';
		// Address simplification hooks the country-locale filter, which the
		// Store API also consults for admin-created and REST orders — so it
		// must load on every request, not only the frontend.
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-checkout-address.php';

		/**
		 * Frontend and AJAX (admin-ajax.php) files
		 * Ensure ROUTEW_Checkout loads during AJAX so wp_ajax hooks are registered.
		 */
		$routew_is_ajax = function_exists('wp_doing_ajax') ? wp_doing_ajax() : (defined('DOING_AJAX') && DOING_AJAX);
		if (!is_admin() || $routew_is_ajax) {
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-checkout.php';			// ROUTEW_Shortcodes owns the routew_print_receipt AJAX handler. admin-ajax
			// requests report is_admin() === true, so without this exception the
			// handler was never registered and the receipt buttons printed "0"
			// (1.2.15). Its remaining hooks (shortcodes, enqueues, reorder) are
			// no-ops on AJAX requests.
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-shortcodes.php';
			// ROUTEW_My_Account renders the dashboard widgets (welcome banner,
			// stats, recent orders, address peek) on the /my-account/ root
			// via the woocommerce_account_dashboard hook. Loaded alongside
			// the other frontend classes; its hooks are no-ops on AJAX
			// requests because the dashboard isn't a sub-endpoint URL.
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-my-account.php';
		}

		// Frontend-only files
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-agent-cash.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-delivery-boy-view.php';

		// Loaded on every request (not just is_admin()): the meta box "Print
		// Receipt" button opens a FRONTEND link (?routew_print_receipt=...), whose
		// template_redirect handler lives here. Loaded only in the admin, the
		// link landed on the homepage instead of the receipt (1.2.15). The
		// admin-only hooks in this class are no-ops on frontend requests.
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-order-admin.php';

		// Admin-only files
		if (is_admin()) {
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-settings.php';
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-settings-extra.php';
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-settings-maps.php';
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-settings-ui.php';
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-dashboard.php';
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-reporting.php';
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-admin-bar.php';

			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		}
	}

	/**
	 * Enqueue scripts for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_admin_scripts()
	{
		wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . '../assets/js/admin.js', array('jquery'), $this->version, true);

		// Localize script with nonce for security
		wp_localize_script($this->plugin_name, 'routew_admin_params', array(
			'nonce' => wp_create_nonce('routew-admin-nonce'),
			'i18n'  => array(
				'updating' => __('Updating...', 'routemile-for-woocommerce'),
				'error'    => __('Error!', 'routemile-for-woocommerce'),
			),
		));
	}

	/**
	 * Enqueue scripts for the delivery dashboard page.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_delivery_dashboard_scripts()
	{
		if (!get_query_var('is_delivery_dashboard')) {
			return;
		}

		wp_enqueue_style('routew-delivery-dashboard', plugin_dir_url(__FILE__) . '../assets/css/delivery-dashboard.css', array(), $this->version);
		wp_enqueue_script('routew-delivery-dashboard', plugin_dir_url(__FILE__) . '../assets/js/delivery-dashboard.js', array('jquery'), $this->version, true);
		wp_localize_script('routew-delivery-dashboard', 'routew_checkout_params', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('routew_print_receipt'),
			'i18n' => array(
				'new_tab' => __('New', 'routemile-for-woocommerce'),
				'in_progress_tab' => __('In Progress', 'routemile-for-woocommerce'),
				'no_orders' => __('No orders in this section.', 'routemile-for-woocommerce'),
			),
		));
		// Agent PWA config: heartbeat polling and service-worker
		// registration. Separate nonce from the print-receipt one above
		// (per-handler nonces, no central wrapper).
		wp_localize_script('routew-delivery-dashboard', 'fxwAgentDashboard', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'stateNonce' => wp_create_nonce('routew_agent_state'),
			'swUrl' => add_query_arg('routew_agent_sw', '1', home_url('/')),
			'pollIntervalMs' => 30000,
			'i18n' => array(
				'offline' => __('You are offline — orders will refresh when the connection returns', 'routemile-for-woocommerce'),
				'online' => __('Back online', 'routemile-for-woocommerce'),
				'picked_up_toast' => __('Order picked up', 'routemile-for-woocommerce'),
				'delivered_toast' => __('Order delivered. Great job!', 'routemile-for-woocommerce'),
				'settled_toast' => __('Hand-over request sent — waiting for manager approval', 'routemile-for-woocommerce'),
				'settle_confirm' => __('Send this cash hand-over to the manager for approval?', 'routemile-for-woocommerce'),
				'settle_confirm_amount' => __('Send {amount} hand-over request to the manager for approval?', 'routemile-for-woocommerce'),
			),
		));
	}

	/**
	 * Define the hooks for the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_hooks()
	{
		add_action('after_setup_theme', array($this, 'disable_admin_bar'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_delivery_dashboard_scripts'));
		add_action('admin_notices', array($this, 'warn_delivery_not_configured'));
		add_action('init', array($this, 'add_rewrite_rules'));
		add_filter('query_vars', array($this, 'add_query_vars'));
		add_action('template_redirect', array($this, 'handle_delivery_dashboard_access'));
		add_filter('template_include', array($this, 'template_include'));
		add_action('woocommerce_shipping_init', array($this, 'load_shipping_method'));
		add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));
		add_filter('woocommerce_shipping_chosen_method', array($this, 'prefer_routew_shipping'), 10, 2);

		// Register custom emails with WooCommerce
		add_filter('woocommerce_email_classes', array($this, 'register_email_classes'));

		// Register REST API routes
		add_action('rest_api_init', array($this, 'register_rest_routes'));
	}

	/**
	 * Add rewrite rules for delivery dashboard.
	 *
	 * @since    1.0.0
	 */
	public function add_rewrite_rules()
	{
		add_rewrite_rule('^delivery-dashboard/?$', 'index.php?is_delivery_dashboard=true', 'top');
	}

	/**
	 * Add custom query vars.
	 *
	 * @since    1.0.0
	 * @param    array    $vars    Existing query vars.
	 * @return   array    Modified query vars.
	 */
	public function add_query_vars($vars)
	{
		$vars[] = 'is_delivery_dashboard';
		return $vars;
	}

	/**
	 * Handle access control for delivery dashboard before any output is sent.
	 *
	 * @since    1.0.0
	 */
	public function handle_delivery_dashboard_access()
	{
		if (get_query_var('is_delivery_dashboard')) {
			if (!is_user_logged_in() || !current_user_can('routew_delivery_access')) {
				wp_safe_redirect(home_url());
				exit;
			}
		}
	}

	/**
	 * Include custom template for delivery dashboard.
	 *
	 * @since    1.0.0
	 * @param    string    $template    The template to include.
	 * @return   string    Modified template path.
	 */
	public function template_include($template)
	{
		if (get_query_var('is_delivery_dashboard')) {
			$custom_template = ROUTEW_PLUGIN_DIR . 'templates/delivery-dashboard-template.php';
			if (file_exists($custom_template)) {
				return $custom_template;
			}
		}
		return $template;
	}

	/**
	 * Load the shipping method class.
	 *
	 * @since    1.0.0
	 */
	public function load_shipping_method()
	{
		require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-shipping-method.php';
	}

	/**
	 * Add the shipping method to WooCommerce.
	 *
	 * @since    1.0.0
	 * @param    array    $methods    Existing shipping methods.
	 * @return   array    Modified shipping methods.
	 */
	public function add_shipping_method($methods)
	{
		$methods['routemile_delivery'] = 'ROUTEW_Shipping_Method';
		return $methods;
	}

	/**
	 * Prefer RouteMile shipping rate by default when available.
	 * Ensures a chosen method exists to avoid "No shipping method has been selected".
	 *
	 * @param string $chosen_method
	 * @param array  $available_methods
	 * @param int    $package_index
	 * @return string
	 */
	public function prefer_routew_shipping($chosen_method, $available_methods)
	{
		if ($chosen_method && isset($available_methods[$chosen_method])) {
			return $chosen_method;
		}
		foreach ($available_methods as $rate_id => $rate) {
			if (is_string($rate_id) && strpos($rate_id, 'routemile_delivery') === 0) {
				if (function_exists('wc_get_logger')) {
					wc_get_logger()->debug('prefer_routew_shipping: selecting ' . $rate_id, array('source' => 'routemile-for-woocommerce'));
				}
				return $rate_id;
			}
		}
		return $chosen_method;
	}


	/**
	 * Warn when delivery cannot be configured to work at all: the Google
	 * Maps API key is missing, or the restaurant has neither coordinates
	 * nor an address to derive them from. Without both, checkout offers
	 * no delivery rate and customers simply cannot order.
	 *
	 * Cheap checks only — no API calls on admin page renders.
	 *
	 * @since 1.2.6
	 */
	public function warn_delivery_not_configured()
	{
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		$options = get_option('routew_settings');
		if (!is_array($options)) {
			$options = array();
		}

		$problems = array();

		// Provider-aware since 1.3.0: only providers that actually need a
		// key can be missing one. OpenStreetMap never triggers this.
		if (class_exists('ROUTEW_Map_Providers') && !ROUTEW_Map_Providers::is_configured($options)) {
			$provider = ROUTEW_Map_Providers::active($options);
			$problems[] = sprintf(
				/* translators: %s: map provider name. */
				__('the %s API key is missing', 'routemile-for-woocommerce'),
				$provider['label']
			);
		}

		$latlng = isset($options['routew_restaurant_latlng']) ? trim((string) $options['routew_restaurant_latlng']) : '';
		$address = isset($options['routew_restaurant_address']) ? trim((string) $options['routew_restaurant_address']) : '';
		if ('' === $latlng && '' === $address) {
			$problems[] = __('the restaurant location (coordinates or address) is missing', 'routemile-for-woocommerce');
		}

		if (empty($problems)) {
			return;
		}

		$settings_url = admin_url('admin.php?page=wc-settings&tab=routemile');
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e('RouteMile:', 'routemile-for-woocommerce'); ?></strong>
				<?php
				printf(
					/* translators: 1: list of missing configuration, 2: link to settings */
					esc_html__('delivery is not configured — %1$s. Customers cannot place delivery orders until this is fixed. %2$s', 'routemile-for-woocommerce'),
					esc_html(implode(__(' and ', 'routemile-for-woocommerce'), $problems)),
					'<a href="' . esc_url($settings_url) . '">' . esc_html__('Open RouteMile settings', 'routemile-for-woocommerce') . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Disable admin bar for customers and delivery boys.
	 *
	 * @since    1.0.0
	 */
	public function disable_admin_bar()
	{
		if (!current_user_can('manage_options')) {
			$user = wp_get_current_user();
			if (in_array('delivery_boy', (array) $user->roles, true) || in_array('customer', (array) $user->roles, true)) {
				add_filter('show_admin_bar', '__return_false');
			}
		}
	}

	/**
	 * Register custom email classes with WooCommerce.
	 *
	 * @since    1.1.0
	 * @param    array    $email_classes    Existing email classes.
	 * @return   array    Modified email classes.
	 */
	public function register_email_classes($email_classes)
	{
		// Load email classes
		require_once ROUTEW_PLUGIN_DIR . 'includes/emails/class-routew-email-in-kitchen.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/emails/class-routew-email-assigned.php';
		require_once ROUTEW_PLUGIN_DIR . 'includes/emails/class-routew-email-picked-up.php';

		// Add to WooCommerce email classes
		$email_classes['ROUTEW_Email_In_Kitchen'] = new ROUTEW_Email_In_Kitchen();
		$email_classes['ROUTEW_Email_Assigned'] = new ROUTEW_Email_Assigned();
		$email_classes['ROUTEW_Email_Picked_Up'] = new ROUTEW_Email_Picked_Up();

		return $email_classes;
	}

	/**
	 * Register REST API routes.
	 *
	 * @since    1.1.0
	 */
	public function register_rest_routes()
	{
		$controller = new ROUTEW_REST_Checkout_Controller();
		$controller->register_routes();
	}
}
