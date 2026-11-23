<?php

namespace walkerdesigns\multisitecolourswatches\models;

use craft\base\Model;
use walkerdesigns\multisitecolourswatches\helpers\Frameworks;
use yii\base\InvalidConfigException;

class Settings extends Model
{
    /** Settings saved from the control panel. Palettes are only ever read from the PHP config file. */
    public const SETTING_KEYS = ['framework', 'siteFrameworks', 'customShades', 'customOpacities', 'registerCss', 'outputPath', 'safelistUtilities'];

    public array $colors = [];
    public array $palettes = [];
    public array $sites = [];

    public string $framework = Frameworks::PLUGIN;
    /** @var array<string, string> Site handle => framework. Missing or blank sites use $framework. */
    public array $siteFrameworks = [];
    /** @var array<string, string[]> Framework => shades added to that framework's defaults. */
    public array $customShades = [];
    /** @var array<string, int[]> Framework => opacities added to that framework's defaults. */
    public array $customOpacities = [];
    public bool $registerCss = true;
    public string $outputPath = '@root/multisite-colour-swatches';
    public array $safelistUtilities = Frameworks::UTILITIES;

    private array $configOverrides = [];
    private array $configOriginals = [];

    public function fields(): array
    {
        return array_combine(self::SETTING_KEYS, self::SETTING_KEYS);
    }

    /** Craft posts '' for empty multi-value inputs and comma-separated text, so normalize before typed assignment. */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (is_array($values)) {
            $values = self::normalizeSettingValues($values);
        }
        parent::setAttributes($values, $safeOnly);
    }

    public static function normalizeSettingValues(array $values): array
    {
        if (array_key_exists('framework', $values)) {
            $values['framework'] = is_scalar($values['framework']) && trim((string)$values['framework']) !== ''
                ? trim((string)$values['framework'])
                : Frameworks::PLUGIN;
        }
        if (array_key_exists('siteFrameworks', $values)) {
            $sites = [];
            foreach (is_array($values['siteFrameworks']) ? $values['siteFrameworks'] : [] as $handle => $framework) {
                if (is_string($framework) && trim($framework) !== '') {
                    $sites[(string)$handle] = trim($framework);
                }
            }
            $values['siteFrameworks'] = $sites;
        }
        foreach (['customShades' => 'splitList', 'customOpacities' => 'opacityList'] as $key => $method) {
            if (array_key_exists($key, $values)) {
                $lists = [];
                foreach (is_array($values[$key]) ? $values[$key] : [] as $framework => $list) {
                    $list = Frameworks::$method($list);
                    if ($list !== []) {
                        $lists[(string)$framework] = $list;
                    }
                }
                $values[$key] = $lists;
            }
        }
        if (array_key_exists('registerCss', $values) && !is_bool($values['registerCss'])) {
            $values['registerCss'] = (bool)filter_var($values['registerCss'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('outputPath', $values)) {
            $values['outputPath'] = is_string($values['outputPath']) ? trim($values['outputPath']) : '';
        }
        if (array_key_exists('safelistUtilities', $values)) {
            $values['safelistUtilities'] = is_array($values['safelistUtilities'])
                ? array_values(array_filter($values['safelistUtilities'], static fn(mixed $value): bool => $value !== ''))
                : [];
        }
        return $values;
    }

    protected function defineRules(): array
    {
        return [
            [['framework'], 'in', 'range' => Frameworks::ALL],
            [['siteFrameworks'], function(string $attribute) {
                foreach ($this->siteFrameworks as $handle => $framework) {
                    if (!in_array($framework, Frameworks::ALL, true)) {
                        $this->addError($attribute, "Site $handle has an unknown framework \"$framework\".");
                    }
                }
            }],
            [['customShades', 'customOpacities'], function(string $attribute) {
                $labels = Frameworks::labels();
                foreach ($this->$attribute as $framework => $values) {
                    if (!in_array($framework, Frameworks::ALL, true) || !is_array($values)) {
                        $this->addError($attribute, "Unknown framework \"$framework\".");
                        continue;
                    }
                    foreach ($values as $value) {
                        try {
                            $attribute === 'customShades' ? Frameworks::parseShade($value) : Frameworks::parseOpacity($value);
                        } catch (\Throwable $exception) {
                            $printable = is_scalar($value) ? (string)$value : gettype($value);
                            $this->addError($attribute, "$labels[$framework]: \"$printable\": " . $exception->getMessage());
                        }
                    }
                }
            }],
            [['registerCss'], 'boolean'],
            [['outputPath'], 'required'],
            [['safelistUtilities'], function(string $attribute) {
                $unknown = array_diff($this->safelistUtilities, Frameworks::UTILITIES);
                if ($unknown !== []) {
                    $this->addError($attribute, 'Unknown utilities: ' . implode(', ', array_map('strval', $unknown)));
                }
            }],
        ];
    }

    public function loadConfig(array $config): void
    {
        // Restore control-panel values, so loading another config never keeps stale overrides.
        foreach ($this->configOriginals as $key => $value) {
            $this->$key = $value;
        }
        $this->configOriginals = [];
        $this->configOverrides = [];

        $unknown = array_diff(array_keys($config), ['*', 'colors', 'palettes', 'sites', ...self::SETTING_KEYS]);
        if ($unknown !== []) {
            throw new InvalidConfigException('Unknown colour config keys: ' . implode(', ', $unknown));
        }
        $defaults = array_key_exists('*', $config) ? $config['*'] : [];
        if (!is_array($defaults)) {
            throw new InvalidConfigException('The "*" colour defaults must be an array.');
        }
        $settingKeys = array_flip(self::SETTING_KEYS);
        $settings = array_intersect_key(array_replace($defaults, $config), $settingKeys);
        $global = array_replace(array_diff_key($defaults, $settingKeys), array_intersect_key($config, ['colors' => true, 'palettes' => true]));
        $this->validateGroup($global, 'global');
        if (array_key_exists('sites', $config) && !is_array($config['sites'])) {
            throw new InvalidConfigException('The colour config "sites" must be an array.');
        }
        foreach ($config['sites'] ?? [] as $handle => $group) {
            if (!is_string($handle) || $handle === '' || !is_array($group)) {
                throw new InvalidConfigException('Each site must have a site handle and an array of palettes.');
            }
            $this->validateGroup($group, "site $handle");
        }
        if ($settings !== []) {
            $originals = [];
            foreach (array_keys($settings) as $key) {
                $originals[$key] = $this->$key;
            }
            $this->setAttributes($settings, false);
            if (!$this->validate(array_keys($settings))) {
                $errors = $this->getFirstErrors();
                foreach ($originals as $key => $value) {
                    $this->$key = $value;
                }
                $this->clearErrors();
                throw new InvalidConfigException('Invalid colour swatch settings: ' . implode(' ', $errors));
            }
            $this->configOriginals = $originals;
            $this->configOverrides = array_keys($settings);
        }
        $this->colors = $global['colors'] ?? [];
        $this->palettes = $global['palettes'] ?? [];
        $this->sites = $config['sites'] ?? [];
    }

    public function isOverriddenByConfig(string $setting): bool
    {
        return in_array($setting, $this->configOverrides, true);
    }

    public function getFrameworkForSite(?string $siteHandle): string
    {
        $framework = $siteHandle !== null ? ($this->siteFrameworks[$siteHandle] ?? '') : '';
        $framework = $framework !== '' ? $framework : $this->framework;
        return in_array($framework, Frameworks::ALL, true) ? $framework : Frameworks::PLUGIN;
    }

    /** @return string[] Framework defaults plus valid additional shades. */
    public function getShades(string $framework): array
    {
        $custom = array_filter(Frameworks::splitList($this->customShades[$framework] ?? []),
            static fn(mixed $shade): bool => is_string($shade) && preg_match(Frameworks::SHADE_PATTERN, $shade) === 1);
        return Frameworks::sortShades([...Frameworks::defaultShades($framework), ...$custom]);
    }

    /** @return int[] Framework defaults plus valid additional opacities, in ascending order. */
    public function getOpacities(string $framework): array
    {
        $custom = array_filter(Frameworks::opacityList($this->customOpacities[$framework] ?? []),
            static fn(mixed $opacity): bool => is_int($opacity) && $opacity >= 0 && $opacity <= 100);
        $opacities = array_values(array_unique([...Frameworks::defaultOpacities($framework), ...$custom]));
        sort($opacities);
        return $opacities;
    }

    /** @return string[] */
    public function getSafelistUtilities(): array
    {
        return array_values(array_intersect(Frameworks::UTILITIES, $this->safelistUtilities));
    }

    public function getPaletteNames(): array
    {
        $names = array_keys($this->palettes);
        foreach ($this->sites as $group) {
            $names = array_merge($names, array_keys($group['palettes'] ?? []));
        }
        return array_values(array_unique($names));
    }

    /** A site palette replaces the whole global list, including an explicitly empty list. */
    public function getOptions(string $siteHandle, ?string $palette): ?array
    {
        $site = $this->sites[$siteHandle] ?? [];
        if ($palette === null || $palette === '') {
            return self::normalizeOptions($site['colors'] ?? $this->colors);
        }
        $options = $site['palettes'][$palette] ?? $this->palettes[$palette] ?? null;
        return $options === null ? null : self::normalizeOptions($options);
    }

    /** @return Swatch[] */
    public static function normalizeOptions(array $options): array
    {
        $result = [];
        $defaults = 0;
        foreach ($options as $option) {
            if (!is_array($option)) {
                throw new InvalidConfigException('Each colour swatch must be an array.');
            }
            $swatch = Swatch::fromArray($option);
            if (isset($result[$swatch->handle])) {
                throw new InvalidConfigException("Duplicate colour handle: $swatch->handle");
            }
            $defaults += (int)$swatch->default;
            $result[$swatch->handle] = $swatch;
        }
        if ($defaults > 1) {
            throw new InvalidConfigException('A colour palette can have only one default.');
        }
        return array_values($result);
    }

    private function validateGroup(array $group, string $context): void
    {
        if (array_diff(array_keys($group), ['colors', 'palettes']) !== []) {
            throw new InvalidConfigException("Only colors and palettes are allowed in $context.");
        }
        foreach (['colors', 'palettes'] as $key) {
            if (array_key_exists($key, $group) && !is_array($group[$key])) {
                throw new InvalidConfigException("$context.$key must be an array.");
            }
        }
        self::normalizeOptions($group['colors'] ?? []);
        foreach ($group['palettes'] ?? [] as $name => $options) {
            if (!is_string($name) || $name === '' || !is_array($options)) {
                throw new InvalidConfigException("Each palette in $context needs a name and a swatch array.");
            }
            self::normalizeOptions($options);
        }
    }
}
