<?php

$common = [
    'white' => '#ffffff',
    'gray-300' => '#dee2e6',
    'gray-700' => '#495057',
    'gray-900' => '#000000',
];

$swatches = static fn(array $colors): array => array_map(
    static fn(string $handle, string $hex): array => [
        'handle' => $handle,
        'label' => ucfirst(str_replace('-', ' ', $handle)),
        'default' => false,
        'color' => [[
            'color' => $hex,
            'background' => 'bg-' . $handle,
            'text' => 'text-' . $handle,
            'button' => 'btn-' . $handle,
            'buttonOutline' => 'btn-outline-' . $handle,
            'buttonUnderline' => 'btn-link text-' . $handle,
        ]],
    ],
    array_keys($colors),
    array_values($colors),
);

return [
    // Optional. Values set here replace the plugin settings page and are locked there.
    // 'framework' => 'tailwind4', // tailwind3, tailwind4, bootstrap5 or plugin
    // 'siteFrameworks' => ['siteB' => 'bootstrap5'],
    // 'customShades' => ['tailwind4' => ['975']],
    // 'customOpacities' => ['bootstrap5' => [5, 15]],
    // 'safelistUtilities' => ['bg', 'text', 'border', 'textBg', 'opacity'],
    // 'registerCss' => true,
    // 'outputPath' => '@root/multisite-colour-swatches',

    // Swatches may also set 'token' (class name), 'shades' (exact shade colours, or false)
    // and 'theme' => false for colours the framework already defines. See the README.
    'palettes' => [
        'brand' => $swatches($common),
    ],
    'sites' => [
        // Use Craft site handles, not names, languages or environment names.
        'default' => [
            'palettes' => [
                'brand' => $swatches(array_replace($common, ['orange' => '#ef730b'])),
            ],
        ],
        'siteA' => [
            'palettes' => [
                'brand' => $swatches(array_replace($common, ['orange' => '#f37b4d', 'green' => '#4a665a'])),
            ],
        ],
        'siteB' => [
            'palettes' => [
                'brand' => $swatches(array_replace($common, [
                    'blue' => '#1c394d',
                    'beige' => '#d6cecc',
                ])),
            ],
        ],
    ],
];
