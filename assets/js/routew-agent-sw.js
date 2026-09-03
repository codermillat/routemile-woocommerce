/**
 * RouteMile Agent — service worker for the delivery dashboard PWA.
 *
 * Served by `?routew_agent_sw=1` with `Service-Worker-Allowed: /` so it can
 * control `/delivery-dashboard/`. Strategy is deliberately conservative:
 *
 * - Dashboard navigations: network-first (agents must see fresh order
 *   state), falling back to the cached shell, then a friendly offline page.
 * - This plugin's static assets (CSS/JS/icons): stale-while-revalidate.
 * - Everything WooCommerce/WordPress dynamic (admin-ajax, REST, wp-admin,
 *   login, cart/checkout/account pages): never intercepted — straight to
 *   the network so authenticated flows can never serve stale HTML.
 */

/* global self, caches, clients, fetch, Response */

// Bumped v2 -> v3 alongside the Mile Zero design-system overhaul — the
// dashboard now loads the scoped routew-ui.css build instead of the raw
// Bootstrap bundle, and any cached v2 CSS would render unstyled. The
// activate handler below deletes old caches, so installed PWAs
// auto-refresh on next open.
const CACHE_VERSION = 'routew-agent-v3';
const DASHBOARD_URL = self.registration.scope;
// WP install sees the plugin as `routemile-for-woocommerce` (matches the
// post-rename plugin slug). The folder path is hardcoded here because the
// service worker runs outside any WP context; renaming the slug means
// updating this string too. (REGRESSION-FIX R2)
const PLUGIN_ASSETS = '/wp-content/plugins/routemile-for-woocommerce/assets/';

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches
			.open(CACHE_VERSION)
			.then((cache) => cache.addAll([DASHBOARD_URL]))
			.then(() => self.skipWaiting())
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches
			.keys()
			.then((keys) => Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))))
			.then(() => self.clients.claim())
	);
});

self.addEventListener('message', (event) => {
	if (event.data && event.data.type === 'SKIP_WAITING') {
		self.skipWaiting();
	}
});

const OFFLINE_HTML =
	'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' +
	'<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' +
	'<title>Offline — RouteMile Agent</title>' +
	'<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#F5F5F5;color:#1A1A1A;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}' +
	'.card{background:#fff;border-radius:12px;padding:32px 24px;max-width:320px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.08)}' +
	'h1{font-size:1.1rem;margin:0 0 8px}p{color:#6B6B6B;font-size:.9rem;line-height:1.5;margin:0}</style></head>' +
	'<body><div class="card"><h1>You are offline</h1>' +
	'<p>New orders and status updates will appear as soon as your connection returns. ' +
	'Orders you already opened may still be available.</p></div></body></html>';

function isDashboardNavigation(request, url) {
	return request.mode === 'navigate' && url.pathname.indexOf('/delivery-dashboard') === 0;
}

function neverIntercept(url) {
	return (
		url.pathname.indexOf('/wp-admin') === 0 ||
		url.pathname.indexOf('/wp-login.php') === 0 ||
		url.pathname.indexOf('/wp-json') === 0 ||
		url.pathname.indexOf('/cart') === 0 ||
		url.pathname.indexOf('/checkout') === 0 ||
		url.pathname.indexOf('/my-account') === 0 ||
		url.pathname.indexOf('admin-ajax.php') !== -1
	);
}

self.addEventListener('fetch', (event) => {
	const request = event.request;
	const url = new URL(request.url);

	if (url.origin !== self.location.origin || request.method !== 'GET' || neverIntercept(url)) {
		return; // network only
	}

	if (isDashboardNavigation(request, url)) {
		event.respondWith(
			fetch(request)
				.then((response) => {
					const copy = response.clone();
					caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
					return response;
				})
				.catch(() =>
					caches.match(request).then((cached) => cached || new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } }))
				)
		);
		return;
	}

	if (url.pathname.indexOf(PLUGIN_ASSETS) === 0) {
		event.respondWith(
			caches.match(request).then((cached) => {
				const network = fetch(request)
					.then((response) => {
						if (response.ok) {
							const copy = response.clone();
							caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
						}
						return response;
					})
					.catch(() => cached);
				return cached || network;
			})
		);
	}
});
