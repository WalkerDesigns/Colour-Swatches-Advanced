<?php

return [
    '*' => [
        'palettes' => [
            'brand' => [
                ['handle' => 'primary', 'label' => 'Primary', 'color' => [['color' => '#ef730b', 'background' => 'bg-primary']]],
            ],
            'buttons' => [
                ['handle' => 'button', 'label' => 'Button', 'color' => '#000000'],
            ],
        ],
    ],
    'sites' => [
        'omeo' => [
            'palettes' => [
                'brand' => [
                    ['handle' => 'primary', 'label' => 'Primary', 'color' => [['color' => '#4a665a', 'background' => 'bg-green']]],
                    ['handle' => 'orange', 'label' => 'Orange', 'color' => '#f37b4d'],
                ],
                'siteOnly' => [
                    ['handle' => 'exclusive', 'label' => 'Exclusive', 'color' => '#123456'],
                ],
            ],
        ],
        'empty' => ['palettes' => ['brand' => []]],
    ],
];
