/**
 * RouteMile settings tab — conditional field visibility + key masking.
 *
 * Contract (emitted by the settings renderers):
 *  - A controlling select carries `data-routew-provider-select` or
 *    `data-routew-fee-mode-select`. Its value is the current "state".
 *  - Any element inside the form with `data-routew-show-when="state:values"`
 *    is shown only while the state matches one of its comma-separated
 *    values; `data-routew-hide-when` is the inverse. The affected element's
 *    closest <tr> row hides with it.
 *
 * Purely visual: hidden fields still submit their values so saving an
 * unrelated section never drops a provider key stored under another
 * provider. Sanitizing is unchanged server-side.
 */
jQuery(function ($) {
	var $root = $('.routew-settings-cards');
	if (!$root.length) {
		return;
	}

	function applyRule($els, activeValue) {
		$els.each(function () {
			var $el = $(this);
			var showWhen = String($el.data('routew-show-when') || '');
			var hideWhen = String($el.data('routew-hide-when') || '');
			var visible = true;

			if (showWhen) {
				var parts = showWhen.split(':');
				var values = (parts[1] || '').split(',').map(function (v) { return v.trim(); });
				visible = values.indexOf(activeValue) !== -1;
			}
			if (hideWhen) {
				var hparts = hideWhen.split(':');
				var hvalues = (hparts[1] || '').split(',').map(function (v) { return v.trim(); });
				if (hvalues.indexOf(activeValue) !== -1) {
					visible = false;
				}
			}

			$el.toggle(visible);
			var $row = $el.closest('tr');
			if ($row.length && $row.find('th').length) {
				$row.toggle(visible);
			}
		});
	}

	function applyAll() {
		$root.find('[data-routew-provider-select]').each(function () {
			applyRule($root.find('[data-routew-show-when^="provider:"], [data-routew-hide-when^="provider:"]'), String($(this).val()));
		});
		$root.find('[data-routew-fee-mode-select]').each(function () {
			applyRule($root.find('[data-routew-show-when^="feemode:"]'), String($(this).val()));
		});
	}

	$root.on('change', '[data-routew-provider-select], [data-routew-fee-mode-select]', applyAll);

	// API key masking toggle.
	$root.on('click', '.routew-key-toggle', function () {
		var $btn = $(this);
		var $input = $btn.closest('.routew-key-wrap').find('.routew-key-input');
		if (!$input.length) {
			return;
		}
		var showing = 'text' === $input.attr('type');
		$input.attr('type', showing ? 'password' : 'text');
		$btn.text(showing ? ($btn.data('show') || 'Show') : ($btn.data('hide') || 'Hide'));
	});

	// Weekly hours: "Open all day" and "Closed all day" are mutually
	// exclusive per row — ticking one unticks the other visually (the
	// server-side sanitize also enforces open-wins).
	$root.on('change', '.routew-hours-day input[type="checkbox"]', function () {
		var $row = $(this).closest('.routew-hours-day');
		if (!$(this).prop('checked')) {
			return;
		}
		$row.find('input[type="checkbox"]').not(this).prop('checked', false);
	});

	applyAll();
});
