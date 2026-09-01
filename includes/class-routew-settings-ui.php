<?php
if (!defined('ABSPATH')) {
	exit;
}
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// should_load() only reads $_GET['page'] / $_GET['tab'] to decide whether
// this presentation layer should enqueue on the current admin screen.
// It performs no state change; WC's settings tab handles its own nonce
// lifecycle. Re-enabled at EOF.

/**
 * ROUTEW_Settings_Ui — presentation layer for the RouteMile settings tab.
 *
 * Turns the flat settings form into section cards with an anchor nav and
 * conditional field visibility, aimed at non-technical store owners:
 *   - Each settings section renders as a card with a one-sentence intro.
 *   - A sticky anchor nav lists the sections.
 *   - Fields that only matter for a specific choice (map provider,
 *     fee structure) carry data-attributes; a tiny script shows/hides the
 *     rows the moment the controlling select changes. No reload, no AJAX.
 *
 * Purely presentational: it changes nothing about how settings are
 * sanitized or saved — every field still posts under `routew_settings[...]`
 * exactly as before. Field rows are tagged by the OTHER classes' existing
 * markup via a shared `data-routew-depends` contract documented below.
 *
 * @since      1.4.0
 * @package    RouteMile
 * @author     MD Millat Hosen <https://millat.is-a.dev/>
 */
class ROUTEW_Settings_Ui
{

	/**
	 * Wire hooks: card rendering + assets on the WC settings page only.
	 *
	 * @since 1.4.0
	 */
	public function __construct()
	{
		// The settings tab id is `routemile-for-woocommerce`; WC fires
		// `woocommerce_settings_{tab_id}` verbatim, so the wrapper hooks
		// must match. Without the `-for-woocommerce` suffix, the wrapper
		// never opens/closes and the settings page falls back to the
		// raw WC table. (REGRESSION-FIX R1)
		add_action('woocommerce_settings_routemile-for-woocommerce', array($this, 'open_wrapper'), 1);
		add_action('woocommerce_settings_routemile-for-woocommerce', array($this, 'close_wrapper'), 99);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	/**
	 * Only load on WooCommerce → Settings → RouteMile.
	 *
	 * @since 1.4.0
	 */
	public function should_load()
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
		return ('woocommerce_page_wc-settings' === ($screen ? $screen->id : '')) || ('wc-settings' === $page && 'routemile-for-woocommerce' === $tab);
	}

	/**
	 * Open the card wrapper before the settings table prints.
	 *
	 * @since 1.4.0
	 */
	public function open_wrapper()
	{
		if (!$this->should_load()) {
			return;
		}
		echo '<div class="routew-settings-cards" id="routew-settings-root">';
	}

	/**
	 * Close the wrapper after the table.
	 *
	 * @since 1.4.0
	 */
	public function close_wrapper()
	{
		if (!$this->should_load()) {
			return;
		}
		echo '</div>';
	}

	/**
	 * Enqueue the card/conditional CSS + JS on this screen only.
	 *
	 * @since 1.4.0
	 */
	public function enqueue_assets()
	{
		if (!$this->should_load()) {
			return;
		}
		wp_enqueue_style(
			'routew-settings-ui',
			ROUTEW_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			ROUTEW_VERSION
		);
		wp_enqueue_script(
			'routew-settings-ui',
			ROUTEW_PLUGIN_URL . 'assets/js/admin-settings.js',
			array('jquery'),
			ROUTEW_VERSION,
			true
		);
	}
}

new ROUTEW_Settings_Ui();

// phpcs:enable
