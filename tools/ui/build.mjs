#!/usr/bin/env node
/**
 * RouteMile UI build — compiles the scoped design system + surface styles.
 *
 * Usage:   node tools/ui/build.mjs
 * Setup:   cd tools/ui && npm install   (once)
 *
 * Outputs (committed to the repo — no runtime build step):
 *   assets/css/routew-ui.css           scoped Bootstrap re-skin + font + tokens
 *   assets/css/my-account.css          customer account surface
 *   assets/css/my-account-dashboard.css my-account dashboard widgets
 *   assets/css/checkout.css            checkout location picker + delivery fields
 *   assets/css/tracking.css            order tracking (stepper, rider card)
 *   assets/css/delivery-dashboard.css  agent PWA
 *
 * Note: this is a Node script (not .sh) on purpose — the WordPress Plugin
 * Check / wp.org review process rejects shell scripts inside a plugin
 * folder. The whole tools/ tree is a dev-only toolchain excluded from the
 * distribution archive via .distignore.
 */
import { spawnSync } from 'node:child_process';
import { readFileSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const cssDir = join(root, 'assets/css');

const sassArgs = [
	'--load-path=scss',
	'--load-path=node_modules',
	'--load-path=' + join(root, 'assets/css-src'),
	'--no-source-map',
];

/**
 * Run a command, inherit stdio, exit non-zero on failure.
 */
function run(cmd, args) {
	const result = spawnSync(cmd, args, { cwd: here, stdio: 'inherit' });
	if (result.error) {
		console.error('✗ ' + cmd + ' is not available: ' + result.error.message);
		process.exit(1);
	}
	if (result.status !== 0) {
		console.error('✗ ' + cmd + ' ' + args.join(' ') + ' (exit ' + result.status + ')');
		process.exit(result.status === null ? 1 : result.status);
	}
}

/**
 * Compile one Sass entry to compressed CSS.
 */
function sass(input, output) {
	run('npx', ['sass', input, output, ...sassArgs, '--style=compressed']);
}

console.log('→ routew-ui.css (scoped Bootstrap re-skin)');

const fontsCss = join(tmpdir(), 'rm-fonts.css');
const bootstrapCss = join(tmpdir(), 'rm-bootstrap.css');

sass('scss/_routew-fonts.scss', fontsCss);
sass('scss/routew-bootstrap.scss', bootstrapCss);

// Concatenate both compiled sheets, then run a single postcss pass:
// prefix-scope every rule, then minify.
const combinedCss = readFileSync(fontsCss, 'utf8') + readFileSync(bootstrapCss, 'utf8');
const postcss = spawnSync('npx', ['postcss', '--config', '.', '--no-map'], {
	cwd: here,
	input: combinedCss,
	encoding: 'utf8',
	maxBuffer: 64 * 1024 * 1024,
});
if (postcss.error || postcss.status !== 0) {
	console.error('✗ postcss pass failed');
	if (postcss.stderr) {
		console.error(postcss.stderr);
	}
	process.exit(postcss.status === null || postcss.status === undefined ? 1 : postcss.status);
}
writeFileSync(join(cssDir, 'routew-ui.css'), postcss.stdout);

console.log('→ surface stylesheets (sass compressed)');

for (const surface of ['my-account', 'my-account-dashboard', 'checkout', 'tracking', 'agent']) {
	const out = surface === 'agent' ? 'delivery-dashboard' : surface;
	sass(join(root, 'assets/css-src', surface + '.scss'), join(cssDir, out + '.css'));
}

console.log('✓ built:');
for (const file of [
	'routew-ui.css',
	'my-account.css',
	'my-account-dashboard.css',
	'checkout.css',
	'tracking.css',
	'delivery-dashboard.css',
]) {
	const size = String(statSync(join(cssDir, file)).size).padStart(8);
	console.log('  ' + size + '  assets/css/' + file);
}
