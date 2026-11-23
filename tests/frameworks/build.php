<?php

declare(strict_types=1);

use craft\helpers\Json;
use walkerdesigns\multisitecolourswatches\fields\ColourSwatches;
use walkerdesigns\multisitecolourswatches\helpers\Frameworks;
use walkerdesigns\multisitecolourswatches\helpers\Generator;

// Writes generated framework files and every class editors can output, for compile.js. Included by run.php.
(static function(string $directory) use ($settings, $fixture): void {
    $accent = ['handle' => 'accent', 'label' => 'Accent', 'color' => 'rebeccapurple'];
    $rainbow = ['handle' => 'rainbow', 'label' => 'Rainbow', 'color' => '#ff0000, #0000ff'];
    $settings->loadConfig(['sites' => [
        // Tailwind: "blue" is one of Tailwind's colours, so it keeps Tailwind's own shades.
        'default' => ['palettes' => ['brand' => [
            ['handle' => 'brandOrange', 'label' => 'Brand orange', 'color' => '#ef730b', 'token' => 'brand-orange', 'shades' => ['50' => '#fff7ed']],
            ['handle' => 'blue', 'label' => 'Blue', 'color' => '#0d6efd', 'theme' => false],
            $accent,
            $rainbow,
        ]]],
        // Bootstrap: "primary" is one of Bootstrap's theme colours, so its native classes are kept.
        'omeo' => ['palettes' => ['brand' => [
            ['handle' => 'primary', 'label' => 'Primary', 'color' => '#0d6efd', 'theme' => false],
            ['handle' => 'brandGreen', 'label' => 'Brand green', 'color' => '#4a665a', 'token' => 'brand-green'],
            $accent,
            $rainbow,
        ]]],
    ]]);
    // Bootstrap here simulates the optional generated file adding shades and opacities to the build.
    $settings->setAttributes([
        'customShades' => ['tailwind3' => ['975'], 'tailwind4' => ['975'], 'bootstrap5' => ['50', '100', '900', 'subtle']],
        'customOpacities' => ['tailwind3' => [33], 'tailwind4' => [33], 'bootstrap5' => [0, 5, 15]],
    ], false);

    $field = new ColourSwatches(['handle' => 'brand']);
    $writeClasses = static function(string $framework, string $site, string $name, bool $buttons = true) use ($field, $directory): void {
        $classes = [];
        foreach ($field->getOptionsForSite($site) as $option) {
            if ($framework !== Frameworks::PLUGIN && $option->isGradient()) {
                continue;
            }
            foreach ([null, ...$field->shadeOptionsForFramework($framework, $option)] as $shade) {
                foreach ([null, ...$field->opacityOptionsForFramework($framework)] as $opacity) {
                    $selection = $option->withSelection($shade, $opacity, $framework);
                    foreach ($buttons ? [...Frameworks::UTILITIES, ...Frameworks::BUTTONS] : Frameworks::UTILITIES as $utility) {
                        foreach (preg_split('/\s+/', $selection->utility($utility), -1, PREG_SPLIT_NO_EMPTY) as $class) {
                            $classes[$class] = true;
                        }
                    }
                }
            }
        }
        $json = Json::encode(array_keys($classes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents("$directory/classes-$name.json", $json) === false) {
            throw new RuntimeException("Unable to write the $name class list.");
        }
        echo "Framework fixture $name: " . count($classes) . " classes\n";
    };
    $generator = new Generator($settings, [$field], ['default', 'omeo']);
    $targets = [Frameworks::TAILWIND3 => 'default', Frameworks::TAILWIND4 => 'default', Frameworks::BOOTSTRAP5 => 'omeo', Frameworks::PLUGIN => 'default'];
    foreach ($targets as $framework => $site) {
        Generator::write($generator->generate($framework, $site)['files'], $directory);
        // The optional generator only adds utilities; Bootstrap button classes come from $theme-colors (checked in the stock build).
        $writeClasses($framework, $site, $framework, $framework !== Frameworks::BOOTSTRAP5);
    }

    // Out of the box: with no generated file, every class must already exist in a normal Bootstrap build.
    $settings->setAttributes(['customShades' => [], 'customOpacities' => []], false);
    $writeClasses(Frameworks::BOOTSTRAP5, 'omeo', 'bootstrap5-stock');

    $settings->loadConfig($fixture);
})($frameworkFixtures);
