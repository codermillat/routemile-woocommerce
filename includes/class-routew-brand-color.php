<?php
/**
 * Admin-configurable brand color for the plugin surfaces.
 *
 * The store admin picks ONE brand hex from WooCommerce → Settings →
 * RouteMile; the full design-system ramp is derived from it in pure PHP
 * (no external libs) and injected as one inline custom-property rule on
 * the `routew-ui` stylesheet handle. Every plugin surface that already
 * consumes `var(--rm-brand*)` (my-account, my-account dashboard,
 * checkout, tracking, order-received, agent PWA) recolours instantly —
 * because the compiled stylesheets read the custom properties, not
 * literal hexes.
 *
 * Deliberately NOT full design control: typography, spacing, radii and
 * the semantic STATUS colours (delivered green, transit blue, assigned
 * amber, cancelled red, preparing orange) stay fixed — status colours
 * carry meaning and must not follow a rebrand.
 *
 * PCP-safety: the value is sanitize_hex_color()'d on save and again on
 * read; the emitted CSS contains only the whitelisted hex characters
 * of computed colours (no user text is ever reflected), and the field
 * itself rides the existing WooCommerce settings-tab nonce +
 * capability path (routew_sanitize_settings_extra extension point).
 *
 * @since      1.6.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
	exit;
}

class ROUTEW_Brand_Color
{

	/**
	 * The default Mile Zero brand (see tools/ui/scss/_tokens.scss).
	 */
	const DEFAULT_HEX = '#E85D04';

	/**
	 * Register hooks.
	 *
	 * @since 1.6.0
	 */
	public function __construct()
	{
		add_action('routew_settings_register_extra_fields', array($this, 'register_fields'));
		add_filter('routew_sanitize_settings_extra', array($this, 'sanitize_brand_color'), 10, 2);
		// Runtime override: one inline custom-property rule appended to
		// the shared routew-ui handle. wp_add_inline_style after the
		// sheet means the cascade redefines the tokens for every
		// dependent surface stylesheet.
		add_action('wp_enqueue_scripts', array($this, 'print_inline_brand'), 20);
	}

	/**
	 * The admin-selected brand hex, sanitized, or '' when the default
	 * ramp should apply.
	 *
	 * @return string Valid '#rrggbb' hex or ''.
	 * @since 1.6.0
	 */
	public static function brand_hex()
	{
		$options = get_option('routew_settings', array());
		if (!is_array($options) || !isset($options['routew_brand_color'])) {
			return '';
		}
		$hex = sanitize_hex_color((string) $options['routew_brand_color']);
		return ($hex && strtolower($hex) !== strtolower(self::DEFAULT_HEX)) ? $hex : '';
	}

	/**
	 * Register the Brand Color section on the RouteMile settings tab.
	 *
	 * @since 1.6.0
	 */
	public function register_fields()
	{
		add_settings_section(
			'routew_brand_color_section',
			__('Brand Color', 'routemile-for-woocommerce'),
			array($this, 'render_section_description'),
			'routemile-settings'
		);

		add_settings_field(
			'routew_brand_color',
			__('Delivery App Color', 'routemile-for-woocommerce'),
			array($this, 'render_color_field'),
			'routemile-settings',
			'routew_brand_color_section'
		);
	}

	/**
	 * Describe the brand-color section.
	 *
	 * @since 1.6.0
	 */
	public function render_section_description()
	{
		echo '<p>' . esc_html__('Pick your restaurant\'s brand color — buttons, links, icons and the highlights on the customer account, checkout, order tracking and rider app recolour automatically. Status colors (delivered green, on-the-way blue) keep their meaning and stay unchanged. Clear the field to return to the default RouteMile orange.', 'routemile-for-woocommerce') . '</p>';
	}

	/**
	 * Render the color field with a live swatch preview.
	 *
	 * @since 1.6.0
	 */
	public function render_color_field()
	{
		$options = get_option('routew_settings', array());
		$value = (is_array($options) && isset($options['routew_brand_color']))
			? sanitize_hex_color((string) $options['routew_brand_color'])
			: '';
		?>
		<div class="routew-brand-color-field">
			<input type="color" class="routew-brand-color-input"
				name="routew_settings[routew_brand_color]"
				value="<?php echo esc_attr($value ? $value : self::DEFAULT_HEX); ?>"
				data-default="<?php echo esc_attr(self::DEFAULT_HEX); ?>">
			<button type="button" class="button routew-brand-color-reset"
				data-reset-for="routew_brand_color"><?php esc_html_e('Use default orange', 'routemile-for-woocommerce'); ?></button>
			<span class="routew-brand-color-preview routew-brand-color-preview--button"
				data-routew-brand-preview-button><?php esc_html_e('Order again', 'routemile-for-woocommerce'); ?></span>
			<span class="routew-brand-color-preview routew-brand-color-preview--pill"
				data-routew-brand-preview-pill><?php esc_html_e('On the way', 'routemile-for-woocommerce'); ?></span>
			<p class="description"><?php esc_html_e('One color is all you set — every shade, hover and tint is derived automatically and checked for readable text contrast.', 'routemile-for-woocommerce'); ?></p>
		</div>
		<?php
	}

	/**
	 * Sanitize the brand color (attached to routew_sanitize_settings_extra).
	 *
	 * sanitize_hex_color accepts '#rgb' shorthand too; normalise it to
	 * the 6-digit form so the ramp math never sees 3-digit input. An
	 * empty value removes the override (default ramp).
	 *
	 * @param array $sanitized Sanitized settings so far.
	 * @param array $input     Raw form input.
	 * @return array
	 * @since 1.6.0
	 */
	public function sanitize_brand_color($sanitized, $input)
	{
		if (!array_key_exists('routew_brand_color', $input)) {
			return $sanitized;
		}

		$raw = isset($input['routew_brand_color']) ? wp_unslash((string) $input['routew_brand_color']) : '';
		$hex = sanitize_hex_color($raw);
		if ('' === $hex) {
			// Empty or invalid → default ramp.
			$sanitized['routew_brand_color'] = '';
			return $sanitized;
		}

		// Expand 3-digit shorthand to 6 digits.
		if (7 !== strlen($hex)) {
			$hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
		}

		$sanitized['routew_brand_color'] = strtoupper($hex);
		return $sanitized;
	}

	/**
	 * Derive the full brand ramp from one hex.
	 *
	 * Every step is pure integer color math (no deps):
	 *   brand        → the admin hex, unchanged
	 *   brand-strong → darkened ~15% (buttons, links)
	 *   brand-deep   → darkened ~32% (hover / pressed)
	 *   brand-soft   → 91% toward white (tinted chip backgrounds)
	 *   brand-line   → 78% toward white (tinted borders)
	 *   canvas       → 96% toward white (page background keeps the
	 *                  faint brand tint instead of hardcoded peach)
	 *   canvas-2     → 88% toward white (hero washes)
	 *   tint-orange  → 89% toward white (quick-action tiles)
	 *   canvas-wash  → 97% toward white (tile gradient ends)
	 *
	 * @param string $hex Valid 6-digit '#rrggbb'.
	 * @return array<string,string> Custom-property name (without --rm-
	 *                              prefix) → computed hex value.
	 * @since 1.6.0
	 */
	public static function derive_ramp($hex)
	{
		$hex = strtoupper($hex);
		if (!preg_match('/^#[0-9A-F]{6}$/', $hex)) {
			return array();
		}

		$r = hexdec(substr($hex, 1, 2));
		$g = hexdec(substr($hex, 3, 2));
		$b = hexdec(substr($hex, 5, 2));

		return array(
			'brand' => self::to_hex($r, $g, $b),
			'brand-strong' => self::shade_hex($hex, 0.85),
			'brand-deep' => self::shade_hex($hex, 0.68),
			'brand-soft' => self::mix_hex($hex, '#FFFFFF', 0.91),
			'brand-line' => self::mix_hex($hex, '#FFFFFF', 0.78),
			'canvas' => self::mix_hex($hex, '#FFFFFF', 0.96),
			'canvas-2' => self::mix_hex($hex, '#FFFFFF', 0.88),
			'tint-orange' => self::mix_hex($hex, '#FFFFFF', 0.89),
			'brand-canvas-wash' => self::mix_hex($hex, '#FFFFFF', 0.97),
		);
	}

	/**
	 * Contrast-aware text color for the strong brand shade.
	 *
	 * WCAG AA needs 4.5:1 for body text. When the derived strong shade
	 * is too light for white text (e.g. a pale-yellow brand), buttons
	 * and solid fills switch to near-black ink instead — the brand
	 * still reads, the text stays readable.
	 *
	 * @param string $strong_hex The computed --rm-brand-strong value.
	 * @return string '#FFFFFF' or the ink color '#1C1917'.
	 * @since 1.6.0
	 */
	public static function on_brand_text($strong_hex)
	{
		$r = hexdec(substr($strong_hex, 1, 2));
		$g = hexdec(substr($strong_hex, 3, 2));
		$b = hexdec(substr($strong_hex, 5, 2));

		// Relative luminance (WCAG formula, sRGB linearised).
		$lum = 0.2126 * self::linear($r) + 0.7152 * self::linear($g) + 0.0722 * self::linear($b);

		// L1 (white) = 1.0; ratio = (1.05) / (lum + 0.05).
		return ((1.05 / ($lum + 0.05)) >= 4.5) ? '#FFFFFF' : '#1C1917';
	}

	/**
	 * Emit the inline custom-property override on the routew-ui handle.
	 *
	 * Empty when no override is saved — the compiled defaults apply and
	 * zero bytes of inline CSS are printed.
	 *
	 * @since 1.6.0
	 */
	public function print_inline_brand()
	{
		$hex = self::brand_hex();
		if ('' === $hex) {
			return;
		}

		$ramp = self::derive_ramp($hex);
		if (empty($ramp)) {
			return;
		}

		$css = '.routew-ui{';
		foreach ($ramp as $name => $value) {
			$css .= '--rm-' . $name . ':' . $value . ';';
		}
		// Contrast-safe solid-fill text color (button labels on the
		// strong shade).
		$css .= '--rm-on-brand:' . self::on_brand_text($ramp['brand-strong']) . ';';
		$css .= '}';

		wp_add_inline_style('routew-ui', $css);
	}

	/**
	 * The effective brand hex for PWA manifest + theme-color meta
	 * (falls back to the default when no override is saved).
	 *
	 * @return string Uppercase '#rrggbb'.
	 * @since 1.6.0
	 */
	public static function pwa_color()
	{
		$hex = self::brand_hex();
		return $hex ? $hex : self::DEFAULT_HEX;
	}

	/**
	 * Mix a hex color toward another by a factor.
	 *
	 * @param string $hex    Base color ('#rrggbb').
	 * @param string $toward Target color ('#rrggbb').
	 * @param float  $factor 0 = base, 1 = target.
	 * @return string Mixed '#rrggbb'.
	 * @since 1.6.0
	 */
	private static function mix_hex($hex, $toward, $factor)
	{
		$factor = max(0.0, min(1.0, (float) $factor));
		$r = (int) round(hexdec(substr($hex, 1, 2)) + (hexdec(substr($toward, 1, 2)) - hexdec(substr($hex, 1, 2))) * $factor);
		$g = (int) round(hexdec(substr($hex, 3, 2)) + (hexdec(substr($toward, 3, 2)) - hexdec(substr($hex, 3, 2))) * $factor);
		$b = (int) round(hexdec(substr($hex, 5, 2)) + (hexdec(substr($toward, 5, 2)) - hexdec(substr($hex, 5, 2))) * $factor);
		return self::to_hex($r, $g, $b);
	}

	/**
	 * Shade (darken) a hex toward black by a factor.
	 *
	 * @param string $hex    Base color ('#rrggbb').
	 * @param float  $factor 0 = black, 1 = base.
	 * @return string Shaded '#rrggbb'.
	 * @since 1.6.0
	 */
	private static function shade_hex($hex, $factor)
	{
		return self::mix_hex($hex, '#000000', 1.0 - $factor);
	}

	/**
	 * sRGB channel → linearised value (WCAG luminance step).
	 *
	 * @param int $channel 0-255.
	 * @return float
	 * @since 1.6.0
	 */
	private static function linear($channel)
	{
		$c = $channel / 255.0;
		return ($c <= 0.04045) ? ($c / 12.92) : pow((($c + 0.055) / 1.055), 2.4);
	}

	/**
	 * Clamp channels to 0-255 and format as '#rrggbb'.
	 *
	 * @param int $r Red.
	 * @param int $g Green.
	 * @param int $b Blue.
	 * @return string
	 * @since 1.6.0
	 */
	private static function to_hex($r, $g, $b)
	{
		return sprintf('#%02X%02X%02X', self::clamp($r), self::clamp($g), self::clamp($b));
	}

	/**
	 * Clamp an int to 0-255.
	 *
	 * @param int $value Any int.
	 * @return int
	 * @since 1.6.0
	 */
	private static function clamp($value)
	{
		return max(0, min(255, (int) $value));
	}
}

new ROUTEW_Brand_Color();
