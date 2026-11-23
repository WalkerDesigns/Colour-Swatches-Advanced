<?php

namespace walkerdesigns\multisitecolourswatches\helpers;

use Craft;
use yii\base\InvalidArgumentException;

final class Frameworks
{
    public const TAILWIND3 = 'tailwind3';
    public const TAILWIND4 = 'tailwind4';
    public const BOOTSTRAP5 = 'bootstrap5';
    public const PLUGIN = 'plugin';
    public const ALL = [self::TAILWIND3, self::TAILWIND4, self::BOOTSTRAP5, self::PLUGIN];

    public const UTILITIES = ['bg', 'text', 'border', 'textBg', 'opacity'];
    /** Button variants: Bootstrap's btn-*, btn-outline-* and btn-link classes, or matching utilities elsewhere. */
    public const BUTTONS = ['button', 'buttonOutline', 'buttonLink'];
    public const SHADE_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::TAILWIND3 => Craft::t('multisite-colour-swatches', 'Tailwind CSS 3'),
            self::TAILWIND4 => Craft::t('multisite-colour-swatches', 'Tailwind CSS 4'),
            self::BOOTSTRAP5 => Craft::t('multisite-colour-swatches', 'Bootstrap 5.3'),
            self::PLUGIN => Craft::t('multisite-colour-swatches', 'Plugin CSS (no build step)'),
        ];
    }

    /**
     * Bootstrap compiles no numeric or -subtle shade classes for custom theme colours, so it has no default shades;
     * add shades in the plugin settings only when your own build provides those classes.
     *
     * @return string[]
     */
    public static function defaultShades(string $framework): array
    {
        if ($framework === self::BOOTSTRAP5) {
            return [];
        }
        return ['50', ...array_map('strval', range(100, 900, 100)), '950'];
    }

    /**
     * Bootstrap defaults match the bg-opacity-* and border-opacity-* classes in a stock Bootstrap 5.3 build.
     *
     * @return int[]
     */
    public static function defaultOpacities(string $framework): array
    {
        return match ($framework) {
            self::BOOTSTRAP5 => [10, 25, 50, 75, 100],
            self::PLUGIN => range(0, 100, 10),
            default => range(0, 100, 5),
        };
    }

    public static function isTailwind(string $framework): bool
    {
        return $framework === self::TAILWIND3 || $framework === self::TAILWIND4;
    }

    public static function normalizeUtility(string $type): string
    {
        return match (strtolower(str_replace(['-', '_', ' '], '', $type))) {
            'bg', 'background' => 'bg',
            'text', 'color', 'colour' => 'text',
            'border' => 'border',
            'textbg' => 'textBg',
            'opacity' => 'opacity',
            'button', 'btn', 'buttonsolid', 'solid' => 'button',
            'buttonoutline', 'btnoutline', 'outline' => 'buttonOutline',
            'buttonlink', 'btnlink', 'buttonunderline', 'link', 'underline' => 'buttonLink',
            default => throw new InvalidArgumentException("Unknown utility type \"$type\". Use bg, text, border, textBg, opacity, button, buttonOutline or buttonLink."),
        };
    }

    public static function isButton(string $utility): bool
    {
        return in_array($utility, self::BUTTONS, true);
    }

    /**
     * Frameworks without button components style buttons with their colour utilities.
     *
     * @return string[]
     */
    public static function buttonParts(string $utility): array
    {
        return match ($utility) {
            'button' => ['textBg'],
            'buttonOutline' => ['border', 'text'],
            'buttonLink' => ['text'],
            default => [$utility],
        };
    }

    /** Numeric shades are sorted numerically, followed by named shades in their configured order. */
    public static function sortShades(array $shades): array
    {
        $numeric = [];
        $named = [];
        foreach ($shades as $shade) {
            $shade = (string)$shade;
            if (ctype_digit($shade)) {
                $numeric[(int)$shade] = $shade;
            } else {
                $named[$shade] = $shade;
            }
        }
        ksort($numeric);
        return [...array_values($numeric), ...array_values($named)];
    }

    public static function name(string $token, ?string $shade): string
    {
        return $shade === null ? $token : "$token-$shade";
    }

    /** Returns the class list for one utility, matching each framework's naming and opacity conventions. */
    public static function classes(string $framework, string $utility, string $token, ?string $shade, ?int $opacity, string $contrast): string
    {
        $utility = self::normalizeUtility($utility);
        $prefix = $framework === self::PLUGIN ? 'mcs-' : '';
        if ($utility === 'opacity') {
            return $opacity === null ? '' : "{$prefix}opacity-$opacity";
        }
        if ($token === '') {
            return '';
        }
        $name = self::name($token, $shade);
        if (self::isButton($utility)) {
            if ($framework !== self::BOOTSTRAP5) {
                return implode(' ', array_filter(array_map(
                    static fn(string $part): string => self::classes($framework, $part, $token, $shade, $opacity, $contrast),
                    self::buttonParts($utility),
                )));
            }
            // Bootstrap only has button classes for $theme-colors, so shades and opacity go in Swatch::style() as --bs-btn-* variables.
            return match ($utility) {
                'button' => "btn-$token",
                'buttonOutline' => "btn-outline-$token",
                'buttonLink' => "btn-link text-$name" . ($opacity === null ? '' : " text-opacity-$opacity"),
            };
        }
        if (self::isTailwind($framework)) {
            $suffix = $opacity === null ? '' : "/$opacity";
            return $utility === 'textBg'
                ? "bg-$name$suffix text-" . ($contrast === Colour::WHITE ? 'white' : 'black')
                : "$utility-$name$suffix";
        }
        $class = $prefix . ($utility === 'textBg' ? 'text-bg' : $utility) . "-$name";
        if ($opacity !== null) {
            $class .= ' ' . $prefix . ($utility === 'textBg' ? 'bg' : $utility) . "-opacity-$opacity";
        }
        return $class;
    }

    public static function decimal(int $opacity): string
    {
        return rtrim(rtrim(sprintf('%.2F', $opacity / 100), '0'), '.') ?: '0';
    }

    public static function parseShade(mixed $shade): ?string
    {
        if ($shade === null || $shade === '') {
            return null;
        }
        if (is_int($shade)) {
            $shade = (string)$shade;
        }
        if (!is_string($shade) || !preg_match(self::SHADE_PATTERN, $shade)) {
            throw new InvalidArgumentException('A shade can contain only lowercase letters, numbers and single hyphens.');
        }
        return $shade;
    }

    public static function parseOpacity(mixed $opacity): ?int
    {
        if ($opacity === null || $opacity === '') {
            return null;
        }
        if (is_string($opacity) && ctype_digit($opacity)) {
            $opacity = (int)$opacity;
        }
        if (!is_int($opacity) || $opacity < 0 || $opacity > 100) {
            throw new InvalidArgumentException('An opacity must be a whole number from 0 to 100.');
        }
        return $opacity;
    }

    /** Splits comma/space-separated settings text into a list, preserving arrays from project config. */
    public static function splitList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [];
        }
        $result = [];
        foreach (is_array($value) ? $value : preg_split('/[\s,]+/', (string)$value) as $item) {
            if (is_int($item)) {
                $item = (string)$item;
            }
            if (is_string($item)) {
                $item = strtolower(trim($item));
            }
            if ($item !== '' && $item !== null && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /** Like splitList(), but converts whole-number strings to integers for opacity settings. */
    public static function opacityList(mixed $value): array
    {
        $result = [];
        foreach (self::splitList($value) as $item) {
            $item = is_string($item) && ctype_digit($item) ? (int)$item : $item;
            if (!in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result;
    }
}
