<?php

namespace walkerdesigns\multisitecolourswatches\helpers;

final class Colour
{
    public const BLACK = '#000000';
    public const WHITE = '#ffffff';

    /** @return int[]|null RGB channels, or null for CSS colour names. Hex alpha is ignored. */
    public static function rgb(string $value): ?array
    {
        $value = trim($value);
        $hex = substr($value, 1);
        if (!str_starts_with($value, '#') || !preg_match('/^(?:[a-f0-9]{3,4}|[a-f0-9]{6}|[a-f0-9]{8})$/i', $hex)) {
            return null;
        }
        if (strlen($hex) <= 4) {
            $hex = preg_replace('/(.)/', '$1$1', substr($hex, 0, 3));
        }
        return array_map('hexdec', str_split(substr($hex, 0, 6), 2));
    }

    public static function hex(array $rgb): string
    {
        return '#' . implode('', array_map(static fn(float|int $channel): string => sprintf('%02x', max(0, min(255, (int)round($channel)))), $rgb));
    }

    public static function normalize(string $value): string
    {
        $rgb = self::rgb($value);
        return $rgb === null ? strtolower(trim($value)) : self::hex($rgb);
    }

    /** Mixes like Sass mix($with, $color, $weight), which Bootstrap uses for tint-color() and shade-color(). */
    public static function mix(string $color, string $with, float $weight): ?string
    {
        $base = self::rgb($color);
        $other = self::rgb($with);
        if ($base === null || $other === null) {
            return null;
        }
        return self::hex(array_map(static fn(int $a, int $b): float => $b * $weight + $a * (1 - $weight), $base, $other));
    }

    /** Bootstrap's numeric scale: 100 is an 80% tint, 900 an 80% shade; other numbers interpolate. */
    public static function shade(string $color, string $shade): ?string
    {
        if (ctype_digit($shade)) {
            $step = (int)$shade;
            if ($step <= 0 || $step >= 1000) {
                return null;
            }
            if ($step === 500) {
                return self::rgb($color) === null ? null : self::normalize($color);
            }
            return $step < 500
                ? self::mix($color, self::WHITE, (500 - $step) / 500)
                : self::mix($color, self::BLACK, ($step - 500) / 500);
        }
        return match ($shade) {
            // Bootstrap 5.3 derives subtle backgrounds and emphasis text from the same base colour.
            'subtle' => self::mix($color, self::WHITE, .8),
            'emphasis' => self::mix($color, self::BLACK, .6),
            default => null,
        };
    }

    public static function luminance(string $color): ?float
    {
        $rgb = self::rgb($color);
        if ($rgb === null) {
            return null;
        }
        [$r, $g, $b] = array_map(static function(int $channel): float {
            $value = $channel / 255;
            return $value <= .04045 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4;
        }, $rgb);
        return .2126 * $r + .7152 * $g + .0722 * $b;
    }

    public static function contrastRatio(string $a, string $b): ?float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);
        if ($first === null || $second === null) {
            return null;
        }
        return (max($first, $second) + .05) / (min($first, $second) + .05);
    }

    /** Matches Bootstrap's color-contrast(): white first, then black, using a 4.5:1 minimum. */
    public static function contrast(?string $background): string
    {
        if ($background === null || self::rgb($background) === null) {
            return self::BLACK;
        }
        $white = self::contrastRatio($background, self::WHITE);
        $black = self::contrastRatio($background, self::BLACK);
        if ($white >= 4.5) {
            return self::WHITE;
        }
        return $black >= 4.5 || $black > $white ? self::BLACK : self::WHITE;
    }

    public static function alpha(string $color, ?int $opacity): string
    {
        if ($opacity === null || $opacity >= 100) {
            return $color;
        }
        $rgb = self::rgb($color);
        $value = Frameworks::decimal($opacity);
        return $rgb === null
            ? "color-mix(in srgb, $color $opacity%, transparent)"
            : "rgb($rgb[0] $rgb[1] $rgb[2] / $value)";
    }

    /** Returns a CSS colour whose alpha comes from a custom property, with a fallback of 1. */
    public static function withAlpha(string $color, string $property): string
    {
        $rgb = self::rgb($color);
        return $rgb === null
            ? "color-mix(in srgb, $color calc(var($property, 1) * 100%), transparent)"
            : "rgb($rgb[0] $rgb[1] $rgb[2] / var($property, 1))";
    }
}
