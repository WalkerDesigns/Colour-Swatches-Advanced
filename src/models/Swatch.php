<?php

namespace walkerdesigns\multisitecolourswatches\models;

use Craft;
use craft\base\Model;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use Illuminate\Support\Collection;
use Twig\Markup;
use walkerdesigns\multisitecolourswatches\helpers\Colour;
use walkerdesigns\multisitecolourswatches\helpers\Frameworks;
use walkerdesigns\multisitecolourswatches\helpers\PluginCss;
use walkerdesigns\multisitecolourswatches\MultisiteColourSwatches;
use yii\base\InvalidConfigException;

class Swatch extends Model
{
    private const COLOUR_PATTERN = '/^(?:#[a-f0-9]{3,4}|#[a-f0-9]{6}|#[a-f0-9]{8}|[a-z]+)$/i';
    private const SERIALIZED = ['handle', 'label', 'color', 'class', 'default', 'shade', 'opacity'];

    public string $handle = '';
    public string $label = '';
    public array|string $color = [];
    public string $class = '';
    public bool $default = false;
    public ?string $shade = null;
    public ?int $opacity = null;

    // Configuration context; deliberately private so it is never saved with an element.
    private string $token = '';
    /** @var array<string, string>|false */
    private array|false $shadeMap = [];
    private bool $themeColour = true;
    private ?string $framework = null;

    public static function fromValue(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        // The old Brand Colour Swatches field could JSON-encode an already serialized value.
        for ($i = 0; $i < 2 && is_string($value); $i++) {
            $value = Json::decode($value);
        }
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidConfigException('A colour selection must be a swatch object.');
        }
        // Token, shade map and theme flags are trusted only from the palette configuration.
        $swatch = self::fromArray(array_diff_key($value, ['token' => true, 'shades' => true, 'theme' => true]));
        if (($value['handle'] ?? '') === '') {
            $swatch->handle = '';
        }
        $swatch->shade = Frameworks::parseShade($value['shade'] ?? null);
        $swatch->opacity = Frameworks::parseOpacity($value['opacity'] ?? null);
        return $swatch;
    }

    /** Creates a palette option. Editor selections such as shade and opacity are ignored here. */
    public static function fromArray(array $data): self
    {
        if (!isset($data['label']) || !is_string($data['label']) || trim($data['label']) === '') {
            throw new InvalidConfigException('Each colour swatch needs a non-empty label.');
        }
        $handle = ($data['handle'] ?? '') !== '' ? $data['handle'] : StringHelper::toCamelCase($data['label']);
        if (!is_string($handle) || $handle === '') {
            throw new InvalidConfigException('Each colour swatch needs a non-empty handle.');
        }
        $color = $data['color'] ?? $data['colour'] ?? null;
        if ((!is_array($color) && !is_string($color)) || $color === [] || $color === '') {
            throw new InvalidConfigException("Swatch $handle needs a color or a list of colors.");
        }
        if (isset($data['class']) && !is_string($data['class'])) {
            throw new InvalidConfigException("Swatch $handle has an invalid CSS class.");
        }
        $default = filter_var($data['default'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($default === null) {
            throw new InvalidConfigException("Swatch $handle has an invalid default flag.");
        }
        $token = $data['token'] ?? null;
        if ($token !== null && (!is_string($token) || !preg_match(Frameworks::TOKEN_PATTERN, $token))) {
            throw new InvalidConfigException("Swatch $handle has an invalid token. Use letters, numbers, underscores and single hyphens.");
        }
        $theme = filter_var($data['theme'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($theme === null) {
            throw new InvalidConfigException("Swatch $handle has an invalid theme flag.");
        }
        $swatch = new self([
            'handle' => $handle,
            'label' => $data['label'],
            'color' => $color,
            'class' => $data['class'] ?? '',
            'default' => $default,
        ]);
        $swatch->token = $token ?? self::defaultToken($handle);
        $swatch->shadeMap = self::parseShadeMap($data['shades'] ?? [], $handle);
        $swatch->themeColour = $theme;
        $swatch->getStops();
        return $swatch;
    }

    public function fields(): array
    {
        return array_combine(self::SERIALIZED, self::SERIALIZED);
    }

    /** Returns a copy with an editor selection and the framework of the element's site. */
    public function withSelection(?string $shade, ?int $opacity, ?string $framework = null): self
    {
        $swatch = clone $this;
        $swatch->shade = $shade;
        $swatch->opacity = $opacity;
        $swatch->framework = $framework ?? $this->framework;
        return $swatch;
    }

    /** @return string[] The configured colour, or each colour of a gradient. */
    public function getStops(): array
    {
        $colors = is_array($this->color) ? $this->color : preg_split('/[;,]/', $this->color);
        $values = [];
        foreach ($colors as $color) {
            $value = is_array($color) ? ($color['color'] ?? $color['colour'] ?? null) : $color;
            if (!is_string($value) || !preg_match(self::COLOUR_PATTERN, trim($value))) {
                throw new InvalidConfigException("Swatch $this->handle needs hex colours or CSS colour names.");
            }
            $values[] = trim($value);
        }
        if ($values === []) {
            throw new InvalidConfigException("Swatch $this->handle has no colours.");
        }
        return $values;
    }

    public function getCssBackground(): string
    {
        $values = $this->getStops();
        return count($values) === 1 ? $values[0] : 'linear-gradient(to bottom right, ' . implode(', ', $values) . ')';
    }

    public function isGradient(): bool
    {
        return count($this->getStops()) > 1;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /** False when the colour already exists in the CSS framework, so generated files must not redefine it. */
    public function definesThemeColour(): bool
    {
        return $this->themeColour;
    }

    public function getFramework(): string
    {
        if ($this->framework !== null) {
            return $this->framework;
        }
        $plugin = MultisiteColourSwatches::getInstance();
        return $plugin instanceof MultisiteColourSwatches
            ? $plugin->getPaletteSettings()->getFrameworkForSite(Craft::$app->getSites()->getCurrentSite()->handle)
            : Frameworks::PLUGIN;
    }

    public function supportsShade(string $shade): bool
    {
        return $this->shadeColorFor($shade) !== null;
    }

    /** Exact configured shades win; otherwise single hex colours are tinted or shaded like Bootstrap. */
    public function shadeColorFor(string $shade): ?string
    {
        if ($this->shadeMap === false) {
            return null;
        }
        if (isset($this->shadeMap[$shade])) {
            return $this->shadeMap[$shade];
        }
        $stops = $this->getStops();
        return count($stops) === 1 ? Colour::shade($stops[0], $shade) : null;
    }

    /** The selected shade's colour, the base colour without a shade, or null for unknown shades and gradients. */
    public function getShadeColor(): ?string
    {
        if ($this->shade !== null) {
            return $this->shadeColorFor($this->shade);
        }
        $stops = $this->getStops();
        return count($stops) === 1 ? Colour::normalize($stops[0]) : null;
    }

    /** A CSS colour (or gradient) with the selected shade and opacity, for inline styles. */
    public function getCssColor(): string
    {
        $color = $this->getShadeColor();
        if ($color !== null) {
            return Colour::alpha($color, $this->opacity);
        }
        $stops = array_map(fn(string $stop): string => Colour::alpha($stop, $this->opacity), $this->getStops());
        return count($stops) === 1 ? $stops[0] : 'linear-gradient(to bottom right, ' . implode(', ', $stops) . ')';
    }

    /** Black or white text, chosen with Bootstrap's color-contrast() rules. */
    public function getContrastColor(): string
    {
        return Colour::contrast($this->getShadeColor() ?? $this->getStops()[0]);
    }

    /** Framework classes for bg, text, border, textBg, opacity or a button variant, including any selected shade and opacity. */
    public function utility(string $type): string
    {
        $utility = Frameworks::normalizeUtility($type);
        $framework = $this->getFramework();
        if (Frameworks::isButton($utility) && $framework !== Frameworks::BOOTSTRAP5) {
            return $this->classes(Frameworks::buttonParts($utility));
        }
        $classes = Frameworks::classes($framework, $utility, $this->token, $this->shade, $this->opacity, $this->getContrastColor());
        if ($framework === Frameworks::PLUGIN && $classes !== '') {
            $color = $this->shade !== null ? $this->getShadeColor() : null;
            PluginCss::register($utility, Frameworks::name($this->token, $this->shade), $color !== null ? [$color] : $this->getStops(), $this->opacity);
        }
        return $classes;
    }

    public function bg(): string
    {
        return $this->utility('bg');
    }

    public function text(): string
    {
        return $this->utility('text');
    }

    public function border(): string
    {
        return $this->utility('border');
    }

    public function textBg(): string
    {
        return $this->utility('textBg');
    }

    public function opacityClass(): string
    {
        return $this->utility('opacity');
    }

    /** Bootstrap: btn-{colour}. Elsewhere: background with contrasting text. */
    public function button(): string
    {
        return $this->utility('button');
    }

    /** Bootstrap: btn-outline-{colour}. Elsewhere: border and text colour. */
    public function buttonOutline(): string
    {
        return $this->utility('buttonOutline');
    }

    /** Bootstrap: btn-link text-{colour}. Elsewhere: text colour. */
    public function buttonLink(): string
    {
        return $this->utility('buttonLink');
    }

    /** Combines several utilities, e.g. swatch.classes('bg', 'opacity') or swatch.classes(['text', 'border']). */
    public function classes(string|array ...$types): string
    {
        $types = array_merge(...array_map(static fn(string|array $type): array => (array)$type, $types));
        return implode(' ', array_filter(array_map(fn(string $type): string => $this->utility($type), $types)));
    }

    /**
     * Inline CSS declarations matching the given utilities, e.g. swatch.style('bg') or swatch.style('textBg', 'border').
     * Use them where the framework class might not be in the compiled CSS, such as purged Tailwind classes.
     */
    public function style(string|array ...$types): string
    {
        $declarations = [];
        foreach (array_merge(...array_map(static fn(string|array $type): array => (array)$type, $types)) as $type) {
            $declarations = array_replace($declarations, $this->declarations(Frameworks::normalizeUtility($type)));
        }
        $css = [];
        foreach ($declarations as $property => $value) {
            $css[] = "$property: $value;";
        }
        return implode(' ', $css);
    }

    /**
     * class and style attributes for the given utilities. Tailwind sites get inline colours, because classes built
     * from field values are usually purged. Bootstrap sites get inline --bs-btn-* variables for button opacity,
     * which has no Bootstrap class.
     */
    public function attrs(string|array ...$types): Markup
    {
        $types = array_map([Frameworks::class, 'normalizeUtility'], array_merge(...array_map(static fn(string|array $type): array => (array)$type, $types)));
        $attributes = ['class' => $this->classes($types) ?: false];
        $framework = $this->getFramework();
        if (Frameworks::isTailwind($framework)) {
            $attributes['style'] = $this->style($types) ?: false;
        } elseif ($framework === Frameworks::BOOTSTRAP5) {
            $attributes['style'] = $this->style(array_values(array_filter($types, [Frameworks::class, 'isButton']))) ?: false;
        }
        return new Markup(trim(Html::renderTagAttributes($attributes)), 'UTF-8');
    }

    /** @return array<string, string> */
    private function declarations(string $utility): array
    {
        if ($utility === 'opacity') {
            return $this->opacity === null ? [] : ['opacity' => Frameworks::decimal($this->opacity)];
        }
        $framework = $this->getFramework();
        if (Frameworks::isButton($utility)) {
            return $framework === Frameworks::BOOTSTRAP5
                ? $this->bootstrapButtonDeclarations($utility)
                : array_replace(...array_map(fn(string $part): array => $this->declarations($part), Frameworks::buttonParts($utility)));
        }
        $shadeColor = $this->getShadeColor();
        $solid = $this->bootstrapColour() ?? Colour::alpha($shadeColor ?? $this->getStops()[0], $this->opacity);
        $background = $shadeColor === null ? ['background-image' => $this->getCssColor()] : ['background-color' => $solid];
        return match ($utility) {
            'bg' => $background,
            'text' => ['color' => $solid],
            'border' => ['border-color' => $solid],
            'textBg' => [...$background, 'color' => $this->getContrastColor()],
        };
    }

    /**
     * On Bootstrap sites, the colour from the compiled stylesheet's --bs-{token}-rgb variable, so inline styles
     * match the site's $theme-colors even if the plugin config uses a slightly different hex value.
     */
    private function bootstrapColour(): ?string
    {
        if ($this->getFramework() !== Frameworks::BOOTSTRAP5 || $this->shade !== null || $this->token === '' || $this->isGradient()) {
            return null;
        }
        return 'rgba(var(--bs-' . $this->token . '-rgb), ' . Frameworks::decimal($this->opacity ?? 100) . ')';
    }

    /**
     * Bootstrap buttons read their colours from --bs-btn-* variables, so a shade or opacity overrides them inline.
     * Hover, focus and active states keep the button class's own solid colours.
     *
     * @return array<string, string>
     */
    private function bootstrapButtonDeclarations(string $utility): array
    {
        if ($utility === 'buttonLink') {
            return $this->opacity === null ? [] : ['--bs-text-opacity' => Frameworks::decimal($this->opacity)];
        }
        if ($this->opacity === null && $this->shade === null) {
            return [];
        }
        $color = $this->bootstrapColour() ?? Colour::alpha($this->getShadeColor() ?? $this->getStops()[0], $this->opacity);
        if ($utility === 'buttonOutline') {
            return ['--bs-btn-color' => $color, '--bs-btn-border-color' => $color, '--bs-btn-disabled-color' => $color, '--bs-btn-disabled-border-color' => $color];
        }
        $declarations = ['--bs-btn-bg' => $color, '--bs-btn-border-color' => $color, '--bs-btn-disabled-bg' => $color, '--bs-btn-disabled-border-color' => $color];
        if ($this->shade !== null) {
            // A lighter or darker shade may need different text than the base colour's button.
            $declarations += ['--bs-btn-color' => $this->getContrastColor(), '--bs-btn-disabled-color' => $this->getContrastColor()];
        }
        return $declarations;
    }

    public function __toString(): string
    {
        return $this->label;
    }

    public function colors(): array|string
    {
        return $this->color;
    }

    public function labels(): string
    {
        return $this->label;
    }

    public function default(): bool
    {
        return $this->default;
    }

    public function class(): string
    {
        return $this->class;
    }

    public function collection(): Collection
    {
        $recursive = static function(mixed $value) use (&$recursive): mixed {
            return is_array($value) ? (new Collection($value))->map($recursive) : $value;
        };
        return (new Collection(is_array($this->color) ? $this->color : [$this->color]))->map($recursive);
    }

    private static function defaultToken(string $handle): string
    {
        if (preg_match(Frameworks::TOKEN_PATTERN, $handle)) {
            return $handle;
        }
        $token = trim((string)preg_replace('/[^A-Za-z0-9_]+/', '-', StringHelper::toKebabCase($handle)), '-');
        return preg_match(Frameworks::TOKEN_PATTERN, $token) ? $token : '';
    }

    /** @return array<string, string>|false */
    private static function parseShadeMap(mixed $shades, string $handle): array|false
    {
        if ($shades === false) {
            return false;
        }
        if (!is_array($shades)) {
            throw new InvalidConfigException("Swatch $handle shades must be false or an array of shade => colour values.");
        }
        $map = [];
        foreach ($shades as $shade => $color) {
            $shade = (string)$shade;
            if (!preg_match(Frameworks::SHADE_PATTERN, $shade) || !is_string($color) || !preg_match(self::COLOUR_PATTERN, trim($color))) {
                throw new InvalidConfigException("Swatch $handle has an invalid shade \"$shade\". Use shade => hex colour or CSS colour name.");
            }
            $map[$shade] = Colour::normalize($color);
        }
        return $map;
    }
}
