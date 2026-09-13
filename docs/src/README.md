# Sources for the README images

`plates.html` is the source of `docs/header.png` and `docs/highlights.png` —
the two plates at the top of the README.

They are **rendered** rather than shipped as SVG because they use
**Rajdhani**, the typeface the ovos console uses, and an SVG referenced from a
markdown file cannot load a webfont: GitHub renders it through `<img>`, where
external resources are blocked. A PNG carries the typeface with it.

To re-render: serve this directory with the Rajdhani `woff2` files beside it
and capture each plate (`#header`, `#highlights`) at `deviceScaleFactor: 2`.
The console repository carries both the capture script
(`tools-dev/cdp-banner.js`) and the fonts (`site/fonts/rajdhani/`).
