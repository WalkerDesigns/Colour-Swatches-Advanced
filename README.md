# Multisite Colour Swatches

A standalone Walker Designs plugin for Craft CMS 5 and PHP 8.2-8.x. Combines the
Colour Swatches field/value conventions from [Craft Pulse](https://github.com/craftpulse/craft-colour-swatches)
with the Brand Colour Swatches module's site-specific palettes. No dependency
on the original plugin or your custom module is required.

Use a current, patched Craft 5 release. The minimum version constraint describes
API compatibility, not a recommendation to run historical, unpatched Craft releases.

The original plugin, fields, configuration and saved content are not modified.

## Installation

After publication to the Craft Plugin Store or Packagist, install from your
Craft project directory:

```shell
composer require walker-designs/craft-multisite-colour-swatches
php craft plugin/install multisite-colour-swatches
```

This package is not published yet. Until then, use the local development setup below.

### Local development

Keep this package at `craft/plugins/multisite-colour-swatches`. From the Craft
project directory (the directory containing its `composer.json`), run:

```shell
composer config repositories.multisite-colour-swatches path plugins/multisite-colour-swatches
composer require walker-designs/craft-multisite-colour-swatches:^1.0
php craft plugin/install multisite-colour-swatches
```

Copy this package's [config.php](config.php) to
`config/multisite-colour-swatches.php`. The example contains fictional `siteA`
and `siteB` palettes. Replace these with the handles in Settings > Sites.
Sites without overrides use the global palette. Your application's existing
configuration is separate and is not changed by package updates.

Create a **Multisite Colour Swatches** field, leave **Use PHP configuration**
enabled, and select `brand` (or another named palette). Add it to an entry layout.
Edit that entry on each site: the options change with the element's site, and
each site saves its own selection. The field only supports Craft's per-site
translation mode. Craft may copy initial content when first propagating an
element; subsequent selections are independent.

This package is not automatically installed by being placed in this directory.
Existing Colour Swatches fields continue using the original plugin. Do not
uninstall that plugin while those fields still exist.

## Configuration

```php
return [
    'palettes' => [
        'brand' => [
            [
                'handle' => 'primary',
                'label' => 'Primary',
                'default' => false,
                'color' => [[
                    'color' => '#ef730b',
                    'background' => 'bg-primary',
                    'text' => 'text-primary',
                ]],
            ],
        ],
    ],
    'sites' => [
        'siteA' => [
            'palettes' => [
                'brand' => [
                    [
                        'handle' => 'primary',
                        'label' => 'Primary',
                        'color' => [['color' => '#1c394d', 'background' => 'bg-primary']],
                    ],
                ],
            ],
        ],
    ],
];
```

- Resolution: `sites[SITE_HANDLE].palettes[PALETTE]`, then global `palettes[PALETTE]`.
- Each site override **replaces the whole palette**, not individual array indexes.
  An explicitly empty array disables a palette on that site without falling back.
- Common colours can be combined with site colours using `array_replace()`, as
  in the example. Avoid `array_merge_recursive()` for hex maps: duplicate keys
  turn colour strings into arrays.
- Your legacy `'*' => ['palettes' => ...], 'sites' => ...` structure is also supported.
  Copy it into the new config filename; the original config is never read or changed.
  `*` means global defaults here, **not** Craft multi-environment configuration.
  Use environment variables inside this PHP file for environment-specific choices.
- Multiple palettes and palettes defined only on a particular site appear in
  field settings. A missing palette displays a warning; it never falls back to
  an unrelated palette.
- The legacy `colors` list is supported globally and per site; choose **Unnamed
  colours (colors)** in field settings.
- Use explicit, unique handles to keep selections stable when renaming labels.
  Without one, a handle is generated from the label, like upstream 5.2.
- Colours accept hex values or CSS colour names. Use a colour array for gradients
  and extra metadata, or a comma-separated hex string for simple fields.
- A palette may have at most one default. Empty values use that site's default.
  Clearing a field with a default therefore reapplies the default. Random-on-save
  applies only when there is no value/default and the palette is nonempty.
- Palette edits update the metadata returned by Twig and saved on the next save.
  Removed selections retain their saved snapshot for display, but show a warning
  and must be cleared or reselected before a normal validated save. They are never
  silently replaced with a different colour.
- Matrix entries use their own site. Hyper is optional: older versions get
  owner-site context on element population, rendering, validation and saving;
  newer versions resolve the link owner's site directly. The compatibility hooks
  only update links containing this plugin's field. The containing Matrix/Hyper
  field's own propagation/translation settings must also allow per-site content.
  For Hyper links, set the containing Hyper field's Translation Method to
  **Translate for each site**. With **Not translatable**, the whole link value
  (including nested swatches) is shared, so saving one site's colour can leave
  another site with an unavailable selection. After changing this setting,
  reselect any unavailable colours on each site and save.
- Swatch clicks use delegated handlers, so Hyper's dynamically rendered fields
  remain selectable without waiting for per-field initialization scripts.
- Disabling PHP configuration uses field-defined colours shared by all sites,
  while the selected value remains independent per site.

## Templates

Existing value conventions are preserved, including custom colour metadata:

```twig
{% set swatch = entry.brandColour %}
{% if swatch %}
    {{ swatch }}
    {{ swatch.handle }}
    {{ swatch.label }}
    {{ swatch.class }}
    {% for color in swatch.color %}
        <div class="{{ color.background ?? '' }}" style="color: {{ color.color }}">
            {{ swatch.label }}
        </div>
    {% endfor %}
{% endif %}
```

`color` remains a string for simple string-based palettes, or an array for
metadata/gradient palettes. `colors()`, `labels()`, `default()`, `class()` and
`collection()` are available. Legacy label-only JSON and the old module's
double-encoded values can be read; this is format compatibility, not an automatic
field migration.

GraphQL exposes `handle`, `label`, `class`, `default` and `color`. As in upstream,
`color` is a list of strings; metadata objects are JSON-encoded strings. It also
exposes `shade`, `opacity`, `framework`, `token`, `shadeColor`, `cssColor`,
`contrastColor` and `utility(type: "bg")` (`bg`, `text`, `border`, `textBg`, `opacity`,
`button`, `buttonOutline` or `buttonLink`).

## Shades, opacity and CSS frameworks

After choosing a colour, editors can choose a **Shade** and an **Opacity** from
dropdowns. The choices follow the CSS framework of the element's site, so one
field can offer Tailwind shades on one site and Bootstrap opacities on another.

**It works out of the box with the CSS your site already builds.** There is no
build step and nothing to import:

- **Bootstrap 5.3**: `swatch.bg`, `swatch.textBg` and so on use the `bg-*`,
  `text-bg-*` and `bg-opacity-*` classes Bootstrap already creates for every
  colour in your `$theme-colors`. Bootstrap has no shade classes for theme
  colours, so editors only see the Opacity dropdown.
- **Tailwind CSS 3 / 4**: Tailwind removes classes it does not find in your
  templates, so use `swatch.attrs('bg')` or `swatch.style('bg')`. They add the
  colour as an inline style, so shades and opacity always render.
- **Plugin CSS**: the plugin adds the CSS for each class to the page.

Generating stylesheet files is optional. Use it only if you want framework
classes for shades Bootstrap does not have, or a Tailwind safelist.

### Plugin settings

Go to **Settings → Plugins → Multisite Colour Swatches**:

- **Framework**: Tailwind CSS 3, Tailwind CSS 4, Bootstrap 5.3 or Plugin CSS
  (no build step, the default). Every site uses it unless overridden.
- **Site frameworks**: give a site its own framework, for example when each site
  has its own front-end build.
- **Additional shades / opacities**: comma-separated values added to each
  framework's defaults. Only add values your front-end build provides, for example
  opacities added to Bootstrap's `$utilities` map, or extra shades in Tailwind's theme.
- **Register plugin CSS automatically** (see Plugin CSS below).
- **Optional: generate stylesheet files**: a collapsed section with **Utilities**
  (`bg`, `text`, `border`, `textBg`, `opacity`) and file previews. Most sites
  never need it.

| Framework | Default shades | Default opacities |
| --- | --- | --- |
| Tailwind CSS 3 / 4 | 50, 100–900, 950 | 0–100 in steps of 5 |
| Bootstrap 5.3 | none | 10, 25, 50, 75, 100 |
| Plugin CSS | 50, 100–900, 950 | 0–100 in steps of 10 |

0% is transparent; **Solid** leaves the opacity modifier unset. Bootstrap's
defaults are its compiled `bg-opacity-*` and `border-opacity-*` classes. A stock
build has no `text-opacity-10` or `opacity-10`, so 10% only affects `bg`, `border`
and `textBg` unless your build adds them. If a site uses Bootstrap with no shades,
saved shades are ignored.

These settings can also be set in `config/multisite-colour-swatches.php`, at the
top level or inside `*`. Config values are shown as locked on the settings page:

```php
return [
    'framework' => 'tailwind4', // tailwind3, tailwind4, bootstrap5 or plugin
    'siteFrameworks' => ['siteB' => 'bootstrap5'],
    'customShades' => ['tailwind4' => ['975']],
    'customOpacities' => ['bootstrap5' => [5, 15]],
    'safelistUtilities' => ['bg', 'text', 'border', 'textBg', 'opacity'],
    'registerCss' => true,
    'outputPath' => '@root/multisite-colour-swatches',
    'palettes' => [/* ... */],
];
```

### Field settings

Each field has **Allow shades**, **Available shades**, **Allow opacity** and
**Available opacities**. Leave the lists blank to offer everything the site's
framework supports, or limit them, for example `100, 500, 900` and `25, 50, 75`.
Gradients have no shades, but can still use opacity.

If a saved shade or opacity is no longer available (for example after changing
a field's limits), the editor shows a warning and the entry cannot be saved until
another option is chosen. If the site's framework offers no shades at all, as with
stock Bootstrap, a saved shade is ignored instead.

### Swatch options

Add these keys to a swatch in the PHP config when needed:

```php
[
    'handle' => 'brandOrange',
    'label' => 'Brand orange',
    'color' => '#ef730b',
    'token' => 'brand-orange',             // class name: bg-brand-orange-200
    'shades' => ['50' => '#fff7ed'],        // exact shades; the rest are calculated
    // 'shades' => false,                   // no shades for this colour
    // 'theme' => false,                    // the framework already defines this colour
],
```

- `token` is the name used in classes. It defaults to the handle, so `brandOrange`
  gives `bg-brandOrange` unless you set a token. For Bootstrap, the token must be a
  key in your `$theme-colors` map.
- Shades without an exact colour are calculated like Bootstrap's `tint-color()` and
  `shade-color()`: 100 mixes 80% white, 900 mixes 80% black, `subtle` mixes 80%
  white and `emphasis` 60% black.
- Use `'theme' => false` for colours the framework already has: Bootstrap's
  `$theme-colors` (`primary`, `secondary`, …) or Tailwind's own colours (`blue`,
  `red`, …). Generated files then keep the framework's own classes. Tailwind keeps
  its own 50–950 shades. The control panel previews calculated shades, so add
  exact `shades` if the preview must match the framework.
- If you add `subtle` or `emphasis` as Bootstrap shades, note that Bootstrap's own
  classes for them ignore its opacity utilities.
- Gradients have no Tailwind or Bootstrap classes. Use Plugin CSS, `swatch.style`
  or `cssColor`.

### Templates

```twig
{% set swatch = entry.brandColour %}
{% if swatch %}
    {# Bootstrap: classes from your existing CSS #}
    <section class="{{ swatch.textBg }}">…</section>

    {# Tailwind: classes plus an inline style, in case Tailwind removed the class #}
    <section {{ swatch.attrs('bg', 'opacity') }}>…</section>
    <h2 class="text-xl {{ swatch.text }}" style="{{ swatch.style('text') }}">…</h2>
{% endif %}
```

| Helper | Tailwind CSS 3 / 4 | Bootstrap 5.3 | Plugin CSS |
| --- | --- | --- | --- |
| `swatch.bg` | `bg-primary-200/50` | `bg-primary bg-opacity-50` | `mcs-bg-primary-200 mcs-bg-opacity-50` |
| `swatch.text` | `text-primary-200/50` | `text-primary text-opacity-50` | `mcs-text-primary-200 mcs-text-opacity-50` |
| `swatch.border` | `border-primary-200/50` | `border-primary border-opacity-50` | `mcs-border-primary-200 mcs-border-opacity-50` |
| `swatch.textBg` | `bg-primary-200/50 text-black` | `text-bg-primary bg-opacity-50` | `mcs-text-bg-primary-200 mcs-bg-opacity-50` |
| `swatch.opacityClass` | `opacity-50` | `opacity-50` | `mcs-opacity-50` |
| `swatch.button` | `bg-primary-200/50 text-black` | `btn-primary` + style | `mcs-text-bg-primary-200 mcs-bg-opacity-50` |
| `swatch.buttonOutline` | `border-primary-200/50 text-primary-200/50` | `btn-outline-primary` + style | `mcs-border-… mcs-text-…` |
| `swatch.buttonLink` | `text-primary-200/50` | `btn-link text-primary text-opacity-50` | `mcs-text-primary-200 mcs-text-opacity-50` |
| `swatch.style('bg')` | `background-color: rgb(… / 0.5);` | `background-color: rgba(var(--bs-primary-rgb), 0.5);` | `background-color: rgb(… / 0.5);` |
| `swatch.attrs('bg')` | `class="…" style="…"` | `class="…"` | `class="…"` |
| `swatch.attrs('button')` | `class="…" style="…"` | `class="btn-primary" style="--bs-btn-bg: …"` | `class="…"` |

The examples show the `primary` colour with shade 200 (Bootstrap: no shade) and
50% opacity. Without a shade or opacity, the suffixes are left out (`bg-primary`).
`swatch.classes()` combines several helpers. `swatch.style()` accepts the same
utility names and returns matching inline CSS (`background-color`, `color`,
`border-color` or `opacity`). `swatch.attrs()` outputs the class attribute, plus
the style attribute on Tailwind sites. `swatch.cssColor` is a CSS colour or
gradient with the shade and opacity applied. `swatch.shadeColor`,
`swatch.contrastColor` (black or white), `swatch.token`, `swatch.shade`,
`swatch.opacity` and `swatch.framework` are also available. In GraphQL, use
`style(type: "bg")`. On Bootstrap sites, `style()` uses the stylesheet's
`--bs-{colour}-rgb` variables, so inline colours match your compiled
`$theme-colors` even if the plugin config's hex value differs.

#### Buttons

`swatch.button`, `swatch.buttonOutline` and `swatch.buttonLink` match the usual
Solid, Outline and Underline button styles. You can also use them as utility
names: `button` (or `btn`, `solid`), `buttonOutline` (or `outline`) and
`buttonLink` (or `link`, `underline`).

On Bootstrap, they use the `btn-*`, `btn-outline-*` and `btn-link` classes your
build already has. Bootstrap has no opacity classes for buttons, so add
`swatch.style()` too. It sets Bootstrap's own `--bs-btn-bg`, `--bs-btn-color` and
`--bs-btn-border-color` variables (or `--bs-text-opacity` for link buttons). For
example, a Hyper link with a colour field and a Button Style button group:

```twig
{% for link in entry.headerButtons %}
    {% set swatch = link.colourPickerButton ?? null %}
    {% set type = {outline: 'buttonOutline', underline: 'buttonLink'}[(link.buttonStyle ?? '')|string] ?? 'button' %}
    <a class="btn {{ swatch ? swatch.utility(type) }}"
        {% if swatch and swatch.style(type) %}style="{{ swatch.style(type) }}"{% endif %}
        href="{{ link.url }}" target="{{ link.target }}">{{ link.text }}</a>
{% endfor %}
```

`{{ swatch.attrs('button') }}` outputs the same class and style attributes, if
you don't need to add `btn` to the class attribute yourself. Hover, focus and active states keep the button class's
solid colours. On Tailwind and Plugin CSS sites, button helpers use the
background, border and text utilities.

### Optional: generating framework files

Not needed for normal use. To get real framework classes for every shade and
opacity, for example Bootstrap shade classes or a Tailwind safelist, generate the
colours and classes editors can choose, then add them to your front-end build.
For Bootstrap, also add the shades you generate to **Additional shades**, so
editors can choose them:

```shell
php craft multisite-colour-swatches/generate
php craft multisite-colour-swatches/generate --framework=tailwind4 --output=@root/src/css
php craft multisite-colour-swatches/generate --site=siteB
```

This writes one file for each framework the sites use (or for `--framework`) to
the **Output path**: `tailwind3.cjs`, `tailwind4.css`, `bootstrap5.scss` or
`plugin.css`. `--site` only includes that site and adds its handle to the file name,
for example `tailwind4-siteB.css`. Files only include the shades and opacities
fields allow. Run the command again after changing palettes, field settings or
plugin settings, then rebuild your CSS. The settings page previews the files.

If sites share a front-end build, the same token cannot have a different colour on
each site. The command warns about this and uses the first colour. Give those
swatches different tokens, or generate each site separately with `--site`.

**Tailwind CSS 4**

```css
@import "tailwindcss";
@import "./multisite-colour-swatches/tailwind4.css";
```

**Tailwind CSS 3**

```js
module.exports = {
    presets: [require('./multisite-colour-swatches/tailwind3.cjs')],
    content: ['./templates/**/*.twig'],
};
```

If your config has its own `safelist`, it replaces the preset's, so add
`...require('./multisite-colour-swatches/tailwind3.cjs').safelist` to it.

**Bootstrap 5.3** (Dart Sass)

```scss
@import "bootstrap/scss/functions";
@import "bootstrap/scss/variables";
@import "bootstrap/scss/variables-dark";
@import "bootstrap/scss/maps";
@import "bootstrap/scss/mixins";
@import "bootstrap/scss/root";
@import "bootstrap/scss/utilities";
@import "../multisite-colour-swatches/bootstrap5";
// ... other Bootstrap parts ...
@import "bootstrap/scss/utilities/api";
```

Or import it before the whole of Bootstrap:
`@import "../multisite-colour-swatches/bootstrap5"; @import "bootstrap/scss/bootstrap";`.

**Plugin CSS** needs no build step. On front-end requests, the CSS for each class a
template outputs is added to the page. Turn off **Register plugin CSS
automatically** if you include the generated `plugin.css` yourself.

Tailwind needs every combination of colour, shade, opacity and utility listed, so
the CSS can grow quickly. Limit shades, opacities and utilities to what editors
need.

## Tests

Install dependencies and run the isolated suite inside this package:

```shell
composer install --no-plugins --no-scripts
php tests/run.php
```

Tests use the package's Composer dependencies or the containing Craft project's
existing vendor directory. They do not bootstrap the project, read its `.env`,
connect to its database, install plugins or change existing content.
An optional first argument can point to another project's `vendor/autoload.php`
to test a different installed Craft/Hyper version. Hyper compatibility checks
run when that dependency is available.

For browser regressions (PHP-generated field markup, real jQuery and Chromium):

```shell
npm ci --ignore-scripts
npx playwright install chromium
```

Set `SWATCH_BROWSER_FIXTURE` to `tests/browser/fixture.html` when running the PHP
suite, then run `npm test`. For example, in PowerShell:

```powershell
$env:SWATCH_BROWSER_FIXTURE = 'tests\browser\fixture.html'
php tests\run.php
npm test
```

Framework compile tests build CSS with the real Tailwind CSS 3, Tailwind CSS 4,
Bootstrap 5.3 (Dart Sass) and PostCSS packages. They check that every class an
editor can produce exists in a normal Bootstrap build with no generated file, and,
for the optional generated files, that every class exists and the Bootstrap file
causes no Sass warnings:

```powershell
$env:SWATCH_FRAMEWORK_FIXTURES = 'tests\frameworks\generated'
php tests\run.php
npm run test:frameworks
```

The GitHub Actions workflow runs PHP 8.2 and 8.5 with no Hyper, Hyper 2 and Hyper 3,
using unmodified Composer releases. Browser tests cover inserted/replaced markup,
independent fields, repeated asset loads, keyboard selection, clearing,
shade/opacity dropdowns and Craft dirty-state notifications. Development tooling
is not required in production.

The PHP suite exercises real field APIs, Twig, GraphQL and Hyper serialization
using an isolated application/element harness. It also exercises Craft's settings
save service and project-config packing with an in-memory persistence fixture.
Browser tests cover 0% opacity separately from a blank (solid) selection.
Form macros and application services are fixtures; these tests do **not**
substitute for database-backed Craft editor save/reload testing.

## Release checklist

- Create a public GitHub repository containing **only this package**, not the
  containing Craft project, its configuration, uploads, credentials or vendor tree.
- Add the real repository URLs to `composer.json`: `support.source`,
  `support.issues`, `support.docs`, `extra.documentationUrl` and `extra.changelogUrl`.
  These are intentionally omitted until the repository exists.
- Run all CI jobs successfully. Check the same entry on two sites in a development
  Craft install: save, reload, switch sites, add/duplicate/remove Hyper links, and
  repeat inside Matrix. Confirm the containing Hyper field translates per site.
- Verify blank/default/random selections, stale selections and required fields.
  A copied link whose handle is unavailable on the destination site must be
  reselected, not silently mapped to a different colour.
- On a development install, set a different framework on two sites, choose shades
  and opacities, and check the classes render on each site with the existing
  front-end CSS (Tailwind: through `swatch.attrs()` or `swatch.style()`).
- Test install/uninstall on a disposable project. Do not uninstall from a project
  while its fields remain in use.
- Decide free versus paid before submitting. Craft does not allow a free listing
  to become paid later. The current package retains its MIT license and upstream
  notices; this preparation does not decide your commercial terms.
- Add listing screenshots, a support contact and a description in Craft Console.
  Connect the GitHub account and select the developer organization.
- Change the changelog's `Unreleased` heading to `1.0.0 - YYYY-MM-DD`, confirm
  `composer.json`'s version matches, commit and tag `1.0.0`, then push.
  The explicit version currently supports local Composer path installations.
- Submit for review through Craft Console. Approval and a version tag are needed
  for in-control-panel Plugin Store availability. Packagist registration is optional.

See Craft's [publishing guide](https://craftcms.com/docs/5.x/extend/plugin-store.html)
and [changelog format](https://craftcms.com/docs/5.x/extend/changelogs-and-updates.html).

## Attribution

Based on the MIT-licensed Colour Swatches project by Percipio Global Ltd. /
Craft Pulse, including upstream 5.2's stable-handle convention, and Walker Designs'
Brand Colour Swatches implementation. See [LICENSE.md](LICENSE.md).
