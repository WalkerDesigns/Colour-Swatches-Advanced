<?php

namespace walkerdesigns\multisitecolourswatches\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Html;
use craft\helpers\Json;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use walkerdesigns\multisitecolourswatches\assetbundles\ColourSwatchesAsset;
use walkerdesigns\multisitecolourswatches\helpers\Frameworks;
use walkerdesigns\multisitecolourswatches\models\Settings;
use walkerdesigns\multisitecolourswatches\models\Swatch;
use walkerdesigns\multisitecolourswatches\MultisiteColourSwatches;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

class ColourSwatches extends Field implements PreviewableFieldInterface
{
    public bool $useConfigFile = true;
    public ?string $palette = 'brand';
    public array $options = [];
    public bool $setRandom = false;
    public string $translationMethod = self::TRANSLATION_METHOD_SITE;
    public bool $allowShades = true;
    public bool $allowOpacity = true;
    /** @var string[] Shades editors may choose; empty allows every shade of the site's framework. */
    public array $shades = [];
    /** @var int[] Opacities editors may choose; empty allows every opacity of the site's framework. */
    public array $opacities = [];

    public function __construct($config = [])
    {
        // Field settings post comma-separated text; project config stores lists.
        if (array_key_exists('shades', $config)) {
            $config['shades'] = Frameworks::splitList($config['shades']);
        }
        if (array_key_exists('opacities', $config)) {
            $config['opacities'] = Frameworks::opacityList($config['opacities']);
        }
        parent::__construct($config);
    }

    public static function displayName(): string
    {
        return Craft::t('multisite-colour-swatches', 'Multisite Colour Swatches');
    }

    public static function icon(): string
    {
        return dirname(__DIR__) . '/icon.svg';
    }

    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_SITE];
    }

    public static function phpType(): string
    {
        return '\\' . Swatch::class . '|null';
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['useConfigFile', 'setRandom', 'allowShades', 'allowOpacity'], 'boolean'],
            [['palette'], 'string'],
            [['options'], function() {
                if (!$this->useConfigFile) {
                    try {
                        Settings::normalizeOptions($this->options);
                    } catch (InvalidConfigException $exception) {
                        $this->addError('options', $exception->getMessage());
                    }
                }
            }],
            [['shades', 'opacities'], function(string $attribute) {
                foreach ($this->$attribute as $value) {
                    try {
                        $attribute === 'shades' ? Frameworks::parseShade($value) : Frameworks::parseOpacity($value);
                    } catch (InvalidArgumentException $exception) {
                        $printable = is_scalar($value) ? (string)$value : gettype($value);
                        $this->addError($attribute, "\"$printable\": " . $exception->getMessage());
                    }
                }
            }],
        ]);
    }

    protected function paletteSettings(): Settings
    {
        $plugin = MultisiteColourSwatches::getInstance();
        if (!$plugin instanceof MultisiteColourSwatches) {
            throw new InvalidConfigException('The Multisite Colour Swatches plugin is not installed.');
        }
        return $plugin->getPaletteSettings();
    }

    private function siteHandle(?ElementInterface $element): string
    {
        if ($element instanceof \verbb\hyper\base\Link && method_exists($element, 'getOwner')) {
            $element = $element->getOwner() ?? $element;
        }
        return ($element?->getSite() ?? Craft::$app->getSites()->getCurrentSite())->handle;
    }

    /** @return Swatch[] */
    public function getOptionsForElement(?ElementInterface $element = null): array
    {
        return $this->getOptionsForSite($this->siteHandle($element));
    }

    /** @return Swatch[] */
    public function getOptionsForSite(string $siteHandle): array
    {
        return $this->useConfigFile
            ? ($this->paletteSettings()->getOptions($siteHandle, $this->palette) ?? [])
            : Settings::normalizeOptions($this->options);
    }

    /** The CSS framework configured for the element's site (or the current site). */
    public function frameworkFor(?ElementInterface $element = null): string
    {
        return $this->paletteSettings()->getFrameworkForSite($this->siteHandle($element));
    }

    /** @return string[] Shades editors can choose for a framework, optionally only those a swatch supports. */
    public function shadeOptionsForFramework(string $framework, ?Swatch $swatch = null): array
    {
        if (!$this->allowShades) {
            return [];
        }
        $shades = $this->paletteSettings()->getShades($framework);
        if ($this->shades !== []) {
            $shades = array_values(array_intersect($shades, array_map('strval', $this->shades)));
        }
        return $swatch === null ? $shades : array_values(array_filter($shades, [$swatch, 'supportsShade']));
    }

    /** @return int[] */
    public function opacityOptionsForFramework(string $framework): array
    {
        if (!$this->allowOpacity) {
            return [];
        }
        $opacities = $this->paletteSettings()->getOpacities($framework);
        return $this->opacities === [] ? $opacities : array_values(array_intersect($opacities, $this->opacities));
    }

    /** @return string[] */
    public function getShadeOptions(?ElementInterface $element = null, ?Swatch $swatch = null): array
    {
        return $this->shadeOptionsForFramework($this->frameworkFor($element), $swatch);
    }

    /** @return int[] */
    public function getOpacityOptions(?ElementInterface $element = null): array
    {
        return $this->opacityOptionsForFramework($this->frameworkFor($element));
    }

    private function match(Swatch $value, array $options): ?Swatch
    {
        foreach ($options as $option) {
            if ($value->handle === '' ? $option->label === $value->label : $option->handle === $value->handle) {
                return $option;
            }
        }
        return null;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): ?Swatch
    {
        $swatch = Swatch::fromValue($value);
        $options = $this->getOptionsForElement($element);
        $framework = $this->frameworkFor($element);
        if ($swatch !== null) {
            // Editors cannot change modifiers once they are switched off or the site's framework has none, so drop them instead of failing validation.
            return ($this->match($swatch, $options) ?? $swatch)->withSelection(
                $this->shadeOptionsForFramework($framework) !== [] ? $swatch->shade : null,
                $this->opacityOptionsForFramework($framework) !== [] ? $swatch->opacity : null,
                $framework,
            );
        }
        foreach ($options as $option) {
            if ($option->default) {
                return $option->withSelection(null, null, $framework);
            }
        }
        return null;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): ?array
    {
        return $this->normalizeValue($value, $element)?->toArray();
    }

    public function getElementValidationRules(): array
    {
        return [[function(ElementInterface $element) {
            $value = $this->normalizeValue($element->getFieldValue($this->handle), $element);
            if ($value === null) {
                return;
            }
            $option = $this->match($value, $this->getOptionsForElement($element));
            if ($option === null) {
                $element->addError($this->handle, Craft::t('multisite-colour-swatches',
                    'Select a colour from the palette for this site, or clear the selection.'));
                return;
            }
            if ($value->shade !== null && !in_array($value->shade, $this->getShadeOptions($element, $option), true)) {
                $element->addError($this->handle, Craft::t('multisite-colour-swatches',
                    'The shade “{shade}” is not available for {label} on this site.', ['shade' => $value->shade, 'label' => $option->label]));
            }
            if ($value->opacity !== null && !in_array($value->opacity, $this->getOpacityOptions($element), true)) {
                $element->addError($this->handle, Craft::t('multisite-colour-swatches',
                    'The opacity {opacity}% is not available on this site.', ['opacity' => $value->opacity]));
            }
        }, 'skipOnEmpty' => false]];
    }

    public function beforeElementSave(ElementInterface $element, bool $isNew): bool
    {
        if (!parent::beforeElementSave($element, $isNew)) {
            return false;
        }
        $value = $this->normalizeValue($element->getFieldValue($this->handle), $element);
        $options = $this->getOptionsForElement($element);
        if ($value === null && $this->setRandom && $options !== []) {
            $value = $options[array_rand($options)]->withSelection(null, null, $this->frameworkFor($element));
        }
        $element->setFieldValue($this->handle, $value);
        return true;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(ColourSwatchesAsset::class);
        return $view->renderTemplate('multisite-colour-swatches/input',
            $this->inputVariables($this->normalizeValue($value, $element), $element, $view->formatInputId($this->handle), $this->handle));
    }

    /**
     * Builds the editor template variables.
     *
     * @internal
     */
    public function inputVariables(?Swatch $value, ?ElementInterface $element, string $id, string $name): array
    {
        $options = $this->getOptionsForElement($element);
        $selected = $value !== null ? $this->match($value, $options) : null;
        $allShades = $this->getShadeOptions($element);
        $opacities = $this->getOpacityOptions($element);
        $swatches = [];
        $selectedShades = [];
        foreach ($options as $option) {
            $shades = [];
            foreach ($this->getShadeOptions($element, $option) as $shade) {
                $shades[$shade] = $option->shadeColorFor($shade);
            }
            if ($selected !== null && $option->handle === $selected->handle) {
                $selectedShades = $shades;
            }
            $swatches[] = ['option' => $option, 'shades' => Json::encode($shades, JSON_FORCE_OBJECT)];
        }
        $shadeOptions = [];
        foreach ($allShades as $shade) {
            $disabled = $selected !== null && !array_key_exists($shade, $selectedShades);
            $shadeOptions[] = [
                'value' => $shade,
                'label' => $shade,
                'selected' => $value?->shade === $shade,
                'disabled' => $disabled,
                'unavailable' => $disabled && $value?->shade === $shade,
                'extra' => false,
            ];
        }
        $opacityOptions = [];
        foreach ($opacities as $opacity) {
            $opacityOptions[] = ['value' => (string)$opacity, 'label' => "$opacity%", 'selected' => $value?->opacity === $opacity, 'disabled' => false, 'unavailable' => false, 'extra' => false];
        }
        $unavailableShade = $value?->shade !== null && ($selected === null || !array_key_exists($value->shade, $selectedShades));
        $unavailableOpacity = $value?->opacity !== null && !in_array($value->opacity, $opacities, true);
        if ($unavailableShade && $allShades !== [] && !in_array($value->shade, $allShades, true)) {
            $shadeOptions[] = ['value' => $value->shade, 'label' => $value->shade, 'selected' => true, 'disabled' => false, 'unavailable' => true, 'extra' => true];
        }
        if ($unavailableOpacity && $opacities !== []) {
            $opacityOptions[] = ['value' => (string)$value->opacity, 'label' => "$value->opacity%", 'selected' => true, 'disabled' => false, 'unavailable' => true, 'extra' => true];
        }
        return [
            'id' => $id,
            'name' => $name,
            'options' => $options,
            'swatches' => $swatches,
            'value' => $value,
            'unavailable' => $value !== null && $selected === null,
            'hasSelection' => $selected !== null,
            'shadeOptions' => $allShades !== [] ? $shadeOptions : [],
            'opacityOptions' => $opacities !== [] ? $opacityOptions : [],
            'unavailableShade' => $unavailableShade && $selected !== null && $allShades !== [],
            'unavailableOpacity' => $unavailableOpacity && $opacities !== [],
            'previewColor' => $value === null ? 'transparent' : ($value->getShadeColor() ?? $value->getCssBackground()),
            'previewOpacity' => $value?->opacity === null ? 1 : $value->opacity / 100,
        ];
    }

    public function getSettingsHtml(): ?string
    {
        $settings = $this->paletteSettings();
        $names = $settings->getPaletteNames();
        if ($this->palette && !in_array($this->palette, $names, true)) {
            $names[] = $this->palette;
        }
        $palettes = [['label' => Craft::t('multisite-colour-swatches', 'Unnamed colours (colors)'), 'value' => '']];
        foreach ($names as $name) {
            $palettes[] = ['label' => $name, 'value' => $name];
        }
        $frameworks = [$settings->framework => true];
        foreach ($settings->siteFrameworks as $framework) {
            $frameworks[$framework] = true;
        }
        $labels = Frameworks::labels();
        $shadeHints = [];
        $opacityHints = [];
        foreach (array_keys($frameworks) as $framework) {
            if (!isset($labels[$framework])) {
                continue;
            }
            $shadeHints[] = $labels[$framework] . ': ' . implode(', ', $settings->getShades($framework));
            $opacityHints[] = $labels[$framework] . ': ' . implode(', ', $settings->getOpacities($framework));
        }
        return Craft::$app->getView()->renderTemplate('multisite-colour-swatches/settings', [
            'field' => $this,
            'palettes' => $palettes,
            'shadeHints' => $shadeHints,
            'opacityHints' => $opacityHints,
        ]);
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        $value = $this->normalizeValue($value, $element);
        $title = $value === null ? '' : trim($value->label . ' ' . ($value->shade ?? '') . ($value->opacity !== null ? " $value->opacity%" : ''));
        return Html::tag('div', Html::tag('div', '', [
            'class' => 'color-preview',
            'style' => 'background: ' . ($value?->getCssColor() ?? 'transparent'),
        ]), ['class' => 'color small static', 'title' => $title]);
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return $this->normalizeValue($value, $element)?->label ?? '';
    }

    public function getContentGqlType(): Type|array
    {
        $name = 'WalkerDesignsMultisiteColourSwatch';
        return GqlEntityRegistry::getEntity($name) ?: GqlEntityRegistry::createEntity($name, new ObjectType([
            'name' => $name,
            'fields' => [
                'handle' => ['type' => Type::string()],
                'label' => ['type' => Type::string()],
                'class' => ['type' => Type::string()],
                'default' => ['type' => Type::boolean()],
                'color' => [
                    'type' => Type::listOf(Type::string()),
                    'resolve' => static function(Swatch $value): array {
                        return is_array($value->color)
                            ? array_map(static fn(mixed $color): string => is_array($color) ? Json::encode($color) : $color, array_values($value->color))
                            : [$value->color];
                    },
                ],
                'shade' => ['type' => Type::string()],
                'opacity' => ['type' => Type::int()],
                'framework' => ['type' => Type::string(), 'resolve' => static fn(Swatch $value): string => $value->getFramework()],
                'token' => ['type' => Type::string(), 'resolve' => static fn(Swatch $value): string => $value->getToken()],
                'shadeColor' => ['type' => Type::string(), 'resolve' => static fn(Swatch $value): ?string => $value->getShadeColor()],
                'cssColor' => ['type' => Type::string(), 'resolve' => static fn(Swatch $value): string => $value->getCssColor()],
                'contrastColor' => ['type' => Type::string(), 'resolve' => static fn(Swatch $value): string => $value->getContrastColor()],
                'utility' => [
                    'type' => Type::string(),
                    'description' => 'Framework classes for bg, text, border, textBg, opacity, button, buttonOutline or buttonLink.',
                    'args' => ['type' => Type::nonNull(Type::string())],
                    'resolve' => static function(Swatch $value, array $args): string {
                        try {
                            return $value->utility($args['type']);
                        } catch (InvalidArgumentException $exception) {
                            throw new UserError($exception->getMessage());
                        }
                    },
                ],
                'style' => [
                    'type' => Type::string(),
                    'description' => 'Inline CSS declarations for bg, text, border, textBg, opacity, button, buttonOutline or buttonLink.',
                    'args' => ['type' => Type::nonNull(Type::string())],
                    'resolve' => static function(Swatch $value, array $args): string {
                        try {
                            return $value->style($args['type']);
                        } catch (InvalidArgumentException $exception) {
                            throw new UserError($exception->getMessage());
                        }
                    },
                ],
            ],
        ]));
    }
}
