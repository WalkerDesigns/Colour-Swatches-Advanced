<?php

namespace walkerdesigns\multisitecolourswatches;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\base\Plugin;
use craft\elements\db\ElementQuery;
use craft\events\CreateFieldLayoutFormEvent;
use craft\events\FieldElementEvent;
use craft\events\PopulateElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\services\Fields;
use walkerdesigns\multisitecolourswatches\fields\ColourSwatches;
use walkerdesigns\multisitecolourswatches\helpers\Frameworks;
use walkerdesigns\multisitecolourswatches\helpers\Generator;
use walkerdesigns\multisitecolourswatches\models\Settings;
use yii\base\Event;
use yii\base\InvalidConfigException;

class MultisiteColourSwatches extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        // Craft treats "*" as an environment key and would discard the legacy "sites" map.
        $path = Craft::$app->getConfig()->getConfigFilePath($this->id);
        if (is_file($path)) {
            $config = require $path;
            if (!is_array($config)) {
                throw new InvalidConfigException("$path must return an array.");
            }
            $this->getPaletteSettings()->loadConfig($config);
        }

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, static function(RegisterComponentTypesEvent $event) {
            $event->types[] = ColourSwatches::class;
        });

        if (class_exists(\verbb\hyper\base\Link::class)) {
            $legacyHyper = !method_exists(\verbb\hyper\base\Link::class, 'getOwner');
            if ($legacyHyper) {
                $this->registerLegacyHyperSupport();
            }
            Event::on(\verbb\hyper\fields\HyperField::class, Field::EVENT_BEFORE_ELEMENT_SAVE, static function(FieldElementEvent $event) use ($legacyHyper) {
                if ($legacyHyper) {
                    self::inheritHyperSite($event->sender, $event->element);
                }
                $links = $event->element->getFieldValue($event->sender->handle);
                if (!$links instanceof \verbb\hyper\models\LinkCollection) {
                    return;
                }
                // Hyper links are JSON values, so Craft does not run their fields' save hooks.
                foreach ($links as $link) {
                    foreach ($link->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                        if (!$field instanceof ColourSwatches) {
                            continue;
                        }
                        if (!$field->beforeElementSave($link, $event->isNew)) {
                            $event->isValid = false;
                            continue;
                        }
                        // Hyper 2 serializes this raw array rather than Craft's normalized values.
                        $link->fields[$field->handle] = $field->serializeValue($link->getFieldValue($field->handle), $link);
                    }
                }
            });
        }
    }

    private function registerLegacyHyperSupport(): void
    {
        // Older Hyper releases do not pass the owner site to their virtual links.
        Event::on(FieldLayout::class, FieldLayout::EVENT_CREATE_FORM, static function(CreateFieldLayoutFormEvent $event) {
            if ($event->element === null) {
                return;
            }
            foreach ($event->tabs as $tab) {
                foreach ($tab->getElements() as $layoutElement) {
                    if (!$layoutElement instanceof CustomField) {
                        continue;
                    }
                    self::inheritHyperSite($layoutElement->getField(), $event->element);
                }
            }
        });

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, static function(PopulateElementEvent $event) {
            if ($event->element !== null) {
                self::inheritHyperSites($event->element);
            }
        });

        Event::on(Element::class, Element::EVENT_BEFORE_VALIDATE, static function(Event $event) {
            self::inheritHyperSites($event->sender);
        });
    }

    private static function inheritHyperSites(ElementInterface $owner): void
    {
        foreach ($owner->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            self::inheritHyperSite($field, $owner);
        }
    }

    private static function inheritHyperSite(FieldInterface $field, ElementInterface $owner): void
    {
        if (!$field instanceof \verbb\hyper\fields\HyperField) {
            return;
        }
        $hasSwatches = false;
        foreach ($field->getLinkTypes() as $link) {
            $hasSwatches = self::inheritLinkSite($link, $owner) || $hasSwatches;
        }
        if (!$hasSwatches) {
            return;
        }
        $links = $owner->getFieldValue($field->handle);
        if ($links instanceof \verbb\hyper\models\LinkCollection) {
            foreach ($links as $link) {
                self::inheritLinkSite($link, $owner);
            }
        }
    }

    private static function inheritLinkSite(ElementInterface $link, ElementInterface $owner): bool
    {
        foreach ($link->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof ColourSwatches) {
                $link->siteId = $owner->siteId;
                return true;
            }
        }
        return false;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $settings = $this->getPaletteSettings();
        $labels = Frameworks::labels();
        $frameworks = [];
        foreach ($labels as $value => $label) {
            $frameworks[] = ['label' => $label, 'value' => $value];
        }
        $scales = [];
        foreach ($labels as $framework => $label) {
            $scales[] = [
                'framework' => $framework,
                'label' => $label,
                'defaultShades' => implode(', ', Frameworks::defaultShades($framework)),
                'defaultOpacities' => implode(', ', Frameworks::defaultOpacities($framework)),
                'customShades' => implode(', ', Frameworks::splitList($settings->customShades[$framework] ?? [])),
                'customOpacities' => implode(', ', Frameworks::opacityList($settings->customOpacities[$framework] ?? [])),
            ];
        }
        try {
            $generated = (new Generator($settings))->generate();
            $generatorError = null;
        } catch (\Throwable $exception) {
            $generated = null;
            $generatorError = $exception->getMessage();
        }
        return Craft::$app->getView()->renderTemplate('multisite-colour-swatches/plugin-settings', [
            'settings' => $settings,
            'frameworks' => $frameworks,
            'frameworkLabels' => $labels,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'scales' => $scales,
            'files' => Generator::FILES,
            'generated' => $generated,
            'generatorError' => $generatorError,
            'utilities' => [
                ['value' => 'bg', 'label' => Craft::t('multisite-colour-swatches', 'Background (bg-*)')],
                ['value' => 'text', 'label' => Craft::t('multisite-colour-swatches', 'Text (text-*)')],
                ['value' => 'border', 'label' => Craft::t('multisite-colour-swatches', 'Border (border-*)')],
                ['value' => 'textBg', 'label' => Craft::t('multisite-colour-swatches', 'Background with contrasting text (text-bg-*)')],
                ['value' => 'opacity', 'label' => Craft::t('multisite-colour-swatches', 'Element opacity (opacity-*)')],
            ],
        ]);
    }

    public function getPaletteSettings(): Settings
    {
        $settings = $this->getSettings();
        if (!$settings instanceof Settings) {
            throw new InvalidConfigException('Multisite Colour Swatches settings are unavailable.');
        }
        return $settings;
    }
}
