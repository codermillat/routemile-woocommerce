<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Manages the settings page for the plugin.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Settings
{

		/** Initialize the class and set its properties. */
	public function __construct()
	{
		// Native tab under WooCommerce → Settings; shop managers can configure delivery (1.2.10).
		add_filter('woocommerce_settings_tabs_array', array($this, 'register_wc_settings_tab'), 50);
		add_action('woocommerce_settings_routemile', array($this, 'render_wc_settings_tab'));
		add_action('woocommerce_update_options_routemile', array($this, 'save_wc_settings_tab'));
		add_action('admin_init', array($this, 'register_settings'));
	}

	/** Add the RouteMile tab to WooCommerce → Settings. */
	public function register_wc_settings_tab($tabs)
	{
		$tabs['routemile-woocommerce'] = __('RouteMile', 'routemile-woocommerce');
		return $tabs;
	}

	/** Render tab content; WooCommerce provides form, nonce, save button. */
	public function render_wc_settings_tab()
	{
		echo '<table class="form-table">';
		do_settings_sections('routemile-settings');
		echo '</table>';
	}

	/** Save on WooCommerce tab save; explicit cap check + WC nonce both required (1.2.16). */
	public function save_wc_settings_tab()
	{
		if (!isset($_POST['routew_settings'])) {
			return;
		}
		if (!current_user_can('manage_woocommerce')) {
			return;
		}
		$input = (array) wc_clean(wp_unslash($_POST['routew_settings']));
		update_option('routew_settings', $this->sanitize_settings($input));
	}

	/** See method name. */
	public function register_settings()
	{
		register_setting('routew_settings_group', 'routew_settings', array(
			'sanitize_callback' => array($this, 'sanitize_settings'),
			'capability' => 'manage_woocommerce',
		));

		// General Settings Section
		add_settings_section(
			'routew_general_settings_section',
			__('General Settings', 'routemile-woocommerce'),
			null,
			'routemile-settings'
		);

		add_settings_field(
			'routew_restaurant_address',
			__('Restaurant Address', 'routemile-woocommerce'),
			array($this, 'render_text_field'),
			'routemile-settings',
			'routew_general_settings_section',
			array('id' => 'routew_restaurant_address')
		);

		add_settings_field(
			'routew_restaurant_latlng',
			__('Restaurant Coordinates (lat, lng)', 'routemile-woocommerce'),
			array($this, 'render_text_field'),
			'routemile-settings',
			'routew_general_settings_section',
			array(
				'id' => 'routew_restaurant_latlng',
				'description' => __('Optional. Exact "lat, lng" used for fee & zone checks, e.g. 40.7128, -74.0060. Overrides the address above.', 'routemile-woocommerce')
			)
		);

		add_settings_field(
			'routew_preparation_time',
			__('Default Preparation Time (minutes)', 'routemile-woocommerce'),
			array($this, 'render_prep_time_field'),
			'routemile-settings',
			'routew_general_settings_section',
			array(
				'id' => 'routew_preparation_time',
				'default' => 20,
				'description' => __('How long your kitchen needs to prepare a typical order. RouteMile adds this to travel time to show customers an estimated arrival, e.g. 20 min cooking + 15 min ride = “Arrives in ~35 minutes”. Most restaurants keep 15–30.', 'routemile-woocommerce'),
			)
		);

		// Google Maps key fields moved to the Map Provider section (1.4.0)
		// so every provider-related field lives together under the
		// provider dropdown.
		do_action('routew_settings_register_google_fields');

		// Delivery Fee Settings Section
		// Base fee + per-km rows moved to ROUTEW_Pricing (1.4.0) so the fee
		// structure choice controls their visibility in one place. The
		// section header itself is registered by ROUTEW_Pricing.
		// (The "extra cart fee" option was removed in 1.3.0: it duplicated
		// the shipping-method charge whenever a non-RouteMile rate was
		// selected, so customers paid the delivery cost twice. The Shipping
		// Method API is now the single place the charge is added.)
		// Uninstall opt-in (1.2.16).
		add_settings_field('routew_remove_on_uninstall', __('Remove Data on Uninstall', 'routemile-woocommerce'), array($this, 'render_checkbox_field'), 'routemile-settings', 'routew_general_settings_section', array('id' => 'routew_remove_on_uninstall', 'label' => __('Delete all RouteMile data (settings, saved profiles, the delivery_boy role) on uninstall. Order meta is never deleted either way.', 'routemile-woocommerce')));

		// Delivery Zone Settings Section
		add_settings_section(
			'routew_delivery_zone_settings_section',
			__('Delivery Zone Settings', 'routemile-woocommerce'),
			null,
			'routemile-settings'
		);

		add_settings_field(
			'routew_delivery_zone_radius',
			__('Delivery Radius (km)', 'routemile-woocommerce'),
			array($this, 'render_number_field'),
			'routemile-settings',
			'routew_delivery_zone_settings_section',
			array('id' => 'routew_delivery_zone_radius', 'default' => 10, 'step' => 0.1)
		);

		add_settings_field(
			'routew_auto_set_assigned_status',
			__('Auto-Set Status on Assign', 'routemile-woocommerce'),
			array($this, 'render_checkbox_field'),
			'routemile-settings',
			'routew_delivery_zone_settings_section',
			array('id' => 'routew_auto_set_assigned_status', 'label' => __('Automatically set order status to "Assigned" when a delivery boy is assigned from the order edit page', 'routemile-woocommerce'))
		);

		// Receipt Branding Settings Section
		add_settings_section(
			'routew_receipt_branding_section',
			__('Receipt Branding', 'routemile-woocommerce'),
			array($this, 'render_receipt_branding_description'),
			'routemile-settings'
		);

		add_settings_field(
			'routew_receipt_logo',
			__('Receipt Logo', 'routemile-woocommerce'),
			array($this, 'render_image_upload_field'),
			'routemile-settings',
			'routew_receipt_branding_section',
			array('id' => 'routew_receipt_logo', 'description' => __('Recommended: 200x80px, PNG or JPG', 'routemile-woocommerce'))
		);

		add_settings_field(
			'routew_receipt_restaurant_name',
			__('Restaurant Name (for Receipt)', 'routemile-woocommerce'),
			array($this, 'render_text_field'),
			'routemile-settings',
			'routew_receipt_branding_section',
			array('id' => 'routew_receipt_restaurant_name', 'description' => __('Leave empty to use site name', 'routemile-woocommerce'))
		);

		add_settings_field(
			'routew_receipt_address',
			__('Restaurant Address (for Receipt)', 'routemile-woocommerce'),
			array($this, 'render_textarea_field'),
			'routemile-settings',
			'routew_receipt_branding_section',
			array('id' => 'routew_receipt_address', 'description' => __('Full address as it appears on receipts', 'routemile-woocommerce'))
		);

		add_settings_field(
			'routew_receipt_phone',
			__('Restaurant Phone (for Receipt)', 'routemile-woocommerce'),
			array($this, 'render_text_field'),
			'routemile-settings',
			'routew_receipt_branding_section',
			array('id' => 'routew_receipt_phone')
		);

		add_settings_field(
			'routew_receipt_tagline',
			__('Receipt Tagline', 'routemile-woocommerce'),
			array($this, 'render_text_field'),
			'routemile-settings',
			'routew_receipt_branding_section',
			array('id' => 'routew_receipt_tagline', 'description' => __('Optional tagline shown below logo (e.g., "Delicious Food, Delivered Fast!")', 'routemile-woocommerce'))
		);

		add_settings_field(
			'routew_receipt_footer_message',
			__('Receipt Footer Message', 'routemile-woocommerce'),
			array($this, 'render_text_field'),
			'routemile-settings',
			'routew_receipt_branding_section',
			array('id' => 'routew_receipt_footer_message', 'default' => 'Thank You! Have a great day!')
		);

		// Sibling sections (Pricing Rules, Opening Hours) register here — do_settings_sections() snapshots sections.
		do_action('routew_settings_register_extra_fields');
	}

	/** Describe the receipt branding section. */
	public function render_receipt_branding_description()
	{
		?>
		<p class="description"><?php esc_html_e('Customize your delivery receipts with your restaurant branding. These settings are used only for printed receipts.', 'routemile-woocommerce'); ?></p>
		<?php
	}

	/** Render a generic text field. */
	public function render_text_field($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$default = isset($args['default']) ? $args['default'] : '';
		$value = isset($options[$id]) ? $options[$id] : $default;
		$description = isset($args['description']) ? $args['description'] : '';
		$dep = isset($args['show_when']) ? ' data-routew-show-when="' . esc_attr($args['show_when']) . '"' : '';
		?>
		<input type="text" name="routew_settings[<?php echo esc_attr($id); ?>]" value="<?php echo esc_attr($value); ?>"
			class="regular-text"<?php echo $dep; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute template ?>>
		<?php if ($description) : ?>
			<p class="description"><?php echo esc_html($description); ?></p>
		<?php endif;
	}

	/**
	 * Render an API-key field: password-masked with a show/hide toggle,
	 * optionally bound to a controlling choice via `show_when`.
	 *
	 * @since 1.4.0
	 */
	public function render_key_field($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$value = isset($options[$id]) ? $options[$id] : '';
		$description = isset($args['description']) ? $args['description'] : '';
		$dep = isset($args['show_when']) ? ' data-routew-show-when="' . esc_attr($args['show_when']) . '"' : '';
		?>
		<div class="routew-key-wrap"<?php echo $dep; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute template ?>>
			<input type="password" name="routew_settings[<?php echo esc_attr($id); ?>]" value="<?php echo esc_attr($value); ?>"
				class="regular-text routew-key-input" autocomplete="new-password">
			<button type="button" class="button routew-key-toggle"
				data-show="<?php esc_attr_e('Show', 'routemile-woocommerce'); ?>"
				data-hide="<?php esc_attr_e('Hide', 'routemile-woocommerce'); ?>"><?php esc_html_e('Show', 'routemile-woocommerce'); ?></button>
			<?php if ($description) : ?>
				<p class="description"><?php echo esc_html($description); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Render a textarea field. */
	public function render_textarea_field($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$value = isset($options[$id]) ? $options[$id] : '';
		$description = isset($args['description']) ? $args['description'] : '';
		?>
		<textarea name="routew_settings[<?php echo esc_attr($id); ?>]" rows="3" class="large-text"><?php echo esc_textarea($value); ?></textarea>
		<?php if ($description) : ?>
			<p class="description"><?php echo esc_html($description); ?></p>
		<?php endif;
	}

	/** Render an image-upload field. */
	public function render_image_upload_field($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$value = isset($options[$id]) ? $options[$id] : '';
		$description = isset($args['description']) ? $args['description'] : '';
		
		// WP.org: enqueue JS via wp_enqueue_script + localize i18n strings.
		// (The media-uploader JS lives at assets/js/admin-settings-media.js.)
		wp_enqueue_media();
		wp_enqueue_script(
			'routew-admin-settings-media',
			ROUTEW_PLUGIN_URL . 'assets/js/admin-settings-media.js',
			array('jquery'),
			ROUTEW_VERSION,
			true
		);
		wp_localize_script(
			'routew-admin-settings-media',
			'routewAdminMediaStrings',
			array(
				'selectTitle' => __('Select or Upload Logo', 'routemile-woocommerce'),
				'useButton'   => __('Use this logo', 'routemile-woocommerce'),
				'removeLabel' => __('Remove Logo', 'routemile-woocommerce'),
			)
		);
		?>
		<div class="routew-image-upload-wrapper">
			<input type="hidden" name="routew_settings[<?php echo esc_attr($id); ?>]" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>">
			
			<div id="<?php echo esc_attr($id); ?>_preview" style="margin-bottom: 10px;">
				<?php if ($value) : ?>
					<img src="<?php echo esc_url($value); ?>" style="max-width: 200px; max-height: 80px; display: block; margin-bottom: 5px;">
				<?php endif; ?>
			</div>
			
			<button type="button" class="button routew-upload-image-btn" data-target="<?php echo esc_attr($id); ?>">
				<?php esc_html_e('Upload Logo', 'routemile-woocommerce'); ?>
			</button>
			
			<?php if ($value) : ?>
				<button type="button" class="button routew-remove-image-btn" data-target="<?php echo esc_attr($id); ?>">
					<?php esc_html_e('Remove Logo', 'routemile-woocommerce'); ?>
				</button>
			<?php endif; ?>
			
			<?php if ($description) : ?>
				<p class="description"><?php echo esc_html($description); ?></p>
			<?php endif; ?>
		</div>
		
	<?php
	}

	/** Render a checkbox field. */
	public function render_checkbox_field($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$label = isset($args['label']) ? $args['label'] : '';
		$value = isset($options[$id]) ? $options[$id] : '';
		$checked = in_array($value, array('yes', 'true', 1, '1'), true);
		?>
		<label>
			<input type="checkbox" name="routew_settings[<?php echo esc_attr($id); ?>]" value="yes"
				<?php checked($checked); ?>>
			<?php echo esc_html($label); ?>
		</label>
		<?php
	}

	/** Render a number field. */
	public function render_number_field($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$default = isset($args['default']) ? $args['default'] : '';
		$step = isset($args['step']) ? $args['step'] : '1';
		$value = isset($options[$id]) ? $options[$id] : $default;
		?>
		<input type="number" step="<?php echo esc_attr($step); ?>" name="routew_settings[<?php echo esc_attr($id); ?>]"
			value="<?php echo esc_attr($value); ?>" class="small-text">
		<?php
	}

	/**
	 * Number field with a plain-language description.
	 *
	 * Used for settings a non-technical store owner must understand at a
	 * glance (e.g. Default Preparation Time).
	 *
	 * @since 1.4.0
	 */
	public function render_prep_time_field($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$default = isset($args['default']) ? $args['default'] : '';
		$value = isset($options[$id]) ? $options[$id] : $default;
		$description = isset($args['description']) ? $args['description'] : '';
		?>
		<input type="number" min="0" step="1" name="routew_settings[<?php echo esc_attr($id); ?>]"
			value="<?php echo esc_attr($value); ?>" class="small-text"> <?php esc_html_e('minutes', 'routemile-woocommerce'); ?>
		<?php if ($description) : ?>
			<p class="description"><?php echo esc_html($description); ?></p>
		<?php endif;
	}

	/** Sanitize settings before saving. */
	public function sanitize_settings($input)
	{
		$existing = get_option('routew_settings', array());
		if (!is_array($existing)) {
			$existing = array();
		}
		$sanitized = array();

		// General settings
		if (isset($input['routew_google_maps_api_key'])) {
			$sanitized['routew_google_maps_api_key'] = sanitize_text_field($input['routew_google_maps_api_key']);
		}

		if (isset($input['routew_google_maps_server_key'])) {
			$sanitized['routew_google_maps_server_key'] = sanitize_text_field($input['routew_google_maps_server_key']);
		}

		if (isset($input['routew_google_maps_map_id'])) {
			$sanitized['routew_google_maps_map_id'] = sanitize_text_field($input['routew_google_maps_map_id']);
		}

		if (isset($input['routew_restaurant_address'])) {
			$sanitized['routew_restaurant_address'] = sanitize_text_field($input['routew_restaurant_address']);
		}

		if (isset($input['routew_restaurant_latlng'])) {
			$raw = sanitize_text_field($input['routew_restaurant_latlng']);
			$sanitized['routew_restaurant_latlng'] = preg_match('/^-?\d{1,3}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?$/', $raw) ? $raw : '';
		}

		if (isset($input['routew_preparation_time'])) {
			$sanitized['routew_preparation_time'] = absint($input['routew_preparation_time']);
		}

		// Delivery fee settings
		if (isset($input['routew_delivery_fee_base'])) {
			$sanitized['routew_delivery_fee_base'] = floatval($input['routew_delivery_fee_base']);
		}

		if (isset($input['routew_delivery_fee_per_km'])) {
			$sanitized['routew_delivery_fee_per_km'] = floatval($input['routew_delivery_fee_per_km']);
		}

		// Delivery zone settings
		if (isset($input['routew_delivery_zone_radius'])) {
			// Fractional-km radii (1.2.16 — was clamped to int by absint).
			$radius = (float) $input['routew_delivery_zone_radius'];
			$sanitized['routew_delivery_zone_radius'] = $radius < 0 ? 0 : $radius;
		}

		if (isset($input['routew_auto_set_assigned_status'])) {
			$sanitized['routew_auto_set_assigned_status'] = 'yes';
		} else {
			$sanitized['routew_auto_set_assigned_status'] = 'no';
		}

		// routew_enable_extra_delivery_fee is intentionally NOT carried over
		// (removed in 1.3.0 — it double-charged delivery). Dropping it here
		// clears the stored value on the next save.

		// Extra settings (e.g. opening hours, uninstall opt-in) registered by other classes
		$sanitized = apply_filters('routew_sanitize_settings_extra', $sanitized, $input);

		// Receipt branding settings
		if (isset($input['routew_receipt_logo'])) {
			$sanitized['routew_receipt_logo'] = esc_url_raw($input['routew_receipt_logo']);
		}

		if (isset($input['routew_receipt_restaurant_name'])) {
			$sanitized['routew_receipt_restaurant_name'] = sanitize_text_field($input['routew_receipt_restaurant_name']);
		}

		if (isset($input['routew_receipt_address'])) {
			$sanitized['routew_receipt_address'] = sanitize_textarea_field($input['routew_receipt_address']);
		}

		if (isset($input['routew_receipt_phone'])) {
			$sanitized['routew_receipt_phone'] = sanitize_text_field($input['routew_receipt_phone']);
		}

		if (isset($input['routew_receipt_tagline'])) {
			$sanitized['routew_receipt_tagline'] = sanitize_text_field($input['routew_receipt_tagline']);
		}

		if (isset($input['routew_receipt_footer_message'])) {
			$sanitized['routew_receipt_footer_message'] = sanitize_text_field($input['routew_receipt_footer_message']);
		}

		// Preserve routew_is_open (admin-bar toggle — not in this form).
		if (isset($existing['routew_is_open']) && !isset($sanitized['routew_is_open'])) {
			$sanitized['routew_is_open'] = $existing['routew_is_open'];
		}

		return $sanitized;
	}
}

new ROUTEW_Settings();

