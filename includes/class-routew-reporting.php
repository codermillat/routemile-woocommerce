<?php
if (!defined('ABSPATH')) {
	exit;
}
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
// Reporting: meta_key lookups against `_routew_*` are how per-order
// delivery analytics aggregate. Inherent to reporting reads.

/**
 * Manages the reporting functionality for the plugin.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Reporting
{

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		add_action('routew_dashboard_content', array($this, 'render_reports'));
	}

	/**
	 * Render the reports section on the dashboard.
	 *
	 * @since    1.0.0
	 */
	public function render_reports()
	{
		?>
		<div class="routew-reports">
			<h2><?php esc_html_e('Today\'s Report', 'routemile-for-woocommerce'); ?></h2>
			<?php
			// Site-timezone day boundary — previously a plain 'Y-m-d' string
			// was passed to date_created, which wc_get_orders interprets in
			// the site timezone already, but the boundary shifts by ±1 day
			// on UTC-offset stores near midnight (1.2.16). Use a >... <...
			// range anchored on the current wp_date site-timezone day, so
			// the report always means "the calendar day at this store".
			$today_start = function_exists('wp_date') ? wp_date('Y-m-d 00:00:00') : gmdate('Y-m-d 00:00:00');
			$today_end   = function_exists('wp_date') ? wp_date('Y-m-d 23:59:59') : gmdate('Y-m-d 23:59:59');
			$args = array(
				'limit' => 200,
				'status' => 'wc-completed',
				'date_created' => '>=' . $today_start . ' <' . $today_end,
				'meta_key' => '_routew_delivery_boy_id',
				'meta_compare' => 'EXISTS',
			);
			$orders = wc_get_orders($args);
			$total_deliveries = count($orders);
			$total_fees = 0;
			foreach ($orders as $order) {
				$total_fees += (float) $order->get_shipping_total();
			}
			?>
			<p>
				<strong><?php esc_html_e('Total Deliveries:', 'routemile-for-woocommerce'); ?></strong>
				<?php echo esc_html($total_deliveries); ?>
			</p>
			<p>
				<strong><?php esc_html_e('Total Delivery Fees:', 'routemile-for-woocommerce'); ?></strong>
				<?php echo wp_kses_post(wc_price($total_fees)); ?>
			</p>
		</div>
		<?php
	}
}

new ROUTEW_Reporting();

// phpcs:enable
