<?php
/**
 * Admin-configurable order stage labels, colours and icons.
 *
 * Every delivery stage the plugin shows (Order placed, In the kitchen,
 * Rider assigned, On the way, Delivered) can be renamed and re-coloured
 * by the store admin from the RouteMile settings tab — e.g. "Sent to
 * kitchen", "Assign rider", "Picked order", "Out for delivery". This is
 * a DISPLAY-ONLY rename: the underlying WooCommerce status slugs
 * (routew-in-kitchen, routew-assigned, routew-picked-up, completed) and
 * every stored `_routew_status_at_*` meta key stay untouched, so no
 * order in flight is ever migrated and no data is lost.
 *
 * Registered via the routew_settings_register_extra_fields /
 * routew_sanitize_settings_extra extension points (same pattern as
 * ROUTEW_Pricing and ROUTEW_Store_Hours). Consumed by:
 *  - ROUTEW_Order_Statuses (WC status dropdown + admin order list)
 *  - includes/agent-template-helpers.php (agent PWA card status pill)
 *  - ROUTEW_Shortcodes (customer tracking stepper + hero)
 *  - ROUTEW_My_Account (customer dashboard status pills)
 *  - ROUTEW_Dashboard_Render (admin deliveries dashboard)
 *
 * @since      1.6.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
	exit;
}

class ROUTEW_Stage_Labels
{

	/**
	 * Register hooks.
	 *
	 * @since 1.6.0
	 */
	public function __construct()
	{
		add_action('routew_settings_register_extra_fields', array($this, 'register_fields'));
		add_filter('routew_sanitize_settings_extra', array($this, 'sanitize_stage_labels'), 10, 2);
	}

	/**
	 * The five renameable delivery stages, with their defaults.
	 *
	 * Keys are internal and stable. `status` is the WooCommerce status
	 * slug the stage renders for. Defaults match the customer-facing
	 * wording already used across the plugin, so a fresh install looks
	 * exactly like before — the admin override only changes what is
	 * displayed, never the stored data.
	 *
	 * @return array<string, array{label:string,colour:string,icon:string,statuses:array}>
	 * @since 1.6.0
	 */
	public static function defaults()
	{
		return array(
			'placed' => array(
				'label' => __('Order placed', 'routemile-for-woocommerce'),
				'colour' => 'placed',
				'icon' => 'receipt',
				'statuses' => array('pending'),
			),
			'kitchen' => array(
				'label' => __('In the kitchen', 'routemile-for-woocommerce'),
				'colour' => 'preparing',
				'icon' => 'kitchen',
				'statuses' => array('processing', 'routew-in-kitchen'),
			),
			'assigned' => array(
				'label' => __('Rider assigned', 'routemile-for-woocommerce'),
				'colour' => 'assigned',
				'icon' => 'pin',
				'statuses' => array('routew-assigned'),
			),
			'picked_up' => array(
				'label' => __('On the way', 'routemile-for-woocommerce'),
				'colour' => 'transit',
				'icon' => 'scooter',
				'statuses' => array('routew-picked-up'),
			),
			'delivered' => array(
				'label' => __('Delivered', 'routemile-for-woocommerce'),
				'colour' => 'delivered',
				'icon' => 'home',
				'statuses' => array('completed'),
			),
		);
	}

	/**
	 * Colour palette: maps a palette key to the pill variant class in
	 * the shared design system (tools/ui/scss/_components.scss).
	 *
	 * Keys double as the option values. `cancelled` is intentionally
	 * NOT offered — terminal states (cancelled / failed / refunded)
	 * always keep the red pill so they are never confused with an
	 * active delivery stage.
	 *
	 * @return array<string, string> Palette key → human label.
	 * @since 1.6.0
	 */
	public static function colour_palette()
	{
		return array(
			'placed' => __('Grey — neutral', 'routemile-for-woocommerce'),
			'preparing' => __('Orange — preparing', 'routemile-for-woocommerce'),
			'assigned' => __('Amber — assigned', 'routemile-for-woocommerce'),
			'transit' => __('Blue — in transit', 'routemile-for-woocommerce'),
			'delivered' => __('Green — delivered', 'routemile-for-woocommerce'),
		);
	}

	/**
	 * Icon palette: fixed set of hand-drawn SVG icons (same family the
	 * agent PWA and the tracking stepper already use, so every surface
	 * renders consistently).
	 *
	 * @return array<string, string> Icon key → SVG path data.
	 * @since 1.6.0
	 */
	public static function icon_palette()
	{
		return array(
			'receipt' => 'M6 2h12a1 1 0 0 1 1 1v19l-3-2-3 2-3 2-3 2V3a1 1 0 0 1 1-1Zm2 5h8V5H8v2Zm0 4h8V9H8v2Zm0 4h6v-2H8v2Z',
			'kitchen' => 'M8 2h8v2H8Zm-4 4h16l-1 3H5Zm2 5h12l1 8H5Z',
			'pin' => 'M12 2a7 7 0 0 1 7 7c0 5.2-7 13-7 13S5 14.2 5 9a7 7 0 0 1 7-7Zm0 9.6A2.6 2.6 0 1 0 12 6.4a2.6 2.6 0 0 0 0 5.2Z',
			'scooter' => 'M5 4h8v2h3l3 6h2v2h-6.2a3 3 0 0 1-5.6 0H8L6 18H4l2-6H4v-2h5.2a3 3 0 0 1 5.6 0H17l-2-4h-2V8h-3V6H7Z',
			'home' => 'M12 3.2 3 11h2.4v9h5.1v-5.4h3v5.4h5.1v-9H21L12 3.2Z',
			'clock' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 10.6 4 2.3-1 1.7-5-2.9V6h2v6.6Z',
			'box' => 'M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.3 6.3 3.2L12 10.7 5.7 7.5 12 4.3Zm-7 4.9 6 3v7.2l-6-3V9.2Zm8 10.2v-7.2l6-3v7.2l-6 3Z',
			'flag' => 'M5 3v18h2v-7h10.6l-2.2-4 2.2-4H7V3H5Z',
			'bell' => 'M12 22a2.3 2.3 0 0 0 2.3-2.3H9.7A2.3 2.3 0 0 0 12 22Zm7-5.4v-1l-1.7-1.7v-4A5.5 5.5 0 0 0 13 4.6V3.5a1 1 0 0 0-2 0v1.1a5.5 5.5 0 0 0-4.3 5.3v4L5 15.6v1h14Z',
		);
	}

	/**
	 * All five stages with the admin's saved overrides merged in.
	 *
	 * Every value is validated against its whitelist at read time too
	 * (defence in depth — the sanitizer already validates on save), and
	 * an empty label falls back to the default so a pill can never
	 * render blank.
	 *
	 * @return array<string, array{label:string,colour:string,icon:string,statuses:array}>
	 * @since 1.6.0
	 */
	public static function stages()
	{
		$defaults = self::defaults();
		$saved = get_option('routew_settings', array());
		$saved = (is_array($saved) && isset($saved['routew_stage_labels']) && is_array($saved['routew_stage_labels']))
			? $saved['routew_stage_labels']
			: array();

		$stages = array();
		foreach ($defaults as $key => $default) {
			$override = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : array();

			$label = isset($override['label']) ? sanitize_text_field((string) $override['label']) : '';
			if ('' === $label) {
				$label = $default['label'];
			}

			$colour = isset($override['colour']) ? (string) $override['colour'] : '';
			if (!array_key_exists($colour, self::colour_palette())) {
				$colour = $default['colour'];
			}

			$icon = isset($override['icon']) ? (string) $override['icon'] : '';
			if (!array_key_exists($icon, self::icon_palette())) {
				$icon = $default['icon'];
			}

			$stages[$key] = array(
				'label' => $label,
				'colour' => $colour,
				'icon' => $icon,
				'statuses' => $default['statuses'],
			);
		}

		return $stages;
	}

	/**
	 * One stage's effective label / colour / icon.
	 *
	 * @param string $key Stage key (placed|kitchen|assigned|picked_up|delivered).
	 * @return array{label:string,colour:string,icon:string}|null Null for unknown keys.
	 * @since 1.6.0
	 */
	public static function get($key)
	{
		$stages = self::stages();
		return isset($stages[$key]) ? $stages[$key] : null;
	}

	/**
	 * Map a WC status slug to its stage key.
	 *
	 * @param string $status Raw WC status slug (e.g. 'routew-picked-up').
	 * @return string|null Stage key, or null for statuses outside the
	 *                     delivery flow (on-hold, cancelled, …).
	 * @since 1.6.0
	 */
	public static function status_to_stage($status)
	{
		foreach (self::stages() as $key => $stage) {
			if (in_array($status, $stage['statuses'], true)) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Display label for a WC status: the admin's stage label when the
	 * status belongs to a renameable stage, WC's own translated name
	 * otherwise (on-hold, cancelled, third-party statuses).
	 *
	 * @param string $status Raw WC status slug.
	 * @return string
	 * @since 1.6.0
	 */
	public static function status_label($status)
	{
		$key = self::status_to_stage($status);
		if (null !== $key) {
			$stage = self::get($key);
			return $stage['label'];
		}
		return wc_get_order_status_name($status);
	}

	/**
	 * Pill colour variant for a WC status.
	 *
	 * @param string $status Raw WC status slug.
	 * @return string Variant key for routew-pill--{variant}.
	 * @since 1.6.0
	 */
	public static function status_colour($status)
	{
		if (in_array($status, array('cancelled', 'failed', 'refunded'), true)) {
			return 'cancelled';
		}
		$key = self::status_to_stage($status);
		if (null !== $key) {
			$stage = self::get($key);
			return $stage['colour'];
		}
		return 'placed';
	}

	/**
	 * Inline SVG for an icon key.
	 *
	 * @param string $icon Icon key from the palette.
	 * @return string SVG markup ('' for unknown keys — callers can
	 *                 echo it safely either way).
	 * @since 1.6.0
	 */
	public static function icon($icon)
	{
		$palette = self::icon_palette();
		if (!isset($palette[$icon])) {
			return '';
		}
		return '<svg class="routew-svg-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . esc_attr($palette[$icon]) . '"/></svg>';
	}

	/**
	 * Register the Order Stage Labels section on the RouteMile tab.
	 *
	 * @since 1.6.0
	 */
	public function register_fields()
	{
		add_settings_section(
			'routew_stage_labels_section',
			__('Order Stage Labels', 'routemile-for-woocommerce'),
			array($this, 'render_section_description'),
			'routemile-settings'
		);

		add_settings_field(
			'routew_stage_labels',
			__('Delivery Stage Names', 'routemile-for-woocommerce'),
			array($this, 'render_stage_rows'),
			'routemile-settings',
			'routew_stage_labels_section'
		);
	}

	/**
	 * Describe the stage-labels section.
	 *
	 * @since 1.6.0
	 */
	public function render_section_description()
	{
		echo '<p>' . esc_html__('Rename the delivery stages your customers and riders see — e.g. "Sent to kitchen" or "Out for delivery" — and pick a colour and icon for each. This only changes the wording on screen: your orders, their history and every recorded time stay exactly as they are.', 'routemile-for-woocommerce') . '</p>';
	}

	/**
	 * Render the five stage rows (label + colour + icon + live preview).
	 *
	 * Field names nest under the routew_settings option the same way
	 * the pricing tiers do, so the existing WooCommerce settings-tab
	 * save path (nonce + capability + sanitize_settings) carries them.
	 *
	 * @since 1.6.0
	 */
	public function render_stage_rows()
	{
		$stages = self::stages();
		$colours = self::colour_palette();
		$icons = self::icon_palette();

		echo '<div class="routew-stage-labels" data-routew-stage-labels>';
		foreach ($stages as $key => $stage) {
			?>
			<div class="routew-stage-row" data-routew-stage="<?php echo esc_attr($key); ?>">
				<div class="routew-stage-row__fields">
					<label class="routew-stage-row__label">
						<span class="screen-reader-text"><?php esc_html_e('Stage name', 'routemile-for-woocommerce'); ?></span>
						<input type="text" class="regular-text" maxlength="40"
							name="routew_settings[routew_stage_labels][<?php echo esc_attr($key); ?>][label]"
							value="<?php echo esc_attr($stage['label']); ?>"
							data-routew-stage-label-input>
					</label>
					<label class="routew-stage-row__control">
						<span><?php esc_html_e('Colour', 'routemile-for-woocommerce'); ?></span>
						<select name="routew_settings[routew_stage_labels][<?php echo esc_attr($key); ?>][colour]"
							data-routew-stage-colour-input>
							<?php foreach ($colours as $value => $name) : ?>
								<option value="<?php echo esc_attr($value); ?>" <?php selected($stage['colour'], $value); ?>><?php echo esc_html($name); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="routew-stage-row__control">
						<span><?php esc_html_e('Icon', 'routemile-for-woocommerce'); ?></span>
						<select name="routew_settings[routew_stage_labels][<?php echo esc_attr($key); ?>][icon]"
							data-routew-stage-icon-input>
							<?php foreach (array_keys($icons) as $value) : ?>
								<option value="<?php echo esc_attr($value); ?>" <?php selected($stage['icon'], $value); ?>><?php echo esc_html(ucwords(str_replace('_', ' ', $value))); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<span class="routew-pill routew-pill--<?php echo esc_attr($stage['colour']); ?> routew-stage-row__preview"
					data-routew-stage-preview><?php echo esc_html($stage['label']); ?></span>
			</div>
			<?php
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__('Tip: the preview pill shows exactly what your riders and customers will see. Leave a name empty to restore the default wording.', 'routemile-for-woocommerce') . '</p>';
	}

	/**
	 * Sanitize the stage labels (attached to routew_sanitize_settings_extra).
	 *
	 * Every key is whitelisted against the five known stages; labels are
	 * sanitize_text_field'd and fall back to the default when empty;
	 * colour and icon values are whitelisted against their palettes.
	 * The 'statuses' mapping can never be written from the form.
	 *
	 * @param array $sanitized Sanitized settings so far.
	 * @param array $input     Raw form input.
	 * @return array
	 * @since 1.6.0
	 */
	public function sanitize_stage_labels($sanitized, $input)
	{
		if (!isset($input['routew_stage_labels']) || !is_array($input['routew_stage_labels'])) {
			return $sanitized;
		}

		$raw = wp_unslash($input['routew_stage_labels']);
		$defaults = self::defaults();
		$colours = self::colour_palette();
		$icons = self::icon_palette();
		$clean = array();

		foreach ($defaults as $key => $default) {
			$row = isset($raw[$key]) && is_array($raw[$key]) ? $raw[$key] : array();

			$label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';
			if ('' === $label) {
				$label = $default['label'];
			}

			$colour = isset($row['colour']) ? sanitize_key((string) $row['colour']) : '';
			if (!array_key_exists($colour, $colours)) {
				$colour = $default['colour'];
			}

			$icon = isset($row['icon']) ? sanitize_key((string) $row['icon']) : '';
			if (!array_key_exists($icon, $icons)) {
				$icon = $default['icon'];
			}

			$clean[$key] = array(
				'label' => $label,
				'colour' => $colour,
				'icon' => $icon,
			);
		}

		$sanitized['routew_stage_labels'] = $clean;
		return $sanitized;
	}
}

new ROUTEW_Stage_Labels();
