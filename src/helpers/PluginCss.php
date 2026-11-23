<?php

namespace walkerdesigns\multisitecolourswatches\helpers;

use Craft;
use walkerdesigns\multisitecolourswatches\MultisiteColourSwatches;

/** Builds the classes used by the "Plugin CSS" framework, which needs no front-end build step. */
final class PluginCss
{
    // Unlike plain custom properties, these do not leak an ancestor's opacity into nested swatches.
    public const PROPERTIES = '@property --mcs-bg-opacity{syntax:"<number>";inherits:false;initial-value:1}'
        . '@property --mcs-text-opacity{syntax:"<number>";inherits:false;initial-value:1}'
        . '@property --mcs-border-opacity{syntax:"<number>";inherits:false;initial-value:1}';

    /** @param string[] $stops One colour, or several gradient colours. */
    public static function colourRule(string $utility, string $name, array $stops): string
    {
        $first = $stops[0];
        return match ($utility) {
            'bg' => ".mcs-bg-$name{" . self::background($stops) . '}',
            'text' => ".mcs-text-$name{color:" . Colour::withAlpha($first, '--mcs-text-opacity') . '}',
            'border' => ".mcs-border-$name{border-color:" . Colour::withAlpha($first, '--mcs-border-opacity') . '}',
            'textBg' => ".mcs-text-bg-$name{color:" . Colour::contrast($first) . ';' . self::background($stops) . '}',
        };
    }

    public static function opacityRule(string $utility, int $opacity): string
    {
        $value = Frameworks::decimal($opacity);
        return $utility === 'opacity'
            ? ".mcs-opacity-$opacity{opacity:$value}"
            : ".mcs-$utility-opacity-$opacity{--mcs-$utility-opacity:$value}";
    }

    /** Registers only the rules a template actually used, keyed so {% cache %} can replay them. */
    public static function register(string $utility, string $name, array $stops, ?int $opacity): void
    {
        $plugin = MultisiteColourSwatches::getInstance();
        if (!$plugin instanceof MultisiteColourSwatches || !$plugin->getPaletteSettings()->registerCss) {
            return;
        }
        $request = Craft::$app->getRequest();
        if (!$request->getIsSiteRequest()) {
            return;
        }
        $view = Craft::$app->getView();
        $view->registerCss(self::PROPERTIES, [], 'mcs-properties');
        if ($utility !== 'opacity') {
            $view->registerCss(self::colourRule($utility, $name, $stops), [], "mcs-$utility-$name");
        }
        if ($opacity !== null) {
            $opacityUtility = $utility === 'textBg' ? 'bg' : $utility;
            $view->registerCss(self::opacityRule($opacityUtility, $opacity), [], "mcs-$opacityUtility-opacity-$opacity");
        }
    }

    private static function background(array $stops): string
    {
        $colors = array_map(static fn(string $color): string => Colour::withAlpha($color, '--mcs-bg-opacity'), $stops);
        return count($colors) === 1
            ? "background-color:$colors[0]"
            : 'background-image:linear-gradient(to bottom right,' . implode(',', $colors) . ')';
    }
}
