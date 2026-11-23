<?php

declare(strict_types=1);

use craft\helpers\Json;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use walkerdesigns\multisitecolourswatches\console\controllers\GenerateController;
use walkerdesigns\multisitecolourswatches\fields\ColourSwatches;
use walkerdesigns\multisitecolourswatches\helpers\Colour;
use walkerdesigns\multisitecolourswatches\helpers\Frameworks;
use walkerdesigns\multisitecolourswatches\helpers\Generator;
use walkerdesigns\multisitecolourswatches\helpers\PluginCss;
use walkerdesigns\multisitecolourswatches\models\Settings;
use walkerdesigns\multisitecolourswatches\models\Swatch;
use yii\console\ExitCode;

// Shade, opacity, framework and generator checks. Included by run.php; the closure keeps its variables private.
(static function() use ($plugin, $settings, $fixture, $default, $omeo): void {
    $cpDefaults = $settings->toArray();

    // Colour maths follows Bootstrap's tint-color(), shade-color() and color-contrast().
    same([170, 187, 204], Colour::rgb('#ABC'), 'Short hex colours are expanded');
    same([239, 115, 11], Colour::rgb('#ef730b80'), 'Hex alpha is ignored when reading channels');
    same(null, Colour::rgb('rebeccapurple'), 'Named colours have no channels');
    same('#aabbcc', Colour::normalize(' #ABC '), 'Hex colours are normalized');
    same('rebeccapurple', Colour::normalize(' RebeccaPurple '), 'Colour names are normalized');
    same('#fce3ce', Colour::shade('#ef730b', '100'), 'Shade 100 is an 80% tint');
    same('#b7c2bd', Colour::shade('#4a665a', '200'), 'Shade 200 is a 60% tint');
    same('#ef730b', Colour::shade('#EF730B', '500'), 'Shade 500 is the base colour');
    same('#301702', Colour::shade('#ef730b', '900'), 'Shade 900 is an 80% shade');
    same('#031633', Colour::shade('#0d6efd', '900'), 'Shade 900 matches Bootstrap $blue-900');
    same('#01060d', Colour::shade('#0d6efd', '975'), 'Additional shades interpolate beyond the default scale');
    same('#cfe2ff', Colour::shade('#0d6efd', 'subtle'), 'Subtle matches Bootstrap $primary-bg-subtle');
    same('#052c65', Colour::shade('#0d6efd', 'emphasis'), 'Emphasis matches Bootstrap $primary-text-emphasis');
    same(null, Colour::shade('#0d6efd', '1000'), 'Shades beyond the scale cannot be calculated');
    same(null, Colour::shade('#0d6efd', 'brand'), 'Unknown named shades cannot be calculated');
    same(null, Colour::shade('rebeccapurple', '100'), 'Named colours cannot be tinted');
    same('#ffffff', Colour::contrast('#0d6efd'), 'Contrast matches Bootstrap: white text on $primary');
    same('#000000', Colour::contrast('#ef730b'), 'Contrast uses black when white fails 4.5:1');
    same('#ffffff', Colour::contrast('#4a665a'), 'Dark colours get white text');
    same('rgb(252 227 206 / 0.5)', Colour::alpha('#fce3ce', 50), 'Opacity uses rgb() with an alpha value');
    same('#fce3ce', Colour::alpha('#fce3ce', 100), 'Full opacity keeps the colour unchanged');
    same('rgb(0 0 0 / 0)', Colour::alpha('#000', 0), 'Zero opacity is supported');
    same('color-mix(in srgb, rebeccapurple 25%, transparent)', Colour::alpha('rebeccapurple', 25), 'Named colours use color-mix() for opacity');

    // Framework scales and class names.
    same(['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'], Frameworks::defaultShades(Frameworks::TAILWIND4), 'Tailwind shades are 50–950');
    same([], Frameworks::defaultShades(Frameworks::BOOTSTRAP5), 'Bootstrap has no default shades because stock builds have no shade classes for theme colours');
    same([10, 25, 50, 75, 100], Frameworks::defaultOpacities(Frameworks::BOOTSTRAP5), 'Bootstrap opacities match the bg-opacity-* classes in a stock build');
    same(range(0, 100, 5), Frameworks::defaultOpacities(Frameworks::TAILWIND3), 'Tailwind opacities match its default scale');
    same(range(0, 100, 10), Frameworks::defaultOpacities(Frameworks::PLUGIN), 'Plugin CSS opacities include transparent in steps of 10');
    same('0.05', Frameworks::decimal(5), 'Opacity decimals keep two places when needed');
    same('0.5', Frameworks::decimal(50), 'Opacity decimals drop trailing zeros');
    same('1', Frameworks::decimal(100), 'Full opacity is 1');
    same('0', Frameworks::decimal(0), 'Zero opacity is 0');
    same(['50', '100', '900', 'emphasis', 'subtle'], Frameworks::sortShades(['emphasis', '900', 50, 'subtle', '100']), 'Numeric shades sort numerically before named shades');
    same('bg', Frameworks::normalizeUtility('background'), 'Utility aliases are accepted');
    same('textBg', Frameworks::normalizeUtility('text-bg'), 'Hyphenated utility names are accepted');
    same('text', Frameworks::normalizeUtility('Colour'), 'British spelling is accepted');
    rejects(fn() => Frameworks::normalizeUtility('shadow'), 'Unknown utilities are rejected');
    same('100', Frameworks::parseShade(100), 'Integer shades from project config are accepted');
    same(null, Frameworks::parseShade(''), 'A blank shade means the base colour');
    rejects(fn() => Frameworks::parseShade('Bad Shade'), 'Shades with spaces or capitals are rejected');
    rejects(fn() => Frameworks::parseShade('a--b'), 'Shades with double hyphens are rejected');
    same(50, Frameworks::parseOpacity('50'), 'Posted opacities are converted to integers');
    same(0, Frameworks::parseOpacity(0), 'Zero opacity is valid');
    rejects(fn() => Frameworks::parseOpacity(101), 'Opacities above 100 are rejected');
    rejects(fn() => Frameworks::parseOpacity('5.5'), 'Fractional opacities are rejected');
    rejects(fn() => Frameworks::parseOpacity(-5), 'Negative opacities are rejected');
    same(['100', '200', 'subtle'], Frameworks::splitList(" 100, 200\nSubtle,,100 "), 'Lists accept commas, spaces and new lines without duplicates');
    same(['100', 'subtle'], Frameworks::splitList([100, 'Subtle']), 'Project config lists are normalized');
    same([33, 66, 'abc'], Frameworks::opacityList('33, 66 abc 033'), 'Opacity lists convert numbers and keep invalid values for validation');
    foreach ([
        ['bg-primary-100/50', Frameworks::TAILWIND4, 'bg', '100', 50],
        ['text-primary', Frameworks::TAILWIND3, 'text', null, null],
        ['bg-primary-900/5 text-white', Frameworks::TAILWIND3, 'textBg', '900', 5],
        ['opacity-75', Frameworks::TAILWIND4, 'opacity', null, 75],
        ['', Frameworks::TAILWIND4, 'opacity', '100', null],
        ['bg-primary-subtle bg-opacity-25', Frameworks::BOOTSTRAP5, 'bg', 'subtle', 25],
        ['text-bg-primary bg-opacity-50', Frameworks::BOOTSTRAP5, 'textBg', null, 50],
        ['border-primary-200 border-opacity-10', Frameworks::BOOTSTRAP5, 'border', '200', 10],
        ['opacity-50', Frameworks::BOOTSTRAP5, 'opacity', null, 50],
        ['mcs-text-primary-900', Frameworks::PLUGIN, 'text', '900', null],
        ['mcs-text-bg-primary mcs-bg-opacity-50', Frameworks::PLUGIN, 'textBg', null, 50],
        ['mcs-opacity-50', Frameworks::PLUGIN, 'opacity', null, 50],
    ] as [$expected, $framework, $utility, $shade, $opacity]) {
        same($expected, Frameworks::classes($framework, $utility, 'primary', $shade, $opacity, Colour::WHITE), "$framework $utility classes follow the framework naming");
    }
    same('', Frameworks::classes(Frameworks::TAILWIND4, 'bg', '', null, null, Colour::WHITE), 'Swatches without a token have no classes');

    // Plugin settings saved from the control panel.
    $fresh = new Settings();
    same(Frameworks::PLUGIN, $fresh->framework, 'Plugin CSS is the default framework');
    same(true, $fresh->registerCss, 'Plugin CSS is registered automatically by default');
    same('@root/multisite-colour-swatches', $fresh->outputPath, 'Generated files default to the project root');
    same(Frameworks::UTILITIES, $fresh->getSafelistUtilities(), 'Every utility is generated by default');
    same(Settings::SETTING_KEYS, array_keys($fresh->toArray()), 'Only control panel settings are saved to project config');
    $posted = new Settings();
    $posted->setAttributes([
        'framework' => Frameworks::TAILWIND4,
        'siteFrameworks' => ['default' => '', 'omeo' => Frameworks::BOOTSTRAP5],
        'customShades' => ['tailwind4' => '25, 975 25', 'bootstrap5' => ''],
        'customOpacities' => ['tailwind4' => '33,66', 'bootstrap5' => '5 15'],
        'registerCss' => '',
        'outputPath' => ' @webroot/css ',
        'safelistUtilities' => ['', 'bg', 'opacity'],
    ], false);
    same(true, $posted->validate(), 'Posted settings validate');
    same(['omeo' => Frameworks::BOOTSTRAP5], $posted->siteFrameworks, 'Sites using the global framework are not stored');
    same(['tailwind4' => ['25', '975']], $posted->customShades, 'Posted shades become lists and empty lists are dropped');
    same(['tailwind4' => [33, 66], 'bootstrap5' => [5, 15]], $posted->customOpacities, 'Posted opacities become integer lists');
    same(false, $posted->registerCss, 'An empty lightswitch turns CSS registration off');
    same('@webroot/css', $posted->outputPath, 'The output path is trimmed');
    same(['bg', 'opacity'], $posted->safelistUtilities, 'The empty checkbox placeholder is removed');
    same(Frameworks::TAILWIND4, $posted->getFrameworkForSite('default'), 'Sites without an override use the global framework');
    same(Frameworks::BOOTSTRAP5, $posted->getFrameworkForSite('omeo'), 'Site framework overrides are used');
    same(['25', '50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950', '975'], $posted->getShades(Frameworks::TAILWIND4), 'Additional shades extend the default scale in order');
    same([5, 10, 15, 25, 50, 75, 100], $posted->getOpacities(Frameworks::BOOTSTRAP5), 'Additional Bootstrap opacities are merged and sorted');
    same(Frameworks::defaultShades(Frameworks::BOOTSTRAP5), $posted->getShades(Frameworks::BOOTSTRAP5), 'Other frameworks keep their own scales');
    $roundTrip = new Settings();
    $roundTrip->setAttributes($posted->toArray(), false);
    same($posted->toArray(), $roundTrip->toArray(), 'Settings survive a project config round trip');
    $pluginsService = new craft\services\Plugins();
    same(true, $pluginsService->savePluginSettings($plugin, $posted->toArray()), 'Craft saves plugin settings through its real service');
    $savedSettings = Craft::$app->projectConfig->values['plugins.multisite-colour-swatches.settings'];
    $roundTrip->setAttributes(craft\helpers\ProjectConfig::unpackAssociativeArrays($savedSettings), false);
    same($posted->toArray(), $roundTrip->toArray(), 'Craft project config packing preserves frameworks, scales and field types');
    same(false, $pluginsService->savePluginSettings($plugin, ['customOpacities' => ['bootstrap5' => '101']]), 'Craft rejects an invalid opacity setting');
    same($savedSettings, Craft::$app->projectConfig->values['plugins.multisite-colour-swatches.settings'], 'Invalid settings do not overwrite saved project config');
    $settings->setAttributes($cpDefaults, false);
    $settings->clearErrors();
    $invalid = new Settings();
    $invalid->setAttributes([
        'framework' => 'bulma',
        'siteFrameworks' => ['omeo' => 'bulma'],
        'customShades' => ['tailwind4' => 'Bad_Shade'],
        'customOpacities' => ['bootstrap5' => '150', 'foundation' => '5'],
        'safelistUtilities' => ['shadow'],
        'outputPath' => '',
    ], false);
    same(false, $invalid->validate(), 'Invalid settings fail validation');
    same(true, $invalid->hasErrors('framework'), 'Unknown frameworks are rejected');
    same(['Site omeo has an unknown framework "bulma".'], $invalid->getErrors('siteFrameworks'), 'Unknown site frameworks name the site');
    same(['Tailwind CSS 4: "bad_shade": A shade can contain only lowercase letters, numbers and single hyphens.'], $invalid->getErrors('customShades'), 'Shade errors name the framework and value');
    same(['Bootstrap 5.3: "150": An opacity must be a whole number from 0 to 100.', 'Unknown framework "foundation".'], $invalid->getErrors('customOpacities'), 'Opacity errors name the framework and value');
    same(['Unknown utilities: shadow'], $invalid->getErrors('safelistUtilities'), 'Unknown utilities are rejected');
    same(true, $invalid->hasErrors('outputPath'), 'An output path is required');
    same(Frameworks::PLUGIN, $invalid->getFrameworkForSite('omeo'), 'Invalid stored frameworks fall back to plugin CSS');
    same(Frameworks::defaultShades(Frameworks::TAILWIND4), $invalid->getShades(Frameworks::TAILWIND4), 'Invalid stored shades are ignored');
    same(Frameworks::defaultOpacities(Frameworks::BOOTSTRAP5), $invalid->getOpacities(Frameworks::BOOTSTRAP5), 'Invalid stored opacities are ignored');
    $configured = new Settings(['framework' => Frameworks::TAILWIND3]);
    $configured->loadConfig(['*' => ['framework' => Frameworks::BOOTSTRAP5, 'palettes' => ['brand' => []]], 'siteFrameworks' => ['omeo' => 'tailwind4']]);
    same(Frameworks::BOOTSTRAP5, $configured->framework, 'Settings in the "*" config group override the control panel');
    same(true, $configured->isOverriddenByConfig('framework'), 'Config overrides are reported for the settings page');
    same(true, $configured->isOverriddenByConfig('siteFrameworks'), 'Top-level config settings are overrides');
    same(false, $configured->isOverriddenByConfig('registerCss'), 'Settings missing from the config are not overrides');
    same(Frameworks::TAILWIND4, $configured->getFrameworkForSite('omeo'), 'Configured site frameworks are used');
    same(['brand'], $configured->getPaletteNames(), 'Palettes still load alongside settings');
    $configured->loadConfig([]);
    same(Frameworks::TAILWIND3, $configured->framework, 'Reloading config restores the control panel value');
    same([], $configured->siteFrameworks, 'Reloading config restores site frameworks');
    same(false, $configured->isOverriddenByConfig('framework'), 'Removed config settings are no longer overrides');
    rejects(fn() => $configured->loadConfig(['framework' => 'bulma']), 'Invalid config settings are rejected');
    same(Frameworks::TAILWIND3, $configured->framework, 'A rejected config keeps the previous setting');
    same([], $configured->getErrors(), 'A rejected config does not leave validation errors behind');
    rejects(fn() => $configured->loadConfig(['customOpacities' => ['tailwind4' => '200']]), 'Invalid configured opacities are rejected');

    // Swatch configuration and editor selections.
    $orange = Swatch::fromArray(['handle' => 'brandOrange', 'label' => 'Brand orange', 'color' => '#EF730B', 'token' => 'brand-orange', 'shades' => ['50' => '#FFF7ED', 'subtle' => 'PeachPuff']]);
    same('brand-orange', $orange->getToken(), 'A configured token replaces the handle in class names');
    same('#fff7ed', $orange->shadeColorFor('50'), 'Exact configured shades win and are normalized');
    same('peachpuff', $orange->shadeColorFor('subtle'), 'Configured shades may be CSS colour names');
    same('#fce3ce', $orange->shadeColorFor('100'), 'Shades without an exact colour are calculated');
    same(null, $orange->shadeColorFor('brand'), 'Unknown shades have no colour');
    same('#ef730b', $orange->getShadeColor(), 'Without a shade the base colour is used');
    same('primary', Swatch::fromArray(['label' => 'Primary', 'color' => '#fff'])->getToken(), 'The token defaults to the handle');
    same('dark-blue', Swatch::fromArray(['handle' => 'dark blue!', 'label' => 'Dark blue', 'color' => '#00008b'])->getToken(), 'Handles that are not valid class names are converted');
    same('', Swatch::fromArray(['handle' => '!!!', 'label' => 'Symbols', 'color' => '#000'])->getToken(), 'Handles without letters or numbers have no token');
    rejects(fn() => Swatch::fromArray(['label' => 'Bad', 'color' => '#fff', 'token' => 'bad token']), 'Tokens with spaces are rejected');
    rejects(fn() => Swatch::fromArray(['label' => 'Bad', 'color' => '#fff', 'token' => '-lead']), 'Tokens with leading hyphens are rejected');
    rejects(fn() => Swatch::fromArray(['label' => 'Bad', 'color' => '#fff', 'shades' => ['100' => 'url(x)']]), 'Shade colours are validated');
    rejects(fn() => Swatch::fromArray(['label' => 'Bad', 'color' => '#fff', 'shades' => ['Bad' => '#fff']]), 'Shade names are validated');
    rejects(fn() => Swatch::fromArray(['label' => 'Bad', 'color' => '#fff', 'shades' => 'yes']), 'Shade maps must be arrays or false');
    rejects(fn() => Swatch::fromArray(['label' => 'Bad', 'color' => '#fff', 'theme' => 'maybe']), 'Theme flags must be booleans');
    same(false, Swatch::fromArray(['label' => 'Blue', 'color' => '#0d6efd', 'theme' => false])->definesThemeColour(), 'theme => false marks framework colours');
    $fixed = Swatch::fromArray(['label' => 'Fixed', 'color' => '#123456', 'shades' => false]);
    same(false, $fixed->supportsShade('100'), 'shades => false turns shades off for one swatch');
    same(null, $fixed->shadeColorFor('500'), 'shades => false has no shade colours');
    $named = Swatch::fromArray(['label' => 'Rebecca', 'color' => 'rebeccapurple']);
    same(false, $named->supportsShade('100'), 'Shades are not calculated for colour names');
    same('rebeccapurple', $named->getShadeColor(), 'Colour names are their own base colour');
    same('color-mix(in srgb, rebeccapurple 25%, transparent)', $named->withSelection(null, 25)->getCssColor(), 'Named colours support opacity');
    $gradient = Swatch::fromArray(['label' => 'Rainbow', 'color' => '#ff0000, #0000ff']);
    same(false, $gradient->supportsShade('100'), 'Gradients have no shades');
    same(null, $gradient->getShadeColor(), 'Gradients have no single shade colour');
    same('linear-gradient(to bottom right, rgb(255 0 0 / 0.5), rgb(0 0 255 / 0.5))', $gradient->withSelection(null, 50)->getCssColor(), 'Gradient opacity applies to each stop');
    same('#000000', $gradient->getContrastColor(), 'Gradient contrast uses the first stop');
    same(null, Swatch::fromArray(['label' => 'P', 'color' => '#fff', 'shade' => '100'])->shade, 'Palette config cannot preselect a shade');
    $saved = Swatch::fromValue(['handle' => 'primary', 'label' => 'Primary', 'color' => '#ef730b', 'shade' => '100', 'opacity' => '50',
        'token' => 'injected', 'shades' => ['100' => '#000000'], 'theme' => false]);
    same('100', $saved->shade, 'Saved shades are restored');
    same(50, $saved->opacity, 'Saved opacities are restored as integers');
    same('primary', $saved->getToken(), 'Saved values cannot inject a token');
    same('#fce3ce', $saved->shadeColorFor('100'), 'Saved values cannot inject shade colours');
    same(true, $saved->definesThemeColour(), 'Saved values cannot change the theme flag');
    same(['handle', 'label', 'color', 'class', 'default', 'shade', 'opacity'], array_keys($saved->toArray()), 'Only the selection is serialized, not config context');
    same(null, Swatch::fromValue(['label' => 'P', 'color' => '#fff', 'shade' => '', 'opacity' => ''])->opacity, 'Blank selects mean no modifier');
    rejects(fn() => Swatch::fromValue(['label' => 'P', 'color' => '#fff', 'shade' => 'Bad Shade']), 'Tampered shades are rejected');
    rejects(fn() => Swatch::fromValue(['label' => 'P', 'color' => '#fff', 'opacity' => 150]), 'Tampered opacities are rejected');
    $base = Swatch::fromArray(['handle' => 'primary', 'label' => 'Primary', 'color' => '#ef730b']);
    $selected = $base->withSelection('100', 50, Frameworks::TAILWIND4);
    same(null, $base->shade, 'withSelection() returns a copy');
    same(Frameworks::TAILWIND4, $selected->getFramework(), 'Selections know their site framework');
    same('bg-primary-100/50', $selected->bg(), 'Tailwind background classes include shade and opacity');
    same('text-primary-100/50', $selected->text(), 'Tailwind text classes include shade and opacity');
    same('border-primary-100/50', $selected->border(), 'Tailwind border classes include shade and opacity');
    same('bg-primary-100/50 text-black', $selected->textBg(), 'Tailwind text-bg adds contrasting text');
    same('opacity-50', $selected->opacityClass(), 'Element opacity classes are available');
    same('bg-primary-100/50 opacity-50', $selected->classes('bg', 'opacity'), 'classes() combines utilities');
    same('text-primary-100/50 border-primary-100/50', $selected->classes(['text', 'border']), 'classes() accepts arrays');
    same(Frameworks::TAILWIND4, $selected->withSelection(null, null)->getFramework(), 'Changing a selection keeps its framework');
    same('bg-primary', $selected->withSelection(null, null)->bg(), 'Base colours have no shade or opacity suffix');
    same('', $selected->withSelection(null, null)->opacityClass(), 'There is no opacity class without an opacity');
    same('rgb(252 227 206 / 0.5)', $selected->getCssColor(), 'CSS colours include shade and opacity');
    same('#000000', $selected->getContrastColor(), 'Contrast uses the selected shade');
    rejects(fn() => $selected->utility('shadow'), 'Unknown utility names are rejected');
    $bootstrap = $base->withSelection('subtle', 25, Frameworks::BOOTSTRAP5);
    same('bg-primary-subtle bg-opacity-25', $bootstrap->bg(), 'Bootstrap classes use separate opacity utilities');
    same('text-bg-primary-subtle bg-opacity-25', $bootstrap->textBg(), 'Bootstrap text-bg uses background opacity');

    // Inline styles render shades and opacity even when the framework class is not in the compiled CSS.
    same('background-color: rgb(252 227 206 / 0.5);', $selected->style('bg'), 'style() gives the shaded, translucent background');
    same('background-color: rgb(252 227 206 / 0.5); color: #000000; border-color: rgb(252 227 206 / 0.5); opacity: 0.5;', $selected->style('textBg', ['border', 'opacity']), 'style() combines utilities');
    same('color: #ef730b;', $base->withSelection(null, null)->style('text'), 'Base colours keep their hex value');
    same('', $base->withSelection(null, null)->style('opacity'), 'There is no opacity style without an opacity');
    same('background-image: linear-gradient(to bottom right, rgb(255 0 0 / 0.5), rgb(0 0 255 / 0.5)); color: rgb(255 0 0 / 0.5);', $gradient->withSelection(null, 50)->style('bg', 'text'), 'Gradient backgrounds use background-image; text uses the first stop');
    rejects(fn() => $selected->style('shadow'), 'Unknown style utilities are rejected');
    same('class="bg-primary-100/50" style="background-color: rgb(252 227 206 / 0.5);"', (string)$selected->attrs('bg'), 'Tailwind attrs() adds an inline fallback style');
    same('class="text-bg-primary-subtle bg-opacity-25"', (string)$bootstrap->attrs('textBg'), 'Bootstrap attrs() relies on the existing classes only');
    same('', (string)$base->withSelection(null, null, Frameworks::BOOTSTRAP5)->attrs('opacity'), 'attrs() omits empty attributes');

    // Buttons: Bootstrap's own btn-* classes, with opacity through its --bs-btn-* variables.
    $orangeBtn = Swatch::fromArray(['handle' => 'orange', 'label' => 'Orange', 'color' => '#ef730b']);
    $solidBtn = $orangeBtn->withSelection(null, null, Frameworks::BOOTSTRAP5);
    $fadedBtn = $orangeBtn->withSelection(null, 50, Frameworks::BOOTSTRAP5);
    same(['btn-orange', 'btn-outline-orange', 'btn-link text-orange'], [$solidBtn->button(), $solidBtn->buttonOutline(), $solidBtn->buttonLink()], 'Bootstrap buttons use the existing button classes');
    same(['button', 'buttonOutline', 'buttonLink', 'buttonLink'], array_map([Frameworks::class, 'normalizeUtility'], ['btn', 'outline', 'underline', 'btn-link']), 'Button aliases match common button style names');
    same(['', ''], [$solidBtn->style('button'), (string)$solidBtn->attrs('button') === 'class="btn-orange"' ? '' : 'style'], 'Solid buttons need no inline style');
    same('btn-orange', $fadedBtn->button(), 'Bootstrap has no button opacity class');
    same('--bs-btn-bg: rgba(var(--bs-orange-rgb), 0.5); --bs-btn-border-color: rgba(var(--bs-orange-rgb), 0.5); --bs-btn-disabled-bg: rgba(var(--bs-orange-rgb), 0.5); --bs-btn-disabled-border-color: rgba(var(--bs-orange-rgb), 0.5);', $fadedBtn->style('button'), 'Button opacity overrides Bootstrap button variables with the stylesheet colour');
    same('--bs-btn-color: rgba(var(--bs-orange-rgb), 0.5); --bs-btn-border-color: rgba(var(--bs-orange-rgb), 0.5); --bs-btn-disabled-color: rgba(var(--bs-orange-rgb), 0.5); --bs-btn-disabled-border-color: rgba(var(--bs-orange-rgb), 0.5);', $fadedBtn->style('outline'), 'Outline button opacity affects its text and border');
    same(['btn-link text-orange text-opacity-50', '--bs-text-opacity: 0.5;'], [$fadedBtn->buttonLink(), $fadedBtn->style('buttonLink')], 'Link buttons use text opacity, inline too for values without a class');
    same('class="btn-orange" style="--bs-btn-bg: rgba(var(--bs-orange-rgb), 0.5); --bs-btn-border-color: rgba(var(--bs-orange-rgb), 0.5); --bs-btn-disabled-bg: rgba(var(--bs-orange-rgb), 0.5); --bs-btn-disabled-border-color: rgba(var(--bs-orange-rgb), 0.5);"', (string)$fadedBtn->attrs('button'), 'Bootstrap attrs() adds button opacity variables');
    same('class="bg-orange bg-opacity-50"', (string)$fadedBtn->attrs('bg'), 'Bootstrap attrs() keeps colour utilities class-only');
    same('background-color: rgba(var(--bs-orange-rgb), 0.5);', $fadedBtn->style('bg'), 'Bootstrap inline colours use the stylesheet colour variables');
    $shadedBtn = $orangeBtn->withSelection('100', 50, Frameworks::BOOTSTRAP5);
    same(['btn-orange', 'btn-outline-orange'], [$shadedBtn->button(), $shadedBtn->buttonOutline()], 'Shaded Bootstrap buttons keep the base button class that Bootstrap compiles');
    same('--bs-btn-bg: rgb(252 227 206 / 0.5); --bs-btn-border-color: rgb(252 227 206 / 0.5); --bs-btn-disabled-bg: rgb(252 227 206 / 0.5); --bs-btn-disabled-border-color: rgb(252 227 206 / 0.5); --bs-btn-color: #000000; --bs-btn-disabled-color: #000000;', $shadedBtn->style('button'), 'Custom Bootstrap shades use the calculated colour and contrasting text');
    same('--bs-btn-color: #fce3ce; --bs-btn-border-color: #fce3ce; --bs-btn-disabled-color: #fce3ce; --bs-btn-disabled-border-color: #fce3ce;', $orangeBtn->withSelection('100', null, Frameworks::BOOTSTRAP5)->style('buttonOutline'), 'A shade without opacity still needs inline button colours');
    $twBtn = $orangeBtn->withSelection('100', 50, Frameworks::TAILWIND4);
    same(['bg-orange-100/50 text-black', 'border-orange-100/50 text-orange-100/50', 'text-orange-100/50'], [$twBtn->button(), $twBtn->buttonOutline(), $twBtn->buttonLink()], 'Tailwind buttons use colour utilities');
    same('class="bg-orange-100/50 text-black" style="background-color: rgb(252 227 206 / 0.5); color: #000000;"', (string)$twBtn->attrs('button'), 'Tailwind button attrs() adds inline colours');
    $view = Craft::$app->view;
    $request = Craft::$app->request;
    $view->css = [];
    $request->site = true;
    same('mcs-border-orange mcs-border-opacity-50 mcs-text-orange mcs-text-opacity-50', $orangeBtn->withSelection(null, 50, Frameworks::PLUGIN)->buttonOutline(), 'Plugin CSS buttons use plugin classes');
    same(true, str_contains(implode('', $view->css), '.mcs-border-orange{') && str_contains(implode('', $view->css), '.mcs-text-orange{'), 'Plugin CSS registers the CSS for button classes');
    $view->css = [];
    $request->site = false;

    // Plugin CSS is registered only for the classes a front-end template outputs.
    $view = Craft::$app->view;
    $request = Craft::$app->request;
    $view->css = [];
    $pluginSelection = $base->withSelection('100', 50, Frameworks::PLUGIN);
    same('mcs-bg-primary-100 mcs-bg-opacity-50', $pluginSelection->bg(), 'Plugin CSS classes use an mcs- prefix');
    same([], $view->css, 'Plugin CSS is not registered for console or control panel requests');
    $request->site = true;
    $pluginSelection->bg();
    same(['mcs-properties', 'mcs-bg-primary-100', 'mcs-bg-opacity-50'], array_keys($view->css), 'Only the CSS a template uses is registered');
    same(PluginCss::PROPERTIES, $view->css['mcs-properties'], 'Opacity properties are registered once');
    same('.mcs-bg-primary-100{background-color:rgb(252 227 206 / var(--mcs-bg-opacity, 1))}', $view->css['mcs-bg-primary-100'], 'Background rules use the selected shade');
    same('.mcs-bg-opacity-50{--mcs-bg-opacity:0.5}', $view->css['mcs-bg-opacity-50'], 'Opacity rules set the opacity property');
    $pluginSelection->textBg();
    same('.mcs-text-bg-primary-100{color:#000000;background-color:rgb(252 227 206 / var(--mcs-bg-opacity, 1))}', $view->css['mcs-textBg-primary-100'], 'Text-bg rules add contrasting text');
    same('mcs-opacity-50', $pluginSelection->opacityClass(), 'Plugin element opacity classes use an mcs- prefix');
    same('.mcs-opacity-50{opacity:0.5}', $view->css['mcs-opacity-opacity-50'], 'Element opacity rules are registered');
    $pluginSelection->withSelection(null, null)->border();
    same('.mcs-border-primary{border-color:rgb(239 115 11 / var(--mcs-border-opacity, 1))}', $view->css['mcs-border-primary'], 'Base colour rules are registered without a shade');
    $gradient->withSelection(null, null, Frameworks::PLUGIN)->bg();
    same('.mcs-bg-rainbow{background-image:linear-gradient(to bottom right,rgb(255 0 0 / var(--mcs-bg-opacity, 1)),rgb(0 0 255 / var(--mcs-bg-opacity, 1)))}',
        $view->css['mcs-bg-rainbow'], 'Plugin CSS supports gradients');
    $view->css = [];
    $settings->registerCss = false;
    $pluginSelection->text();
    same([], $view->css, 'Automatic CSS registration can be turned off');
    $settings->registerCss = true;
    $request->site = false;

    // Field limits, per-site frameworks and the editor dropdowns.
    $settings->setAttributes(['framework' => Frameworks::TAILWIND4, 'siteFrameworks' => ['omeo' => Frameworks::BOOTSTRAP5]], false);
    $stock = new ColourSwatches(['handle' => 'stock']);
    same([[], [10, 25, 50, 75, 100]], [$stock->getShadeOptions($omeo), $stock->getOpacityOptions($omeo)], 'Stock Bootstrap offers no shades and its built-in opacities');
    $stockValue = $stock->normalizeValue(['handle' => 'primary', 'label' => 'Primary', 'color' => '#fff', 'shade' => '100', 'opacity' => 50], $omeo);
    same([null, 50, 'bg-primary bg-opacity-50'], [$stockValue->shade, $stockValue->opacity, $stockValue->bg()], 'Stock Bootstrap drops saved shades and keeps native opacity classes');
    $stockHtml = $stock->getInputHtml(['handle' => 'primary', 'label' => 'Primary', 'color' => '#fff'], $omeo);
    same([false, true], [str_contains($stockHtml, 'data-shade>'), str_contains($stockHtml, 'data-opacity>')], 'Stock Bootstrap editors see only the opacity dropdown');
    // The rest of this block simulates a Bootstrap build that provides its own shade classes.
    $settings->setAttributes(['customShades' => ['bootstrap5' => '100, 200, 300, 400, 500, 600, 700, 800, 900, subtle, emphasis']], false);
    $modifiers = new ColourSwatches(['handle' => 'modifiers', 'name' => 'Modifiers', 'shades' => '100, 200, subtle,900', 'opacities' => '50 75 33']);
    same(['100', '200', 'subtle', '900'], $modifiers->shades, 'Posted field shades become a list');
    same([50, 75, 33], $modifiers->opacities, 'Posted field opacities become integers');
    same(true, $modifiers->validate(['shades', 'opacities', 'allowShades', 'allowOpacity']), 'Valid field limits pass validation');
    same(true, (new ColourSwatches(['handle' => 'all']))->allowShades && (new ColourSwatches(['handle' => 'all']))->allowOpacity, 'New fields allow shades and opacity');
    $badField = new ColourSwatches(['handle' => 'bad', 'shades' => 'Bad_Shade, 100', 'opacities' => '150, abc, 50']);
    same(false, $badField->validate(['shades', 'opacities']), 'Invalid field limits fail validation');
    same(['"bad_shade": A shade can contain only lowercase letters, numbers and single hyphens.'], $badField->getErrors('shades'), 'Field shade errors name the value');
    same(['"150": An opacity must be a whole number from 0 to 100.', '"abc": An opacity must be a whole number from 0 to 100.'], $badField->getErrors('opacities'), 'Field opacity errors name each value');
    same(Frameworks::TAILWIND4, $modifiers->frameworkFor($default), 'Sites without an override use the global framework');
    same(Frameworks::BOOTSTRAP5, $modifiers->frameworkFor($omeo), 'Site framework overrides apply to fields');
    same(Frameworks::BOOTSTRAP5, $modifiers->frameworkFor(), 'Without an element the current site framework is used');
    same(['100', '200', '900'], $modifiers->getShadeOptions($default), 'Field limits keep only shades the site framework provides');
    same(['100', '200', '900', 'subtle'], $modifiers->getShadeOptions($omeo), 'Field limits follow the framework order');
    same([50, 75], $modifiers->getOpacityOptions($default), 'Field opacity limits keep only Tailwind opacities');
    same([50, 75], $modifiers->getOpacityOptions($omeo), 'Field opacity limits keep only Bootstrap opacities');
    same(Frameworks::defaultShades(Frameworks::TAILWIND4), (new ColourSwatches(['handle' => 'all']))->getShadeOptions($default), 'Empty limits allow every framework shade');
    $choice = ['handle' => 'primary', 'label' => 'Primary', 'color' => '#ffffff'];
    $tailwindValue = $modifiers->normalizeValue($choice + ['shade' => '100', 'opacity' => '50'], $default);
    same('#fce3ce', $tailwindValue->getShadeColor(), 'Normalized selections use the configured site colour, not the submitted one');
    same('bg-primary-100/50', $tailwindValue->bg(), 'Tailwind sites output Tailwind classes');
    $bootstrapValue = $modifiers->normalizeValue($choice + ['shade' => '100', 'opacity' => '50'], $omeo);
    same('bg-primary-100 bg-opacity-50', $bootstrapValue->bg(), 'Bootstrap sites output Bootstrap classes for the same field');
    same('#dbe0de', $bootstrapValue->getShadeColor(), 'Shades use the element site colour');
    $serialized = $modifiers->serializeValue($choice + ['shade' => '100', 'opacity' => '50'], $omeo);
    same(['100', 50], [$serialized['shade'], $serialized['opacity']], 'Shade and opacity are saved with the selection');
    same(null, $modifiers->serializeValue(null, $omeo), 'Empty values are not given modifiers');
    $plain = new ColourSwatches(['handle' => 'plain', 'allowShades' => false, 'allowOpacity' => false]);
    $plainValue = $plain->normalizeValue($choice + ['shade' => '100', 'opacity' => 50], $omeo);
    same([null, null], [$plainValue->shade, $plainValue->opacity], 'Turning modifiers off drops saved modifiers instead of failing validation');
    same([[], []], [$plain->getShadeOptions($omeo), $plain->getOpacityOptions($omeo)], 'Turned-off modifiers have no options');
    $plainHtml = $plain->getInputHtml($choice, $omeo);
    same(false, str_contains($plainHtml, '<select') || str_contains($plainHtml, 'wd-colour-swatches__modifiers'), 'Turned-off modifiers render no dropdowns');

    // Element validation and random selection.
    $validateModifiers = $modifiers->getElementValidationRules()[0][0];
    $omeo->setFieldValue('modifiers', $choice + ['shade' => 'subtle', 'opacity' => 50]);
    $validateModifiers($omeo);
    same([], $omeo->getErrors('modifiers'), 'Available modifiers pass validation');
    $omeo->setFieldValue('modifiers', $choice + ['shade' => '950', 'opacity' => 33]);
    $validateModifiers($omeo);
    same(['The shade “950” is not available for Primary on this site.', 'The opacity 33% is not available on this site.'], $omeo->getErrors('modifiers'), 'Unavailable modifiers fail validation with clear messages');
    $omeo->clearErrors();
    $default->setFieldValue('modifiers', $choice + ['shade' => 'subtle']);
    $validateModifiers($default);
    same(['The shade “subtle” is not available for Primary on this site.'], $default->getErrors('modifiers'), 'Bootstrap shades fail on a Tailwind site');
    $default->clearErrors();
    $gradientField = new ColourSwatches(['handle' => 'gradientField', 'useConfigFile' => false, 'options' => [['handle' => 'rainbow', 'label' => 'Rainbow', 'color' => '#ff0000,#0000ff']]]);
    $rainbow = ['handle' => 'rainbow', 'label' => 'Rainbow', 'color' => '#ff0000,#0000ff'];
    $default->setFieldValue('gradientField', $rainbow + ['shade' => '100', 'opacity' => 50]);
    $gradientField->getElementValidationRules()[0][0]($default);
    same(['The shade “100” is not available for Rainbow on this site.'], $default->getErrors('gradientField'), 'Gradients cannot be saved with a shade but can use opacity');
    $default->clearErrors();
    $modifiers->setRandom = true;
    $default->setFieldValue('modifiers', null);
    $modifiers->beforeElementSave($default, true);
    $randomValue = $default->getFieldValue('modifiers');
    same(['primary', null, null, Frameworks::TAILWIND4], [$randomValue->handle, $randomValue->shade, $randomValue->opacity, $randomValue->getFramework()], 'Random colours are saved without modifiers');
    $modifiers->setRandom = false;
    $default->setFieldValue('modifiers', null);
    $default->setFieldValue('gradientField', null);
    $omeo->setFieldValue('modifiers', null);

    // Rendered editor inputs.
    $html = $modifiers->getInputHtml($choice + ['shade' => 'subtle', 'opacity' => 50], $omeo);
    same(true, str_contains($html, '<select id="modifiers-shade" data-shade>'), 'The shade dropdown is enabled for a selection');
    same(true, str_contains($html, '<option value="subtle" selected>subtle</option>'), 'The saved shade is selected');
    same(true, str_contains($html, '<option value="50" selected>50%</option>'), 'The saved opacity is selected');
    same(8, substr_count($html, '<option'), 'Dropdowns contain the limited options plus the base and solid choices');
    same(true, str_contains($html, '<option value="">Base colour</option>') && str_contains($html, '<option value="">Solid</option>'), 'Dropdowns can reset modifiers');
    same(true, str_contains($html, 'background: #dbe0de; opacity: 0.5'), 'The preview shows the selected shade and opacity');
    same(false, str_contains($html, 'data-unavailable'), 'Available modifiers show no warnings');
    preg_match_all('/data-shades="([^"]*)"/', $html, $shadeMaps);
    same([100 => '#dbe0de', 200 => '#b7c2bd', 900 => '#0f1412', 'subtle' => '#dbe0de'], Json::decode(html_entity_decode($shadeMaps[1][0])), 'Each swatch lists its calculated shade colours for the editor');
    same(2, count($shadeMaps[1]), 'Every swatch has a shade map');
    $html = $modifiers->getInputHtml($choice + ['shade' => '950', 'opacity' => 33], $omeo);
    same(true, str_contains($html, '<option value="950" selected data-unavailable-option data-extra-option>950</option>'), 'An unavailable saved shade remains visible');
    same(true, str_contains($html, '<option value="33" selected data-unavailable-option data-extra-option>33%</option>'), 'An unavailable saved opacity remains visible');
    same(true, str_contains($html, 'data-unavailable-modifier="shade"') && str_contains($html, 'data-unavailable-modifier="opacity"'), 'Unavailable modifiers have warnings');
    $html = $gradientField->getInputHtml($rainbow + ['shade' => '100'], $default);
    same(true, str_contains($html, '<option value="100" selected disabled data-unavailable-option>100</option>'), 'Shades a gradient cannot use are disabled');
    same(true, str_contains($html, 'data-shades="{}"'), 'Gradients have no shade colours');
    same(true, str_contains($html, 'data-unavailable-modifier="shade"'), 'A saved gradient shade has a warning');
    same(true, str_contains($html, 'background: linear-gradient(to bottom right, #ff0000, #0000ff); opacity: 1'), 'Gradient previews use the gradient');
    $html = $modifiers->getInputHtml(null, $default);
    same(true, str_contains($html, '<select id="modifiers-shade" data-shade disabled>') && str_contains($html, '<select id="modifiers-opacity" data-opacity disabled>'), 'Modifiers are disabled until a colour is selected');
    same(true, str_contains($html, 'background: transparent; opacity: 1'), 'The empty preview is transparent');
    same(true, str_contains($html, '<option value="900">900</option>') && !str_contains($html, 'value="subtle"'), 'Tailwind sites show Tailwind shades only');

    // Field settings, previews and GraphQL.
    $html = $modifiers->getSettingsHtml();
    same(true, str_contains($html, 'name="shades" value="100, 200, subtle, 900"'), 'Field shade limits are editable as text');
    same(true, str_contains($html, 'name="opacities" value="50, 75, 33"'), 'Field opacity limits are editable as text');
    same(true, str_contains($html, 'Tailwind CSS 4: 50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950; Bootstrap 5.3: 100, 200, 300, 400, 500, 600, 700, 800, 900, subtle, emphasis'), 'Settings list the shades of every framework in use');
    same(true, str_contains($html, 'Tailwind CSS 4: 0, 5, 10, 15'), 'Settings list framework opacities');
    $badField->validate(['shades', 'opacities']);
    same(true, str_contains($badField->getSettingsHtml(), '<p class="error">&quot;150&quot;: An opacity must be a whole number from 0 to 100.</p>'), 'Settings show field limit errors');
    $preview = $modifiers->getPreviewHtml($choice + ['shade' => '200', 'opacity' => 75], $omeo);
    same(true, str_contains($preview, 'background: rgb(183 194 189 / 0.75)') && str_contains($preview, 'title="Primary 200 75%"'), 'Element index previews show the shade and opacity');
    same(true, str_contains($modifiers->getPreviewHtml(null, $default), 'background: transparent'), 'Empty previews are transparent');
    $schema = new Schema(['query' => new ObjectType([
        'name' => 'Query',
        'fields' => ['swatch' => [
            'type' => $modifiers->getContentGqlType(),
            'resolve' => fn() => $modifiers->normalizeValue($choice + ['shade' => 'subtle', 'opacity' => 50], $omeo),
        ]],
    ])]);
    $gql = GraphQL::executeQuery($schema, '{ swatch { shade opacity framework token shadeColor cssColor contrastColor bg: utility(type: "bg") textBg: utility(type: "text-bg") opacityClass: utility(type: "opacity") } }')->toArray();
    same([
        'shade' => 'subtle',
        'opacity' => 50,
        'framework' => Frameworks::BOOTSTRAP5,
        'token' => 'primary',
        'shadeColor' => '#dbe0de',
        'cssColor' => 'rgb(219 224 222 / 0.5)',
        'contrastColor' => '#000000',
        'bg' => 'bg-primary-subtle bg-opacity-50',
        'textBg' => 'text-bg-primary-subtle bg-opacity-50',
        'opacityClass' => 'opacity-50',
    ], $gql['data']['swatch'] ?? $gql, 'GraphQL exposes shade, opacity, colours and framework classes');
    $gql = GraphQL::executeQuery($schema, '{ swatch { utility(type: "shadow") } }')->toArray();
    same(true, str_starts_with($gql['errors'][0]['message'] ?? '', 'Unknown utility type "shadow"'), 'Unknown GraphQL utilities return a clear error');

    // Framework files generated from palettes and field limits.
    $settings->setAttributes($cpDefaults, false);
    $settings->loadConfig([
        '*' => ['palettes' => [
            'brand' => [
                ['handle' => 'primary', 'label' => 'Primary', 'color' => '#ef730b', 'shades' => ['50' => '#fff7ed']],
                ['handle' => 'rainbow', 'label' => 'Rainbow', 'color' => '#ff0000, #0000ff'],
                ['handle' => 'blue', 'label' => 'Blue', 'color' => '#0d6efd', 'theme' => false],
            ],
            'other' => [['handle' => 'primary', 'label' => 'Other primary', 'color' => '#000000']],
        ]],
        'sites' => ['omeo' => ['palettes' => ['brand' => [
            ['handle' => 'primary', 'label' => 'Primary', 'color' => '#4a665a'],
            ['handle' => 'accent', 'label' => 'Accent', 'color' => 'rebeccapurple'],
        ]]]],
    ]);
    $gen = new ColourSwatches(['handle' => 'gen', 'shades' => '100, 900, subtle', 'opacities' => '50']);
    $generator = new Generator($settings, [$gen], ['default', 'omeo']);
    $gradientWarning = 'Tailwind CSS 4: Rainbow is a gradient, so it has no framework classes. Use swatch.cssColor in an inline style instead.';

    $result = $generator->generate(Frameworks::TAILWIND4, 'default');
    same(['tailwind4.css'], array_keys($result['files']), 'Tailwind 4 output is a CSS file');
    same(['tailwind4' => 39], $result['counts'], 'Tailwind 4 counts every class editors can choose');
    same([$gradientWarning], $result['warnings'], 'Gradients are reported for Tailwind');
    $css = $result['files']['tailwind4.css'];
    same(true, str_contains($css, "@theme {\n    --color-blue: #0d6efd;\n    --color-primary: #ef730b;\n    --color-primary-100: #fce3ce;\n    --color-primary-900: #301702;\n}"), 'Tailwind 4 theme variables include the base colour and chosen shades');
    same(true, str_contains($css, '--color-blue: #0d6efd;') && !str_contains($css, '--color-blue-100'), 'theme => false colours keep Tailwind’s own shades but define the base colour');
    same(false, str_contains($css, 'primary-50:'), 'Shades no field allows are not generated');
    same(true, str_contains($css, '@source inline("{bg,text,border}-blue{,-100,-900}{,/50}");'), 'Tailwind 4 safelists framework colours with brace expansion');
    same(true, str_contains($css, '@source inline("{bg,text,border}-primary{,-100,-900}{,/50}");'), 'Tailwind 4 safelists theme colours');
    same(true, str_contains($css, '@source inline("text-{white,black}");'), 'Tailwind 4 safelists text-bg contrast colours');
    same(true, str_contains($css, '@source inline("opacity-50");'), 'Tailwind 4 safelists element opacity');
    same(false, str_contains($css, 'rainbow'), 'Gradients are left out of framework files');

    $result = $generator->generate(Frameworks::TAILWIND3, 'omeo');
    same([], $result['warnings'], 'Named colours generate without warnings');
    same(['tailwind3' => 27], $result['counts'], 'Tailwind 3 counts its safelist');
    $js = $result['files']['tailwind3.cjs'];
    same(true, str_starts_with($js, '// Generated by Multisite Colour Swatches.'), 'Generated files have a header');
    same(1, preg_match('/^module\.exports = (.*);$/ms', $js, $match), 'Tailwind 3 output is a CommonJS preset');
    $preset = Json::decode($match[1]);
    same(['accent' => ['DEFAULT' => 'rebeccapurple'], 'primary' => ['DEFAULT' => '#4a665a', 100 => '#dbe0de', 900 => '#0f1412']], $preset['theme']['extend']['colors'], 'Tailwind 3 extends colours with the site palette');
    same([50 => '0.5'], $preset['theme']['extend']['opacity'], 'Tailwind 3 extends the opacity scale');
    same(27, count($preset['safelist']), 'Tailwind 3 safelists every class');
    same(true, in_array('border-primary-900/50', $preset['safelist'], true) && in_array('text-accent/50', $preset['safelist'], true), 'Tailwind 3 safelists shade and opacity combinations');

    // Optional generation is how Bootstrap builds get shade classes, so declare the shades the build will provide.
    $settings->customShades = ['bootstrap5' => ['100', '900', 'subtle']];
    $result = $generator->generate(Frameworks::BOOTSTRAP5, 'default');
    $settings->customShades = [];
    same(['bootstrap5' => 30], $result['counts'], 'Bootstrap counts its utility classes');
    $scss = $result['files']['bootstrap5.scss'];
    same(true, str_contains($scss, '"primary": string.unquote("rgb(239 115 11 / var(--#{$prefix}bg-opacity, 1))"),'), 'Bootstrap colours support its opacity variables');
    same(true, str_contains($scss, "@use \"sass:map\";\n@use \"sass:string\";\n") && str_contains($scss, '$utilities: map.merge('), 'Bootstrap output uses Sass modules instead of deprecated global functions');
    same(false, str_contains($scss, '"blue":'), 'theme => false colours keep Bootstrap’s own base classes');
    same(1, substr_count($scss, '"blue-subtle"'), 'Native subtle classes of framework colours are not redefined');
    same(true, str_contains($scss, ".text-bg-primary-100 {\n    color: #000000 !important;"), 'Bootstrap text-bg classes use contrasting text');
    same(true, str_contains($scss, "class: bg-opacity,\n            values: (50: 0.5),"), 'Bootstrap opacity utilities include the field opacities');
    same(true, strpos($scss, '"mcs-bg-color"') < strpos($scss, '"mcs-bg-opacity"'), 'Opacity utilities follow colour utilities so they win');

    $result = $generator->generate(Frameworks::PLUGIN, 'default');
    same([], $result['warnings'], 'Plugin CSS supports gradients');
    same(['plugin' => 32], $result['counts'], 'Plugin CSS counts its rules');
    $css = $result['files']['plugin.css'];
    same(true, str_contains($css, PluginCss::PROPERTIES), 'Plugin CSS registers its opacity properties');
    same(true, str_contains($css, '.mcs-bg-rainbow{background-image:linear-gradient(to bottom right,rgb(255 0 0 / var(--mcs-bg-opacity, 1)),rgb(0 0 255 / var(--mcs-bg-opacity, 1)))}'), 'Plugin CSS includes gradients');
    same(true, str_contains($css, '.mcs-text-bg-blue-900{color:#ffffff;background-color:rgb(3 22 51 / var(--mcs-bg-opacity, 1))}'), 'Plugin CSS includes text-bg shades');
    same(true, str_contains($css, '.mcs-opacity-50{opacity:0.5}'), 'Plugin CSS includes element opacity');

    $result = $generator->generate(Frameworks::TAILWIND4);
    same([$gradientWarning, 'Tailwind CSS 4: "primary" has different colours on sites default and omeo. The first is used; give one swatch a distinct "token" in the config, or generate each site separately with --site.'],
        $result['warnings'], 'Tokens with different colours on different sites are reported');
    same(true, str_contains($result['files']['tailwind4.css'], '--color-accent: rebeccapurple;') && str_contains($result['files']['tailwind4.css'], '--color-primary: #ef730b;'), 'All sites are combined and the first colour wins');
    $result = (new Generator($settings, [$gen, new ColourSwatches(['handle' => 'other', 'palette' => 'other'])], ['default']))->generate(Frameworks::TAILWIND4);
    same(true, in_array('Tailwind CSS 4: "primary" has different colours in two palettes on site default. The first is used; give one swatch a distinct "token" in the config, or generate each site separately with --site.', $result['warnings'], true), 'Tokens with different colours in two palettes are reported');
    $result = (new Generator($settings, [$gen, new ColourSwatches(['handle' => 'wide', 'allowShades' => false])], ['default']))->generate(Frameworks::TAILWIND4);
    same(true, str_contains($result['files']['tailwind4.css'], '-primary{,-100,-900}{,/0,/5,/10,/15,'), 'Fields sharing a palette merge their shades and opacities');
    same([$gradientWarning], $result['warnings'], 'Fields sharing a palette do not conflict');
    $odd = new ColourSwatches(['handle' => 'odd', 'useConfigFile' => false, 'options' => [['handle' => '!!!', 'label' => 'Symbols', 'color' => '#000000']]]);
    $broken = new ColourSwatches(['handle' => 'broken', 'useConfigFile' => false, 'options' => [['label' => 'Broken']]]);
    $result = (new Generator($settings, [$odd, $broken], ['default']))->generate(Frameworks::PLUGIN);
    same('Symbols has no usable class name. Add a "token" to the swatch config.', $result['warnings'][0] ?? null, 'Swatches without a class name are reported');
    same(true, str_starts_with($result['warnings'][1] ?? '', 'broken: '), 'Broken fields are reported without stopping generation');
    same([], $result['files'], 'No files are generated when no swatch has a usable class name');
    same(['files' => [], 'counts' => [], 'warnings' => []], (new Generator($settings, [], ['default']))->generate(), 'No files are generated without fields');
    rejects(fn() => $generator->generate('bulma'), 'Unknown frameworks are rejected');
    rejects(fn() => $generator->generate(null, 'nowhere'), 'Unknown sites are rejected');

    $settings->setAttributes(['customShades' => ['tailwind4' => ['975']], 'safelistUtilities' => ['bg']], false);
    $result = (new Generator($settings, [new ColourSwatches(['handle' => 'all', 'opacities' => '50'])], ['default']))->generate(Frameworks::TAILWIND4);
    $css = $result['files']['tailwind4.css'];
    same(true, str_contains($css, '--color-primary-50: #fff7ed;'), 'Exact configured shades are used in theme files');
    same(true, str_contains($css, '--color-blue-975: #01060d;') && !str_contains($css, '--color-blue-100'), 'theme => false colours only add shades the framework lacks');
    same(true, str_contains($css, '@source inline("bg-blue{,-50,-100,-200,-300,-400,-500,-600,-700,-800,-900,-950,-975}{,/50}");'), 'Only the chosen utilities are safelisted');
    same(false, str_contains($css, 'text-{white,black}') || str_contains($css, 'opacity-50"'), 'Unused text-bg and opacity utilities are left out');
    $settings->setAttributes(['safelistUtilities' => ['text']], false);
    $scss = $generator->generate(Frameworks::BOOTSTRAP5, 'default')['files']['bootstrap5.scss'];
    same([false, true, true, false], [str_contains($scss, '"mcs-bg-color"'), str_contains($scss, '"mcs-text-color"'), str_contains($scss, '"mcs-text-opacity"'), str_contains($scss, '.text-bg-')], 'Bootstrap output follows the chosen utilities');
    $settings->setAttributes(['customShades' => [], 'safelistUtilities' => Frameworks::UTILITIES, 'framework' => Frameworks::TAILWIND4, 'siteFrameworks' => ['omeo' => Frameworks::BOOTSTRAP5]], false);

    Craft::$app->fields->fields = [$gen];
    $result = (new Generator($settings))->generate();
    same(['bootstrap5.scss', 'tailwind4.css'], array_keys($result['files']), 'Each site uses its own framework by default');
    same(true, str_contains($result['files']['bootstrap5.scss'], '"accent": string.unquote("color-mix(in srgb, rebeccapurple calc(var(--#{$prefix}bg-opacity, 1) * 100%), transparent)"),'), 'Named colours use color-mix() for Bootstrap opacity');
    same(false, str_contains($result['files']['tailwind4.css'], 'accent'), 'Site frameworks only include their own sites');

    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcs-' . bin2hex(random_bytes(4));
    try {
        $written = Generator::write(['tailwind4.css' => 'a', 'bootstrap5.scss' => 'b'], $directory . '/nested', 'omeo');
        same([$directory . '/nested' . DIRECTORY_SEPARATOR . 'tailwind4-omeo.css', $directory . '/nested' . DIRECTORY_SEPARATOR . 'bootstrap5-omeo.scss'], $written, 'Site files get the site handle as a suffix');
        same(['a', 'b'], [file_get_contents($written[0]), file_get_contents($written[1])], 'Generated files are written');
        same([$directory . DIRECTORY_SEPARATOR . 'plugin.css'], Generator::write(['plugin.css' => 'c'], $directory . '/'), 'Files without a site keep their names');

        // The console command: php craft multisite-colour-swatches/generate
        $console = static function(array $options) use ($plugin): array {
            $controller = new class('generate', $plugin, ['request' => Craft::$app->request, 'response' => new yii\console\Response()]) extends GenerateController {
                public string $out = '';
                public string $err = '';

                public function stdout($string)
                {
                    $this->out .= $string;
                    return strlen($string);
                }

                public function stderr($string)
                {
                    $this->err .= $string;
                    return strlen($string);
                }
            };
            same(['color', 'interactive', 'help', 'silentExitOnException', 'isolated', 'output', 'framework', 'site'], $controller->options('index'), 'The command accepts --output, --framework and --site');
            foreach ($options as $option => $value) {
                $controller->$option = $value;
            }
            return [$controller->actionIndex(), $controller->out, $controller->err];
        };
        [$code, $out, $err] = $console(['output' => "$directory/cli", 'site' => 'default']);
        same(ExitCode::OK, $code, 'The command succeeds');
        same('Wrote ' . "$directory/cli" . DIRECTORY_SEPARATOR . 'tailwind4-default.css' . PHP_EOL, $out, 'The command lists the files it wrote');
        same('Warning: ' . $gradientWarning . PHP_EOL, $err, 'The command prints warnings');
        same(true, str_contains((string)file_get_contents("$directory/cli/tailwind4-default.css"), '--color-primary: #ef730b;'), 'The command writes the generated CSS');
        [$code, $out] = $console(['output' => "$directory/cli", 'framework' => Frameworks::PLUGIN]);
        same([ExitCode::OK, 'Wrote ' . "$directory/cli" . DIRECTORY_SEPARATOR . 'plugin.css' . PHP_EOL], [$code, $out], '--framework generates one framework for every site');
        [$code, , $err] = $console(['framework' => 'bulma']);
        same([ExitCode::USAGE, true], [$code, str_starts_with($err, 'Unknown framework "bulma"')], 'Unknown frameworks are usage errors');
        [$code, , $err] = $console(['site' => 'nowhere']);
        same([ExitCode::USAGE, 'Unknown site "nowhere".' . PHP_EOL], [$code, $err], 'Unknown sites are usage errors');
        // Craft's error handler turns mkdir() warnings into exceptions.
        set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            [$code, , $err] = $console(['output' => "$directory/plugin.css/blocked"]);
        } finally {
            restore_error_handler();
        }
        same([ExitCode::IOERR, true], [$code, str_contains($err, 'Could not write the files: ')], 'Write failures are reported');
        Craft::$app->fields->fields = [];
        [$code, $out] = $console(['output' => "$directory/empty"]);
        same([ExitCode::OK, 'No Multisite Colour Swatches fields use these palettes yet, so there is nothing to generate.' . PHP_EOL], [$code, $out], 'Projects without fields have nothing to generate');
        same(false, is_dir("$directory/empty"), 'No directory is created when there is nothing to generate');
    } finally {
        craft\helpers\FileHelper::removeDirectory($directory);
    }

    // The plugin settings page.
    Craft::$app->fields->fields = [$gen];
    $settings->setAttributes(['customOpacities' => ['bootstrap5' => '5, 15']], false);
    $settingsHtml = new ReflectionMethod($plugin, 'settingsHtml');
    $page = $settingsHtml->invoke($plugin);
    same(true, str_contains($page, '<select name="framework"><option value="tailwind3">Tailwind CSS 3</option><option value="tailwind4" selected>Tailwind CSS 4</option><option value="bootstrap5">Bootstrap 5.3</option><option value="plugin">Plugin CSS (no build step)</option></select>'), 'The settings page offers Tailwind 3, Tailwind 4, Bootstrap 5 and plugin CSS');
    same(1, preg_match('/name="siteFrameworks\[omeo\]">(.*?)<\/select>/', $page, $siteSelect), 'Each site can override the framework');
    same(true, str_contains($siteSelect[1], '<option value="bootstrap5" selected>'), 'Site framework overrides are selected');
    same(true, str_contains($page, 'name="siteFrameworks[default]"><option value="" selected>Use the framework above</option>'), 'Sites without an override use the global framework');
    same(4, substr_count($page, 'name="customShades['), 'Every framework has an additional shades setting');
    same(true, str_contains($page, 'name="customOpacities[bootstrap5]" value="5, 15"'), 'Additional opacities are shown for each framework');
    same(5, substr_count($page, 'name="safelistUtilities[]"'), 'Every utility can be chosen');
    same(5, preg_match_all('/name="safelistUtilities\[\]" value="\w+" checked/', $page), 'Every utility is generated by default');
    same(true, str_contains($page, '<input name="registerCss" type="checkbox" checked>'), 'Automatic CSS registration can be turned off');
    same(true, str_contains($page, 'name="outputPath" value="@root/multisite-colour-swatches"'), 'The output path is editable');
    same(1, preg_match('/<strong>bootstrap5\.scss<\/strong> · Bootstrap 5\.3 ·\s+12 classes/', $page), 'Generated Bootstrap files are previewed with a class count, without shades by default');
    same(true, str_contains($page, '<details id="advanced-generation">') && strpos($page, 'Optional: generate stylesheet files') < strpos($page, 'name="safelistUtilities[]"'), 'Stylesheet generation is an optional, collapsed section');
    same(true, strpos($page, 'name="registerCss"') < strpos($page, '<details id="advanced-generation">'), 'Plugin CSS registration stays outside the optional section');
    same(true, str_contains($page, '<code>entry.colour.attrs(&#039;bg&#039;)</code>') || str_contains($page, "<code>entry.colour.attrs('bg')</code>"), 'Tailwind inline fallbacks are explained');
    same(1, preg_match('/<strong>tailwind4\.css<\/strong> · Tailwind CSS 4 ·\s+39 classes/', $page), 'Generated Tailwind files are previewed with a class count');
    same(false, str_contains($page, 'tailwind3.cjs</strong>') || str_contains($page, 'plugin.css</strong>'), 'Frameworks no site uses are not previewed');
    same(true, str_contains($page, '@source inline(&quot;{bg,text,border}-primary{,-100,-900}{,/50}&quot;);'), 'File previews contain the generated code');
    same(true, str_contains($page, '<p class="warning with-icon">' . $gradientWarning . '</p>'), 'Generator warnings are shown on the settings page');
    same([true, false], [str_contains($page, '<code>@import "bootstrap/scss/bootstrap"</code>'), str_contains($page, 'tailwind3.cjs\')]</code>')], 'Usage instructions are shown only for previewed files');

    $settings->loadConfig(['*' => ['framework' => Frameworks::PLUGIN, 'registerCss' => false, 'palettes' => ['brand' => [['handle' => 'primary', 'label' => 'Primary', 'color' => '#ef730b']]]]]);
    $page = $settingsHtml->invoke($plugin);
    $configWarning = '<p class="warning">This is set in config/multisite-colour-swatches.php.</p>';
    same(true, str_contains($page, '<select name="framework" disabled>'), 'Settings set in the config file cannot be edited');
    same(true, str_contains($page, '<input name="registerCss" type="checkbox" disabled>' . $configWarning), 'Config file settings show their source');
    same(false, str_contains($page, 'name="siteFrameworks[omeo]" disabled'), 'Settings missing from the config remain editable');
    same(1, preg_match('/<strong>plugin\.css<\/strong> · Plugin CSS \(no build step\) ·\s+\d+ classes/', $page), 'Previews use config file settings');

    $settings->loadConfig($fixture);
    $settings->setAttributes(['customShades' => ['tailwind4' => 'Bad_Shade'], 'outputPath' => ''], false);
    $settings->validate();
    $page = $settingsHtml->invoke($plugin);
    same(true, str_contains($page, '<li>Tailwind CSS 4: &quot;bad_shade&quot;: A shade can contain only lowercase letters, numbers and single hyphens.</li>'), 'Shade and opacity errors are listed');
    same(true, str_contains($page, 'name="customShades[tailwind4]" value="bad_shade"'), 'Invalid posted values are shown for correction');
    same(true, str_contains($page, 'name="outputPath" value=""><p class="error">'), 'Output path errors are shown');
    $settings->clearErrors();

    Craft::$app->fields->fields = [];
    same(true, str_contains($settingsHtml->invoke($plugin), 'Add a Multisite Colour Swatches field to see the generated files.'), 'Projects without fields are told how to see generated files');
    $fieldsService = Craft::$app->fields;
    Craft::$app->fields = new class extends TestFields {
        public function getAllFields(mixed $context = null): array
        {
            throw new RuntimeException('Fields are unavailable.');
        }
    };
    try {
        same(true, str_contains($settingsHtml->invoke($plugin), '<p class="error">Fields are unavailable.</p>'), 'Generator errors do not break the settings page');
    } finally {
        Craft::$app->fields = $fieldsService;
    }

    // Leave run.php with the control panel settings and fixture it started with.
    $settings->loadConfig($fixture);
    $settings->setAttributes($cpDefaults, false);
    $settings->clearErrors();
    Craft::$app->fields->fields = [];
    Craft::$app->request->site = false;
    Craft::$app->view->css = [];
})();
