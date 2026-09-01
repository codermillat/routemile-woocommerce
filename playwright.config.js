// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Playwright configuration for RouteMile browser tests.
 *
 * Targets a real WordPress install rather than a mock: the bugs worth
 * catching here (map tiles not painting, hooks firing at the wrong time,
 * block-theme rendering) only appear against real WooCommerce.
 *
 * Point ROUTEW_BASE_URL at your dev site. Defaults to the Local by Flywheel
 * site used during development.
 */
const baseURL = process.env.ROUTEW_BASE_URL || 'http://routemile-woocommerce.local';

module.exports = defineConfig({
	testDir: './tests/e2e',
	// The suite mutates shared store settings (map provider, opening hours),
	// so tests must not run concurrently against one site.
	fullyParallel: false,
	workers: 1,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
	timeout: 60000,
	expect: {
		// Map tiles come from a remote provider; give them room.
		timeout: 15000,
	},
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
		// Local dev sites are plain HTTP with self-signed or absent certs.
		ignoreHTTPSErrors: true,
		// Geolocation is blocked on insecure origins, so the "Use My
		// Location" button cannot be exercised on a plain-HTTP dev site.
		// Grant + stub coordinates so that path is testable anyway.
		permissions: ['geolocation'],
		geolocation: { latitude: 28.5200, longitude: 77.5500 },
		locale: 'en-US',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
		{
			// The picker is mobile-first and tap-to-move-pin exists
			// specifically for touch; regressions there are invisible on
			// desktop.
			name: 'mobile-chrome',
			use: { ...devices['Pixel 7'] },
		},
	],
});
