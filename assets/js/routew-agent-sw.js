/**
 * RouteMile Agent — service worker for the delivery dashboard PWA.
 *
 * Served by `?routew_agent_sw=1` with a matching `Service-Worker-Allowed`
 * dashboard-path header and registered with the same-path `{ scope }`
 * (both derived from the dashboard URL, so subdirectory installs work) —
 * it controls ONLY the agent dashboard. Strategy is deliberately conservative:
 *
 * - Dashboard navigations: network-first (agents must see fresh order
 *   state), falling back to the cached shell, then a friendly offline page.
 * - This plugin's static assets (CSS/JS/icons): stale-while-revalidate.
 * - Everything else (including WooCommerce/WordPress dynamic pages) is
 *   outside our scope by construction, so authenticated flows can never
 *   serve stale HTML.
 *
 * NOTE for Chrome's "Event handler of <event> must be added on the initial
 * evaluation" warning: EVERY addEventListener in this file is synchronous,
 * top-level and unconditional — install, activate, message,
 * notificationclick and fetch are all wired during the initial script
 * evaluation. No handler is added lazily, inside a promise, or behind a
 * condition. If that warning names THIS script URL (?routew_agent_sw=1),
 * the cause is a stale registration (e.g. an older copy cached under a
 * wider scope) — unregister it once in DevTools > Application > Service
 * Workers and reload the dashboard; the fresh install is warning-free.
 */

/* global self, caches, clients, fetch, Response */

// Bumped v3 -> v4 alongside the SW scope tightening — the worker now
// controls ONLY /delivery-dashboard/ (was: /) and every listener lives at
// the top level of the initial evaluation (see header note). The activate
// handler below deletes old caches, so PWAs installed under the old scope
// refresh cleanly on next open. (Chrome's "Event handler ... must be added
// on the initial evaluation" warning is triggered by stale wide-scope
// registrations; v4 makes the intended scope explicit.)
const CACHE_VERSION = 'routew-agent-v4';
// Dashboard shell: under the '/delivery-dashboard/' scope,
// self.registration.scope IS the dashboard URL (it always ends in '/',
// so do NOT append the path again — that would double it). (The old
// code cached `scope` too, but under the previous '/' scope that meant
// the install step cached the HOMEPAGE html as the "shell".)
const DASHBOARD_URL = self.registration.scope;
// Base path of the WP install ('/' normally, '/shop/' on subdirectory
// installs), derived from the registered scope so subdirectory sites work
// without hardcoding. Scope is <base>delivery-dashboard/.
const BASE_PATH = new URL(self.registration.scope).pathname.replace(/delivery-dashboard\/$/, '');
// WP install sees the plugin as `routemile-for-woocommerce` (matches the
// post-rename plugin slug). The slug is hardcoded here because the service
// worker runs outside any WP context; renaming the slug means updating
// this string too. (REGRESSION-FIX R2)
const PLUGIN_ASSETS = BASE_PATH + 'wp-content/plugins/routemile-for-woocommerce/assets/';

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

// Top-level notificationclick handler (initial evaluation — see header
// note). The heartbeat raises new-order alerts from the PAGE via the
// Notification API; this worker handler covers card-click focus so no
// notification handler is ever attached lazily at runtime. (No `push`
// handler: the plugin sends no Web Push yet — Phase 5 will add one
// alongside its sender, keeping this file free of speculative surface.)
self.addEventListener('notificationclick', (event) => {
	event.notification.close();
	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
			for (const client of list) {
				if ('focus' in client) {
					return client.focus();
				}
			}
			if (self.clients.openWindow) {
				return self.clients.openWindow(self.registration.scope);
			}
			return undefined;
		})
	);
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
	// Scope-relative: self.registration.scope is the dashboard URL on any
	// install (root or subdirectory), so compare the full href prefix
	// rather than a hardcoded '/delivery-dashboard' pathname.
	return request.mode === 'navigate' && url.href.indexOf(self.registration.scope) === 0;
}

function neverIntercept(url) {
	// Under the dashboard scope the worker only ever SEES dashboard
	// navigations + plugin assets, but keep this belt-and-braces
	// list so a scope-widened stale registration can never serve stale HTML
	// for a dynamic WooCommerce flow. Base-path prefixed so subdirectory
	// installs (e.g. example.com/shop/wp-admin) are covered too.
	return (
		url.pathname.indexOf(BASE_PATH + 'wp-admin') === 0 ||
		url.pathname.indexOf(BASE_PATH + 'wp-login.php') === 0 ||
		url.pathname.indexOf(BASE_PATH + 'wp-json') === 0 ||
		url.pathname.indexOf(BASE_PATH + 'cart') === 0 ||
		url.pathname.indexOf(BASE_PATH + 'checkout') === 0 ||
		url.pathname.indexOf(BASE_PATH + 'my-account') === 0 ||
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
