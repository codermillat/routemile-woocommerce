# Design source files

Original artwork for the WordPress.org Plugin Directory assets.
The processed, spec-exact versions that actually ship live in
[`.wordpress-org/`](../.wordpress-org/) and are pushed to the
[SVN `assets/` directory](https://plugins.svn.wordpress.org/routemile-for-woocommerce/assets/).

| File | What it is | Processed into |
|---|---|---|
| `logo.png` | Logo master, 1254×1254 square, rounded corners with alpha | `.wordpress-org/icon-256x256.png` (256×256) |
| `banner.png` | Banner master, 2172×724 (3.0:1) | `.wordpress-org/banner-772x250.png` and `.wordpress-org/banner-1544x500.png` (center-cropped to 3.088:1, then resized) |
| `screenshot-4-settings-fullpage.png` | Full-page capture of the WooCommerce → Settings → RouteMile screen (1185×5260) | source for `screenshot-4.png` |
| `screenshot-4-settings-viewport.png` | Viewport capture (1200×1000) — the one actually shipped as `.wordpress-org/screenshot-4.png` | `.wordpress-org/screenshot-4.png` |

## Regenerating the processed assets

Requires ImageMagick (`magick`):

```bash
# Icon: 256×256, keep alpha (transparent corners render as a rounded square)
magick design-assets/logo.png -resize 256x256 .wordpress-org/icon-256x256.png

# Banner: center-crop 2172×703 for the exact 3.088:1 ratio, then resize
magick design-assets/banner.png -gravity center -crop 2172x703+0+0 +repage -resize 772x250  .wordpress-org/banner-772x250.png
magick design-assets/banner.png -gravity center -crop 2172x703+0+0 +repage -resize 1544x500 .wordpress-org/banner-1544x500.png
```

After committing new assets to SVN `assets/`, the
[plugin page](https://wordpress.org/plugins/routemile-for-woocommerce/)
updates within a few minutes.
