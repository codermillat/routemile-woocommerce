<?php
/**
 * Scheduled opening hours.
 *
 * Enterprise expectation for a food-delivery store: the toggle stays the
 * manual master switch (close NOW regardless of schedule), and an
 * optional per-day schedule additionally closes ordering outside opening
 * hours. Registered as an extra settings section on the RouteMile tab
 * via the routew_settings_register_extra_fields /
 * routew_sanitize_settings_extra extension points, and checked through
 * ROUTEW_Checkout::is_store_open() — the single open/closed source of truth.
 *
 * @since      1.2.12
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
	exit;
}

class ROUTEW_Store_Hours
{

	/**
	 * Register settings-section extension hooks.
	 *
	 * @since 1.2.12
	 */
	public function __construct()
	{
		add_action('routew_settings_register_extra_fields', array($this, 'register_fields'));
		add_filter('routew_sanitize_settings_extra', array($this, 'sanitize_hours'), 10, 2);
	}

	/**
	 * Default schedule: every day 09:00 – 22:00.
	 *
	 * @return array
	 * @since 1.2.12
	 */
	public static function defaults()
	{
		$days = array();
		for ($i = 0; $i < 7; $i++) {
			$days[$i] = array('open' => '09:00', 'close' => '22:00', 'closed' => '', 'all_day' => '');
		}
		return $days;
	}

	/**
	 * Stored weekly hours with defaults filled in per day.
	 *
	 * Do NOT use wp_parse_args($hours, self::defaults()) here: with
	 * numeric top-level keys it drops the stored day arrays and returns
	 * the defaults, which made the settings grid ignore saved flags
	 * (Sunday's "Closed" showed unchecked) while runtime checks read
	 * different values.
	 *
	 * @return array
	 * @since 1.4.0
	 */
	public static function get_hours()
	{
		$options = get_option('routew_settings');
		$stored = isset($options['routew_hours']) && is_array($options['routew_hours']) ? $options['routew_hours'] : array();

		$hours = array();
		foreach (self::defaults() as $i => $defaults) {
			$hours[$i] = isset($stored[$i]) && is_array($stored[$i])
				? array_merge($defaults, $stored[$i]) // stored values win, defaults fill gaps
				: $defaults;
		}
		return $hours;
	}

	/**
	 * Register the Opening Hours section on the RouteMile settings tab.
	 *
	 * @since 1.2.12
	 */
	public function register_fields()
	{
		add_settings_section(
			'routew_hours_section',
			__('Opening Hours', 'routemile-woocommerce'),
			array($this, 'render_section_description'),
			'routemile-settings'
		);

		add_settings_field(
			'routew_hours_enabled',
			__('Enable Schedule', 'routemile-woocommerce'),
			array($this, 'render_enable_field'),
			'routemile-settings',
			'routew_hours_section'
		);

		add_settings_field(
			'routew_hours',
			__('Weekly Hours', 'routemile-woocommerce'),
			array($this, 'render_hours_field'),
			'routemile-settings',
			'routew_hours_section'
		);

		add_settings_field(
			'routew_hours_override',
			__('Special Occasion Override', 'routemile-woocommerce'),
			array($this, 'render_override_field'),
			'routemile-settings',
			'routew_hours_section'
		);
	}

	/** Describe the hours section. */
	public function render_section_description()
	{
		echo '<p>' . esc_html__('The admin-bar toggle closes deliveries immediately at any time; this schedule additionally pauses ordering outside your opening hours.', 'routemile-woocommerce') . '</p>';
	}

	/** Render the enable checkbox. */
	public function render_enable_field()
	{
		$options = get_option('routew_settings');
		$enabled = !empty($options['routew_hours_enabled']);
		echo '<label><input type="checkbox" name="routew_settings[routew_hours_enabled]" value="1"' . checked($enabled, true, false) . ' /> ' . esc_html__('Close ordering automatically outside the hours below', 'routemile-woocommerce') . '</label>';
	}

	/**
	 * Special-occasion override: force-open until a date/time even when
	 * the weekly schedule (or today's "Closed all day") says closed.
	 *
	 * @since 1.4.0
	 */
	public function render_override_field()
	{
		$options = get_option('routew_settings');
		$until = isset($options['routew_hours_override_until']) ? (string) $options['routew_hours_override_until'] : '';
		?>
		<div class="routew-hours-override">
			<label>
				<input type="checkbox" name="routew_settings[routew_hours_override_enabled]" value="1"
					<?php checked(!empty($options['routew_hours_override_enabled']) && '' !== $until); ?> />
				<?php esc_html_e('Stay open regardless of the weekly schedule', 'routemile-woocommerce'); ?>
			</label>
			<p class="description" style="margin:4px 0 8px;">
				<?php esc_html_e('For special occasions — a holiday the weekly schedule marks as closed, or an all-day event. Ordering stays open until the moment below (the manual Open/Closed admin-bar toggle still wins).', 'routemile-woocommerce'); ?>
			</p>
			<input type="datetime-local" name="routew_settings[routew_hours_override_until]"
				value="<?php echo esc_attr($until); ?>" />
			<p class="description">
				<?php
				if ('' !== $until) {
					$ts = strtotime($until);
					printf(
						esc_html__('Currently forcing OPEN until %s.', 'routemile-woocommerce'),
						esc_html(false === $ts ? $until : date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts))
					);
					echo ' ';
				}
				esc_html_e('Leave empty and untick to rely on the weekly schedule only.', 'routemile-woocommerce');
				?>
			</p>
		</div>
		<?php
	}

	/** Render the per-day open/close/closed grid. */
	public function render_hours_field()
	{
		$hours = self::get_hours();

		$day_names = array(
			__('Sunday', 'routemile-woocommerce'), __('Monday', 'routemile-woocommerce'), __('Tuesday', 'routemile-woocommerce'),
			__('Wednesday', 'routemile-woocommerce'), __('Thursday', 'routemile-woocommerce'), __('Friday', 'routemile-woocommerce'), __('Saturday', 'routemile-woocommerce'),
		);

		echo '<table class="routew-hours-table">';
		echo '<tr class="routew-hours-table__head"><th scope="col"></th><td><strong>' . esc_html__('Opens', 'routemile-woocommerce') . '</strong> &nbsp;–&nbsp; <strong>' . esc_html__('Closes', 'routemile-woocommerce') . '</strong></td></tr>';
		foreach ($day_names as $i => $name) {
			$day = $hours[$i]; // get_hours() already merged defaults per day.
			printf(
				'<tr>
					<th scope="row">%1$s</th>
					<td class="routew-hours-day">
						<span class="routew-hours-times"><input type="time" name="routew_settings[routew_hours][%2$d][open]" value="%3$s" /> – <input type="time" name="routew_settings[routew_hours][%2$d][close]" value="%4$s" /></span>
						<label class="routew-hours-flag"><input type="checkbox" name="routew_settings[routew_hours][%2$d][all_day]" value="1"%5$s /> %6$s</label>
						<label class="routew-hours-flag"><input type="checkbox" name="routew_settings[routew_hours][%2$d][closed]" value="1"%7$s /> %8$s</label>
					</td>
				</tr>',
				esc_html($name),
				$i,
				esc_attr($day['open']),
				esc_attr($day['close']),
				checked(!empty($day['all_day']), true, false),
				esc_html__('Open all day', 'routemile-woocommerce'),
				checked(!empty($day['closed']), true, false),
				esc_html__('Closed all day', 'routemile-woocommerce')
			);
		}
		echo '</table>';
	}

	/**
	 * Sanitize the hours settings (attached to routew_sanitize_settings_extra).
	 *
	 * @param array $sanitized Sanitized settings so far.
	 * @param array $input     Raw input.
	 * @return array
	 * @since 1.2.12
	 */
	public function sanitize_hours($sanitized, $input)
	{
		// Defensive: if the whole Opening Hours section failed to post (e.g. a
		// future regression prevents it from rendering), preserve the stored
		// values instead of silently disabling the schedule. When the section
		// is present the time inputs always post, so their presence is the
		// reliable signal (the enable checkbox alone can legitimately be
		// absent when unchecked).
		if (!isset($input['routew_hours']) || !is_array($input['routew_hours'])) {
			$existing = get_option('routew_settings', array());
			if (is_array($existing)) {
				if (isset($existing['routew_hours_enabled'])) {
					$sanitized['routew_hours_enabled'] = $existing['routew_hours_enabled'];
				}
				if (isset($existing['routew_hours'])) {
					$sanitized['routew_hours'] = $existing['routew_hours'];
				}
				foreach (array('routew_hours_override_enabled', 'routew_hours_override_until') as $key) {
					if (isset($existing[$key])) {
						$sanitized[$key] = $existing[$key];
					}
				}
			}
			return $sanitized;
		}

		$sanitized['routew_hours_enabled'] = isset($input['routew_hours_enabled']) ? 'yes' : 'no';

		if (!isset($input['routew_hours']) || !is_array($input['routew_hours'])) {
			return $sanitized;
		}

		$hours = array();
		for ($i = 0; $i < 7; $i++) {
			$raw = isset($input['routew_hours'][$i]) && is_array($input['routew_hours'][$i]) ? $input['routew_hours'][$i] : array();
			$open = isset($raw['open']) ? sanitize_text_field(wp_unslash($raw['open'])) : '09:00';
			$close = isset($raw['close']) ? sanitize_text_field(wp_unslash($raw['close'])) : '22:00';
			if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $open)) {
				$open = '09:00';
			}
			if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $close)) {
				$close = '22:00';
			}
			// "Open all day" and "Closed all day" are mutually exclusive;
			// open-all-day wins if both end up ticked.
			$all_day = isset($raw['all_day']) && !isset($raw['closed']);
			$hours[$i] = array(
				'open' => $open,
				'close' => $close,
				'all_day' => $all_day ? 'yes' : '',
				'closed' => (!$all_day && isset($raw['closed'])) ? 'yes' : '',
			);
		}
		$sanitized['routew_hours'] = $hours;

		// Special-occasion override: only kept while both the checkbox is
		// ticked AND a parseable datetime-local value was posted.
		if (isset($input['routew_hours_override_enabled']) && !empty($input['routew_hours_override_until'])) {
			$until_raw = sanitize_text_field(wp_unslash($input['routew_hours_override_until']));
			if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $until_raw) && false !== strtotime($until_raw)) {
				$sanitized['routew_hours_override_enabled'] = 'yes';
				$sanitized['routew_hours_override_until'] = $until_raw;
			} else {
				set_transient('routew_admin_notice', __('Special-occasion override date was invalid; override not saved.', 'routemile-woocommerce'), 30);
			}
		} else {
			$sanitized['routew_hours_override_enabled'] = 'no';
			$sanitized['routew_hours_override_until'] = '';
		}

		return $sanitized;
	}

	/**
	 * Is the store accepting delivery orders right now? Canonical
	 * implementation of the manual Open/Closed toggle combined with the
	 * optional schedule.
	 *
	 * This lives here rather than in ROUTEW_Checkout because ROUTEW_Core only
	 * loads the checkout classes on frontend/AJAX requests, so admin-side
	 * callers (the admin bar) could not reach it and silently fell back to
	 * "open" while the customer-facing pages said closed. ROUTEW_Store_Hours
	 * is loaded on every request. `ROUTEW_Checkout::is_store_open()` delegates
	 * here, so both names stay valid. v1.2.19.
	 *
	 * @return bool
	 * @since 1.2.19
	 */
	public static function is_store_open()
	{
		$options = get_option('routew_settings');
		$is_open = isset($options['routew_is_open']) ? $options['routew_is_open'] : true;

		if (is_bool($is_open)) {
			$manual_open = $is_open;
		} else {
			$manual_open = in_array($is_open, array('yes', 'true', '1', 1), true);
		}

		if (!$manual_open) {
			return false; // manual toggle: closed NOW
		}

		return self::is_open_now();
	}

	/**
	 * Is "now" within the configured opening hours? Returns true when the
	 * schedule is disabled. Supports overnight spans (e.g. 18:00 – 02:00).
	 *
	 * @return bool
	 * @since 1.2.12
	 */
	public static function is_open_now()
	{
		$options = get_option('routew_settings');

		// Special-occasion override wins over the weekly schedule (but NOT
		// over the manual admin-bar Open/Closed toggle — is_store_open()
		// checks that before ever calling this).
		if (!empty($options['routew_hours_override_enabled']) && !empty($options['routew_hours_override_until'])) {
			$until = strtotime((string) $options['routew_hours_override_until']);
			if (false !== $until && current_time('timestamp') < $until) {
				return true;
			}
		}

		if (empty($options['routew_hours_enabled'])) {
			return true;
		}

		$hours = self::get_hours();
		$now = current_time('timestamp');
		$day = (int) gmdate('w', $now);
		$minutes = (int) gmdate('G', $now) * 60 + (int) gmdate('i', $now);

		$today = $hours[$day];

		// Open all day: 24h window for today.
		if (!empty($today['all_day'])) {
			return true;
		}

		if (!empty($today['closed'])) {
			// Maybe we are inside yesterday's overnight span.
			return self::in_overnight_span($hours, $day - 1, $minutes);
		}

		// A day marked "Open all day" yesterday still covers the early
		// hours of today via its closing tail.
		$yesterday = isset($hours[(($day - 1 % 7) + 7) % 7]) && is_array($hours[(($day - 1 % 7) + 7) % 7]) ? $hours[(($day - 1 % 7) + 7) % 7] : array();
		if (!empty($yesterday['all_day'])) {
			// Yesterday was open all day; if it ran past midnight its tail
			// is today's first hours. Treat "all_day" as open-until-midnight
			// only, so today's own rules govern from midnight on.
			return true;
		}

		$open = self::to_minutes($today['open']);
		$close = self::to_minutes($today['close']);

		if ($open <= $close) {
			return $minutes >= $open && $minutes < $close;
		}

		// Overnight span: open until midnight, or inside yesterday's tail.
		return $minutes >= $open || self::in_overnight_span($hours, $day - 1, $minutes);
	}

	/**
	 * Is $minutes inside the closing tail of $day_index's overnight span?
	 *
	 * @param array $hours     Weekly hours.
	 * @param int   $day_index Day index (may wrap).
	 * @param int   $minutes   Minutes since midnight.
	 * @return bool
	 * @since 1.2.12
	 */
	private static function in_overnight_span($hours, $day_index, $minutes)
	{
		$day_index = (($day_index % 7) + 7) % 7;
		$day = isset($hours[$day_index]) && is_array($hours[$day_index]) ? $hours[$day_index] : null;
		if (!$day || !empty($day['closed'])) {
			return false;
		}
		$open = self::to_minutes($day['open']);
		$close = self::to_minutes($day['close']);
		// Only an overnight span (close < open) reaches past midnight.
		return $close < $open && $minutes < $close;
	}

	/**
	 * "HH:MM" to minutes since midnight.
	 *
	 * @param string $time Time string.
	 * @return int
	 * @since 1.2.12
	 */
	private static function to_minutes($time)
	{
		$parts = explode(':', (string) $time);
		$h = isset($parts[0]) ? (int) $parts[0] : 0;
		$m = isset($parts[1]) ? (int) $parts[1] : 0;
		return $h * 60 + $m;
	}

	/**
	 * Human hint for the closed notices: when the schedule says we reopen
	 * today or on the next open day. Empty string when unknown.
	 *
	 * @return string
	 * @since 1.2.12
	 */
	public static function reopen_hint()
	{
		$options = get_option('routew_settings');
		if (empty($options['routew_hours_enabled'])) {
			return '';
		}

		$hours = self::get_hours();
		$now = current_time('timestamp');
		$day = (int) gmdate('w', $now);
		$minutes = (int) gmdate('G', $now) * 60 + (int) gmdate('i', $now);

		$day_names = array(__('Sunday', 'routemile-woocommerce'), __('Monday', 'routemile-woocommerce'), __('Tuesday', 'routemile-woocommerce'), __('Wednesday', 'routemile-woocommerce'), __('Thursday', 'routemile-woocommerce'), __('Friday', 'routemile-woocommerce'), __('Saturday', 'routemile-woocommerce'));

		// Still open later today?
		$today = isset($hours[$day]) && is_array($hours[$day]) ? $hours[$day] : null;
		if ($today && empty($today['closed']) && self::to_minutes($today['open']) > $minutes) {
			return sprintf(__('We open today at %s.', 'routemile-woocommerce'), esc_html($today['open']));
		}

		// Next open day.
		for ($offset = 1; $offset <= 7; $offset++) {
			$idx = ($day + $offset) % 7;
			$candidate = isset($hours[$idx]) && is_array($hours[$idx]) ? $hours[$idx] : null;
			if ($candidate && empty($candidate['closed'])) {
				return sprintf(__('We reopen %s at %s.', 'routemile-woocommerce'), $day_names[$idx], esc_html($candidate['open']));
			}
		}
		return '';
	}
}

new ROUTEW_Store_Hours();
