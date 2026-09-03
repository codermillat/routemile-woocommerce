//
// postcss config for the scoped Bootstrap build ONLY (build.mjs pipes the
// concatenated routew-ui source through this; the surface stylesheets are
// compiled by Sass alone and never pass through here).
//
// postcss-prefix-selector rewrites every selector to `.routew-ui …` so
// Bootstrap applies exclusively inside plugin-owned markup. Root-level
// selectors (:root, html, body — Reboot's page defaults) are replaced by
// the scope class itself, turning Reboot's body typography into the
// scoped root's typography. cssnano then minifies the result.
//

module.exports = {
	plugins: [
		require('postcss-prefix-selector')({
			prefix: '.routew-ui',
			transform(prefix, selector, prefixedSelector) {
				if (selector === ':root' || selector === 'html' || selector === 'body') {
					return prefix;
				}
				return prefixedSelector;
			},
		}),
		require('cssnano')({
			preset: ['default', { calc: false }],
		}),
	],
};
