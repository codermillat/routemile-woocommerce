<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * ROUTEW_Settings_Maps — Map provider settings.
 *
 * Registers the Map Provider section on the RouteMile settings tab and
 * sanitises its three fields. Lives in its own sibling class because
 * ROUTEW_Settings sits exactly at the 500-LOC cap; hooks onto the same two
 * documented extension points the rest of the settings code uses
 * (`routew_settings_register_extra_fields` + `routew_sanitize_settings_extra`),
 * so ROUTEW_Settings stays the single owner of the tab.
 *
 * @since      1.3.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Settings_Maps
{

	/**
	 * Wire the section + sanitiser.
	 *
	 * @since 1.3.0
	 */
	public function __construct()
	{
		add_action('routew_settings_register_extra_fields', array($this, 'register_fields'));
		// Google's three key fields live in THIS section too (right under
		// the provider dropdown) — ROUTEW_Settings fires this hook at the point
		// where its General section used to hold them.
		add_action('routew_settings_register_google_fields', array($this, 'register_google_fields'));
		// Priority 50: after the core sanitize, before the range-check and
		// preserve-keys passes in ROUTEW_Settings_Extra (100 / 999).
		add_filter('routew_sanitize_settings_extra', array($this, 'sanitize'), 50, 2);
	}

	/**
	 * Register Google's key fields inside the Map Provider section.
	 *
	 * Shown only while "Google Maps" is the selected provider
	 * (`data-routew-show-when` contract consumed by admin-settings.js).
	 *
	 * @since 1.4.0
	 */
	public function register_google_fields()
	{
		add_settings_field(
			'routew_google_maps_api_key',
			__('Google Maps API Key', 'routemile-for-woocommerce'),
			array($this, 'render_google_key_field'),
			'routemile-settings',
			'routew_map_provider_section'
		);

		add_settings_field(
			'routew_google_maps_server_key',
			__('Google Server Key (optional)', 'routemile-for-woocommerce'),
			array($this, 'render_text_field_maps'),
			'routemile-settings',
			'routew_map_provider_section',
			array(
				'id' => 'routew_google_maps_server_key',
				'description' => __('Optional separate key for Geocoding/Distance Matrix — lets you restrict the main key to your site domain and this one to your server IP. Falls back to the main key when empty.', 'routemile-for-woocommerce'),
				'show_when' => 'provider:google',
			)
		);

		add_settings_field(
			'routew_google_maps_map_id',
			__('Google Map ID (optional)', 'routemile-for-woocommerce'),
			array($this, 'render_text_field_maps'),
			'routemile-settings',
			'routew_map_provider_section',
			array(
				'id' => 'routew_google_maps_map_id',
				'description' => __('Optional Map ID from your Cloud Console (enables Advanced Markers). Leave empty for classic markers.', 'routemile-for-woocommerce'),
				'show_when' => 'provider:google',
			)
		);
	}

	/**
	 * Google Maps API key: masked, shown only for the google provider.
	 *
	 * @since 1.4.0
	 */
	public function render_google_key_field()
	{
		$options = get_option('routew_settings');
		$value = isset($options['routew_google_maps_api_key']) ? $options['routew_google_maps_api_key'] : '';
		?>
		<div class="routew-key-wrap" data-routew-show-when="provider:google">
			<input type="password" name="routew_settings[routew_google_maps_api_key]" value="<?php echo esc_attr($value); ?>"
				class="regular-text routew-key-input" autocomplete="new-password">
			<button type="button" class="button routew-key-toggle"
				data-show="<?php esc_attr_e('Show', 'routemile-for-woocommerce'); ?>"
				data-hide="<?php esc_attr_e('Hide', 'routemile-for-woocommerce'); ?>"><?php esc_html_e('Show', 'routemile-for-woocommerce'); ?></button>
			<p class="description"><?php esc_html_e('Paste your Google Maps JavaScript API key. Only needed when Google Maps is selected above — OpenStreetMap needs no key at all.', 'routemile-for-woocommerce'); ?></p>
		</div>
		<?php
	}

	/** Text field with conditional visibility support (maps section). */
	public function render_text_field_maps($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$value = isset($options[$id]) ? $options[$id] : '';
		$description = isset($args['description']) ? $args['description'] : '';
		$dep = isset($args['show_when']) ? ' data-routew-show-when="' . esc_attr($args['show_when']) . '"' : '';
		printf(
			'<input type="text" name="routew_settings[%1$s]" value="%2$s" class="regular-text"%3$s />%4$s',
			esc_attr($id),
			esc_attr($value),
			$dep // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute template
			,
			$description ? '<p class="description">' . esc_html($description) . '</p>' : ''
		);
	}

	/**
	 * Register the Map Provider section.
	 *
	 * Field rows carry `data-routew-depends` attributes read by
	 * ROUTEW_Settings_Ui's script: a row is visible only while its provider
	 * is selected (`provider:<id>`), or hidden for providers that don't
	 * need it (`hide-for-provider:<id>,<id>`).
	 *
	 * @since 1.3.0
	 */
	public function register_fields()
	{
		add_settings_section(
			'routew_map_provider_section',
			__('Map Provider', 'routemile-for-woocommerce'),
			array($this, 'render_section_description'),
			'routemile-settings'
		);

		add_settings_field(
			'routew_map_provider',
			__('Map Provider', 'routemile-for-woocommerce'),
			array($this, 'render_provider_field'),
			'routemile-settings',
			'routew_map_provider_section',
			array('row_class' => 'routew-row-controls-provider')
		);

		add_settings_field(
			'routew_map_provider_key',
			__('Provider API Key', 'routemile-for-woocommerce'),
			array($this, 'render_key_field'),
			'routemile-settings',
			'routew_map_provider_section',
			array('row_class' => 'routew-row-shows-provider-key')
		);

		add_settings_field(
			'routew_road_distance_factor',
			__('Road Distance Factor', 'routemile-for-woocommerce'),
			array($this, 'render_factor_field'),
			'routemile-settings',
			'routew_map_provider_section',
			array('row_class' => 'routew-row-hide-has-routing')
		);

		// Per WP.org Guideline 10: "Powered by" credits must be opt-in.
		// Note: OpenStreetMap's CC BY-SA-derived attribution may be license-
		// required for some providers even when this opt-in is unchecked —
		// admin is responsible for honouring the upstream terms.
		add_settings_field(
			'routew_show_map_credit',
			__('Show Provider Credit', 'routemile-for-woocommerce'),
			array($this, 'render_show_credit_field'),
			'routemile-settings',
			'routew_map_provider_section'
		);
	}

	/**
	 * Provider-credit opt-in checkbox (Guideline 10).
	 *
	 * @since 1.5.0
	 */
	public function render_show_credit_field()
	{
		$options = get_option('routew_settings', array());
		$checked = !empty($options['routew_show_map_credit']);
		?>
		<label>
			<input type="checkbox"
				id="routew_show_map_credit"
				name="routew_settings[routew_show_map_credit]"
				value="1"
				<?php checked($checked); ?>
			/>
			<?php esc_html_e('Show map provider attribution (e.g. "Powered by Geoapify") on the customer-facing checkout map. Off by default per WP.org Guideline 10 — leave off unless the provider requires visible credit for license terms.', 'routemile-for-woocommerce'); ?>
		</label>
		<?php
	}

	/** Describe the section. */
	public function render_section_description()
	{
		echo '<p>' . esc_html__('Choose which mapping service powers the checkout location picker, address lookup and distance calculation. OpenStreetMap works with no key and no billing account; the others need a free key.', 'routemile-for-woocommerce') . '</p>';
	}

	/**
	 * Provider dropdown, with each provider's free-tier terms listed
	 * beneath so the choice can be made without leaving the page.
	 * `data-routew-provider-select` marks it as THE controlling select for
	 * the conditional rows in this section.
	 *
	 * @since 1.3.0
	 */
	public function render_provider_field()
	{
		$active = ROUTEW_Map_Providers::active_id();
		$all = ROUTEW_Map_Providers::all();

		echo '<select id="routew_map_provider" name="routew_settings[routew_map_provider]" data-routew-provider-select>';
		foreach ($all as $id => $provider) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr($id),
				selected($active, $id, false),
				esc_html($provider['label'])
			);
		}
		echo '</select>';

		echo '<ul class="routew-provider-notes" style="margin-top:8px;">';
		foreach ($all as $id => $provider) {
			$caps = array();
			if (in_array('routing', $provider['capabilities'], true)) {
				$caps[] = __('road distance', 'routemile-for-woocommerce');
			} else {
				$caps[] = __('straight-line distance only', 'routemile-for-woocommerce');
			}

			$signup = '';
			if (!empty($provider['signup_url'])) {
				$signup = sprintf(
					' <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url($provider['signup_url']),
					esc_html__('Get a key', 'routemile-for-woocommerce')
				);
			}

			printf(
				'<li class="routew-provider-note routew-provider-note--%1$s"%2$s><strong>%3$s</strong> — %4$s <em>(%5$s)</em>%6$s</li>',
				esc_attr($id),
				$active === $id ? '' : ' style="display:none;"',
				esc_html($provider['label']),
				esc_html($provider['free_tier']),
				esc_html(implode(', ', $caps)),
				$signup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url/esc_html above
			);
		}
		echo '</ul>';
	}

	/**
	 * Single key field used by every provider except Google (which keeps
	 * its three historical fields) and OpenStreetMap (which needs none).
	 * Shown only for providers whose key this is.
	 *
	 * @since 1.3.0
	 */
	public function render_key_field()
	{
		$options = get_option('routew_settings');
		$value = isset($options['routew_map_provider_key']) ? (string) $options['routew_map_provider_key'] : '';

		printf(
			'<input type="text" id="routew_map_provider_key" name="routew_settings[routew_map_provider_key]" value="%s" class="regular-text routew-key-input" autocomplete="off" data-routew-show-when="provider:maptiler,geoapify" data-routew-hide-when="provider:osm,google" />',
			esc_attr($value)
		);

		echo '<p class="description">' . esc_html__('Paste the API key from your MapTiler or Geoapify account. Not needed for OpenStreetMap; Google uses its own fields below.', 'routemile-for-woocommerce') . '</p>';
	}

	/**
	 * Road-correction factor, only meaningful for providers without
	 * routing (MapTiler/Geoapify without routing add-ons).
	 *
	 * @since 1.3.0
	 */
	public function render_factor_field()
	{
		printf(
			'<input type="number" step="0.05" min="1" max="3" id="routew_road_distance_factor" name="routew_settings[routew_road_distance_factor]" value="%s" class="small-text" data-routew-show-when="provider:maptiler,geoapify" />',
			esc_attr((string) ROUTEW_Map_Providers::road_factor())
		);

		echo '<p class="description">' . esc_html__('Only for providers that cannot measure real road distance: straight-line distance is multiplied by this to approximate a driving route. 1.3 suits most towns and cities.', 'routemile-for-woocommerce') . '</p>';
	}

	/**
	 * Sanitise the three fields.
	 *
	 * @param array $sanitized Sanitized settings so far.
	 * @param array $input     Raw input.
	 * @return array
	 * @since 1.3.0
	 */
	public function sanitize($sanitized, $input)
	{
		if (!is_array($sanitized)) {
			$sanitized = array();
		}

		if (isset($input['routew_map_provider'])) {
			$candidate = sanitize_key(wp_unslash($input['routew_map_provider']));
			$all = ROUTEW_Map_Providers::all();
			if (isset($all[$candidate])) {
				$sanitized['routew_map_provider'] = $candidate;
			} else {
				set_transient('routew_admin_notice', __('Unknown map provider submitted; previous provider kept.', 'routemile-for-woocommerce'), 30);
			}
		}

		if (isset($input['routew_map_provider_key'])) {
			$sanitized['routew_map_provider_key'] = sanitize_text_field(wp_unslash($input['routew_map_provider_key']));
		}

		if (isset($input['routew_road_distance_factor'])) {
			$factor = (float) wp_unslash($input['routew_road_distance_factor']);
			if ($factor >= 1.0 && $factor <= 3.0) {
				$sanitized['routew_road_distance_factor'] = $factor;
			} else {
				set_transient('routew_admin_notice', __('Road Distance Factor must be between 1.0 and 3.0; previous value kept.', 'routemile-for-woocommerce'), 30);
			}
		}

		// Provider-credit opt-in (Guideline 10): '1' when the checkbox is
		// posted, '0' when it is absent (unchecked). Storing the explicit
		// '0' on uncheck is what makes unticking persist — without it, the
		// option key is never written and the box reads unchecked forever.
		$sanitized['routew_show_map_credit'] = isset($input['routew_show_map_credit']) ? 1 : 0;

		return $sanitized;
	}
}

new ROUTEW_Settings_Maps();
