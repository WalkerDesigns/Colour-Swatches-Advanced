# Changelog

## 1.0.0 - 2026-11-23

### Added
- Standalone multisite colour field with per-site selections and PHP-configured palettes.
- Global palette fallbacks, stable handles, colour metadata, gradients, defaults and random-on-save.
- Optional Hyper 2 and 3 integration for site-aware rendering, validation and saving.
- PHP regressions, dynamic-field browser tests and a GitHub Actions compatibility matrix.
- Shade and opacity dropdowns for Tailwind CSS 3, Tailwind CSS 4, Bootstrap 5.3 and a
  built-in Plugin CSS option, with per-field limits. They work out of the box with the
  site's existing CSS: Bootstrap uses its compiled `bg-*`, `text-bg-*` and
  `bg-opacity-*` classes (no shades by default), and Tailwind can use inline styles.
- A plugin settings page with a global framework, per-site frameworks, additional shades
  and opacities, automatic Plugin CSS registration and an optional, collapsed section
  for generating stylesheet files.
- Optional `php craft multisite-colour-swatches/generate`, which writes a Tailwind 3 preset, a
  Tailwind 4 theme file, Bootstrap utilities or plugin CSS for every class editors can choose.
- Twig helpers (`bg`, `text`, `border`, `textBg`, `opacityClass`, `classes()`, `style()`,
  `attrs()`, `cssColor`, `shadeColor`, `contrastColor`, `token`) and matching GraphQL fields,
  including `utility(type:)` and `style(type:)`.
- Button helpers (`button`, `buttonOutline`, `buttonLink`) for Solid, Outline and Underline buttons.
  On Bootstrap they use the existing `btn-*`, `btn-outline-*` and `btn-link` classes, and
  `style()`/`attrs()` apply the shade and opacity through Bootstrap's `--bs-btn-*` variables.
- Swatch `token`, `shades` and `theme` config keys.
- Framework compile tests using the real Tailwind, Bootstrap and Sass packages, including a
  check that every Bootstrap class exists in a normal build without generated files.

### Fixed
- Default opacity lists include 0% (transparent) where the framework provides it,
  independently of an unset opacity.
- Swatches remain clickable when Hyper inserts or replaces field markup.
- Repeated asset loading does not register duplicate click handlers.
- Hyper 2's serialized JSON now receives normalized site colours, defaults and random selections.
