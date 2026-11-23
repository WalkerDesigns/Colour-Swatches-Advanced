<?php

declare(strict_types=1);

use craft\base\Element;
use craft\base\Field;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Json;
use craft\models\Site;
use craft\services\Config;
use craft\services\Fields;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use walkerdesigns\multisitecolourswatches\fields\ColourSwatches;
use walkerdesigns\multisitecolourswatches\models\Settings;
use walkerdesigns\multisitecolourswatches\models\Swatch;
use walkerdesigns\multisitecolourswatches\MultisiteColourSwatches;
use yii\base\Component;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\i18n\I18N;
use yii\i18n\PhpMessageSource;

$autoload = null;
if (isset($argv[1]) && !is_file($argv[1])) {
    throw new RuntimeException('The specified Composer autoloader does not exist: ' . $argv[1]);
}
foreach ([$argv[1] ?? '', dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    throw new RuntimeException('Install Composer dependencies in this package or its containing Craft project first.');
}
$loader = require $autoload;
$loader->addPsr4('walkerdesigns\\multisitecolourswatches\\', dirname(__DIR__) . '/src');
// An installed project's optimized class map must not redirect tests to an older vendor copy.
$source = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($source as $file) {
    if ($file->getExtension() === 'php') {
        $relative = substr($file->getPathname(), strlen(dirname(__DIR__) . '/src/'), -4);
        $loader->addClassMap(['walkerdesigns\\multisitecolourswatches\\' . str_replace(['/', '\\'], '\\', $relative) => $file->getPathname()]);
    }
}
require_once dirname($autoload) . '/yiisoft/yii2/Yii.php';
require_once dirname($autoload) . '/craftcms/cms/src/Craft.php';
Craft::setAlias('@vendor', dirname($autoload));
Craft::setAlias('@craft', dirname($autoload) . '/craftcms/cms/src');

$hyperVersion = Composer\InstalledVersions::isInstalled('verbb/hyper')
    ? Composer\InstalledVersions::getPrettyVersion('verbb/hyper')
    : 'none';
echo 'Testing PHP ' . PHP_VERSION . ', Craft ' . Composer\InstalledVersions::getPrettyVersion('craftcms/cms') . ", Hyper $hyperVersion\n";
if (($expectedHyper = getenv('SWATCH_EXPECT_HYPER')) !== false) {
    $matches = $expectedHyper === 'none' ? $hyperVersion === 'none' : str_starts_with(ltrim($hyperVersion, 'v'), $expectedHyper . '.');
    if (!$matches) {
        throw new RuntimeException("Expected Hyper $expectedHyper, got $hyperVersion.");
    }
}

class TestSites
{
    public Site $current;

    public function __construct()
    {
        $this->current = new Site(['id' => 1, 'handle' => 'default']);
    }

    public function getCurrentSite(): Site
    {
        return $this->current;
    }

    /** @return Site[] */
    public function getAllSites(): array
    {
        return [
            new Site(['id' => 1, 'handle' => 'default', 'name' => 'Default']),
            new Site(['id' => 2, 'handle' => 'omeo', 'name' => 'Omeo']),
            new Site(['id' => 3, 'handle' => 'empty', 'name' => 'Empty']),
        ];
    }
}

class TestFields
{
    public array $fields = [];

    public function getAllFields(mixed $context = null): array
    {
        return $this->fields;
    }
}

class TestProjectConfig
{
    public array $values = [];

    public function set(string $path, mixed $value, ?string $message = null): void
    {
        $this->values[$path] = $value;
    }
}

class TestRequest extends craft\console\Request
{
    public bool $site = false;

    public function getIsSiteRequest(): bool
    {
        return $this->site;
    }
}

class TestView
{
    public string $namespace = 'fields';
    public array $js = [];
    public array $assets = [];
    public Environment $twig;

    public function __construct()
    {
        $forms = <<<'TWIG'
{% macro attrs(config) %}{{ (config.disabled ?? false) ? ' disabled' }}{% endmacro %}
{% macro extras(config) %}{% if config.warning ?? false %}<p class="warning">{{ config.warning }}</p>{% endif %}{% if config.tip ?? false %}<p class="tip">{{ config.tip }}</p>{% endif %}{% for error in config.errors ?? [] %}<p class="error">{{ error }}</p>{% endfor %}{% endmacro %}
{% macro hidden(config) %}<input type="hidden" name="{{ config.name }}" value="{{ config.value }}">{% endmacro %}
{% macro lightswitchField(config) %}<input name="{{ config.name }}" type="checkbox"{{ (config.on ?? false) ? ' checked' }}{{ _self.attrs(config) }}>{{ _self.extras(config) }}{% endmacro %}
{% macro select(config) %}<select name="{{ config.name }}"{{ _self.attrs(config) }}>{% for option in config.options %}<option value="{{ option.value }}"{{ option.value == (config.value ?? null) ? ' selected' }}>{{ option.label }}</option>{% endfor %}</select>{% endmacro %}
{% macro selectField(config) %}{{ _self.select(config) }}{{ _self.extras(config) }}{% endmacro %}
{% macro textField(config) %}<input type="text" name="{{ config.name }}" value="{{ config.value ?? '' }}"{{ _self.attrs(config) }}>{{ _self.extras(config) }}{% endmacro %}
{% macro checkboxSelectField(config) %}{% for option in config.options %}<input type="checkbox" name="{{ config.name }}[]" value="{{ option.value }}"{{ option.value in config.values ? ' checked' }}{{ _self.attrs(config) }}>{% endfor %}{{ _self.extras(config) }}{% endmacro %}
{% macro field(config, input) %}<div id="{{ config.id }}">{{ input|raw }}{{ _self.extras(config) }}</div>{% endmacro %}
{% macro editableTableField(config) %}<div id="{{ config.id }}"></div>{% endmacro %}
TWIG;
        $this->twig = new Environment(new ChainLoader([
            new ArrayLoader(['_includes/forms' => $forms]),
            new FilesystemLoader(dirname(__DIR__) . '/src/templates'),
        ]), ['autoescape' => 'html', 'strict_variables' => true]);
        $this->twig->addFilter(new TwigFilter('t', static function(string $value, string $category, array $params = []): string {
            return Craft::t($category, $value, $params);
        }));
    }

    public function formatInputId(string $name): string
    {
        return $name;
    }

    public function namespaceInputId(string $id): string
    {
        return "$this->namespace-$id";
    }

    public function registerAssetBundle(string $class): void
    {
        $this->assets[] = $class;
    }

    public function registerJs(string $js): void
    {
        $this->js[] = $js;
    }

    public array $css = [];

    public function registerCss(string $css, array $options = [], ?string $key = null): void
    {
        $this->css[$key ?? md5($css)] = $css;
    }

    public function renderTemplate(string $name, array $variables): string
    {
        return $this->twig->render(basename($name) . '.twig', $variables);
    }
}

class TestApplication extends Component
{
    public string $language = 'en';
    public string $sourceLanguage = 'en';
    public string $charset = 'UTF-8';
    public array $loadedModules = [];
    public TestSites $sites;
    public TestView $view;
    public TestRequest $request;
    public TestFields $fields;
    public TestProjectConfig $projectConfig;
    public Config $config;
    public I18N $i18n;

    public function __construct()
    {
        parent::__construct();
        Craft::$app = $this;
        $this->sites = new TestSites();
        $this->request = new TestRequest();
        $this->fields = new TestFields();
        $this->projectConfig = new TestProjectConfig();
        $this->config = new Config(['configDir' => __DIR__ . '/fixtures']);
        $this->i18n = new I18N(['translations' => [
            '*' => ['class' => PhpMessageSource::class, 'sourceLanguage' => 'en', 'forceTranslation' => false],
            'multisite-colour-swatches' => ['class' => PhpMessageSource::class, 'sourceLanguage' => 'en', 'forceTranslation' => false],
        ]]);
        $this->view = new TestView();
    }

    public function getConfig(): Config { return $this->config; }
    public function getSites(): TestSites { return $this->sites; }
    public function getView(): TestView { return $this->view; }
    public function getI18n(): I18N { return $this->i18n; }
    public function getIsInstalled(): bool { return false; }
    public function getBasePath(): string { return Craft::getAlias('@craft'); }
    public function getVendorPath(): string { return Craft::getAlias('@vendor'); }
    public function getRequest(): craft\console\Request { return $this->request; }
    public function getFields(): TestFields { return $this->fields; }
    public function getProjectConfig(): TestProjectConfig { return $this->projectConfig; }
}

class TestElement extends Element
{
    public string $testSite = 'default';
    public array $testValues = [];
    public ?craft\models\FieldLayout $testLayout = null;

    public function init(): void
    {
        // No element service/database is needed to exercise a field's public API.
    }

    public function getSite(): Site
    {
        return new Site(['id' => $this->siteId, 'handle' => $this->testSite]);
    }

    public function getFieldLayout(): ?craft\models\FieldLayout
    {
        return $this->testLayout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $this->testValues[$fieldHandle] ?? null;
    }

    public function setFieldValue(string $fieldHandle, mixed $value): void
    {
        $this->testValues[$fieldHandle] = $value;
    }
}

$checks = 0;
function same(mixed $expected, mixed $actual, string $message): void
{
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        throw new RuntimeException("$message\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function rejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidConfigException|yii\base\InvalidArgumentException $exception) {
        same(true, $exception->getMessage() !== '', $message);
        return;
    }
    throw new RuntimeException($message);
}

Craft::$app = new TestApplication();
$plugin = new MultisiteColourSwatches('multisite-colour-swatches');
$settings = $plugin->getPaletteSettings();
$fixture = require __DIR__ . '/fixtures/multisite-colour-swatches.php';
$field = new ColourSwatches(['handle' => 'brandColour', 'name' => 'Brand colour']);
$default = new TestElement(['testSite' => 'default', 'siteId' => 1]);
$omeo = new TestElement(['testSite' => 'omeo', 'siteId' => 2]);
$empty = new TestElement(['testSite' => 'empty', 'siteId' => 3]);

$event = new RegisterComponentTypesEvent();
Event::trigger(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, $event);
same(realpath(dirname(__DIR__) . '/src/fields/ColourSwatches.php'), realpath((new ReflectionClass(ColourSwatches::class))->getFileName()), 'Tests execute the package source, not a stale installed copy');
same([ColourSwatches::class], $event->types, 'Plugin registers only its own field type');
same(Field::TRANSLATION_METHOD_SITE, $field->translationMethod, 'Fields default to per-site translation');
same([Field::TRANSLATION_METHOD_SITE], $field::supportedTranslationMethods(), 'Only per-site translation is allowed');
same(Field::TRANSLATION_METHOD_SITE, (new ColourSwatches(['translationMethod' => 'none']))->translationMethod, 'Shared translation cannot accidentally overwrite another site');
same(['brand', 'buttons', 'siteOnly'], $settings->getPaletteNames(), 'Settings include site-only palettes');
same(2, count($field->getOptionsForElement($omeo)), 'Plugin loads sites even with legacy wildcard config');
same(1, count($field->getOptionsForElement($default)), 'A site override does not mutate global options');
same([], $field->getOptionsForElement($empty), 'An explicit empty site palette does not fall back');
same([], $settings->getOptions('empty', 'brand'), 'Resolver preserves explicit empty lists');
same(null, $settings->getOptions('default', 'missing'), 'Missing palette is distinguishable from an empty palette');
same(1, count($settings->getOptions('omeo', 'buttons')), 'Missing site palette uses that named global palette');

$global = $field->getOptionsForElement($default)[0];
$site = $field->normalizeValue($global, $omeo);
same('#4a665a', $site->color[0]['color'], 'Normalization resolves an already-normalized model for the element site');
same('#ef730b', $global->color[0]['color'], 'Site normalization does not mutate a model from another site');
same('bg-green', $field->serializeValue($global, $omeo)['color'][0]['background'], 'Saving uses site metadata, not request/global metadata');
same('bg-primary', $field->serializeValue($site, $default)['color'][0]['background'], 'Alternating sites on one field instance is isolated');
same([], $field->options, 'Resolved palettes never overwrite field settings');
same(null, $field->normalizeValue(null, $default), 'An unconfigured default stays empty');

$legacy = ['label' => 'Primary', 'color' => [['color' => '#ffffff']]];
same('primary', $field->normalizeValue($legacy, $omeo)->handle, 'Legacy arrays resolve by label');
same('#4a665a', $field->normalizeValue(Json::encode($legacy), $omeo)->color[0]['color'], 'Legacy JSON is supported');
same('#4a665a', $field->normalizeValue(Json::encode(Json::encode($legacy)), $omeo)->color[0]['color'], 'Double-encoded module values are supported');
$collision = $fixture;
$collision['*']['palettes']['brand'] = [
    ['handle' => 'primary', 'label' => 'Different', 'color' => '#000000'],
    ['handle' => 'renamedHandle', 'label' => 'Primary', 'color' => '#ffffff'],
];
$settings->loadConfig($collision);
same('renamedHandle', $field->normalizeValue($legacy, $default)->handle, 'Legacy labels cannot accidentally match another option handle');
same('renamedHandle', $field->normalizeValue(Swatch::fromValue($legacy), $default)->handle, 'Legacy identity survives repeated model normalization');
$renamed = $fixture;
$renamed['sites']['omeo']['palettes']['brand'][0]['label'] = 'Renamed primary';
$settings->loadConfig($renamed);
same('Renamed primary', $field->normalizeValue($global, $omeo)->label, 'An explicit stable handle survives label changes');
$settings->loadConfig($fixture);
$forged = $global->toArray();
$forged['class'] = 'untrusted-class';
$forged['color'][0]['background'] = 'untrusted-background';
same('', $field->serializeValue($forged, $omeo)['class'], 'Saving replaces supplied class metadata with configured metadata');
same('bg-green', $field->serializeValue($forged, $omeo)['color'][0]['background'], 'Saving replaces supplied color metadata');

$stale = Swatch::fromArray(['handle' => 'removed', 'label' => 'Removed', 'color' => '#ffffff']);
same('removed', $field->normalizeValue($stale, $omeo)->handle, 'Removed values retain their snapshot');
$omeo->setFieldValue($field->handle, $stale);
$validate = $field->getElementValidationRules()[0][0];
$validate($omeo);
same(true, $omeo->hasErrors($field->handle), 'Removed values fail validation explicitly');
$omeo->clearErrors();
$omeo->setFieldValue($field->handle, $site);
$validate($omeo);
same(false, $omeo->hasErrors($field->handle), 'Current site values pass validation');

$defaults = $fixture;
$defaults['sites']['omeo']['palettes']['brand'][1]['default'] = true;
$settings->loadConfig($defaults);
same('orange', $field->normalizeValue(null, $omeo)->handle, 'A site-specific default is selected');
same(null, $field->normalizeValue(null, $default), 'A default does not leak to another site');
same('orange', $field->serializeValue('', $omeo)['handle'], 'Default and serialization agree');
$settings->loadConfig($fixture);
$field->setRandom = true;
same(null, $field->serializeValue(null, $omeo), 'Read-only serialization does not pick random values');
$omeo->setFieldValue($field->handle, null);
$field->beforeElementSave($omeo, true);
$random = $omeo->getFieldValue($field->handle);
same(true, in_array($random->handle, ['primary', 'orange'], true), 'Random-on-save uses the current site palette');
$field->beforeElementSave($omeo, false);
same($random->handle, $omeo->getFieldValue($field->handle)->handle, 'Random selection is stable on later saves');
$field->beforeElementSave($empty, true);
same(null, $empty->getFieldValue($field->handle), 'An empty palette never causes array_rand errors');
$field->setRandom = false;

Craft::$app->sites->current = new Site(['id' => 2, 'handle' => 'omeo']);
same(2, count($field->getOptionsForElement()), 'Rendering without an element uses the current site');
same(1, count($field->getOptionsForElement($default)), 'An element takes priority over the current CP site');
same('1', $field->getTranslationKey($default), 'Craft translation keys isolate site one');
same('2', $field->getTranslationKey($omeo), 'Craft translation keys isolate site two');

if (class_exists(verbb\hyper\base\Link::class) && method_exists(verbb\hyper\base\Link::class, 'setOwner')) {
    class TestHyperLayout extends craft\models\FieldLayout
    {
        public array $testFields = [];
        public function getCustomFields(): array { return $this->testFields; }
    }
    class TestHyperLink extends verbb\hyper\base\Link
    {
        public array $testValues = [];
        public ?craft\models\FieldLayout $testLayout = null;
        public function init(): void {}
        public function getFieldLayout(): ?craft\models\FieldLayout { return $this->testLayout; }
        public function getFieldValue(string $handle): mixed { return $this->testValues[$handle] ?? null; }
        public function setFieldValue(string $handle, mixed $value): void { $this->testValues[$handle] = $value; }
    }
    class TestHyperField extends verbb\hyper\fields\HyperField
    {
        public function init(): void {}
    }
    class TestHyperCollection extends verbb\hyper\models\LinkCollection
    {
        public array $testLinks = [];
        public function __construct() {}
        public function getIterator(): ArrayIterator { return new ArrayIterator($this->testLinks); }
    }
    $link = new TestHyperLink(['siteId' => 1]);
    $link->setOwner($omeo);
    same('#4a665a', $field->normalizeValue($global, $link)->color[0]['color'], 'Hyper link owner wins over a primary-site virtual link');
    same('bg-green', $field->serializeValue($global, $link)['color'][0]['background'], 'Hyper serialization uses the owner site');
    $link->testLayout = new TestHyperLayout(['testFields' => [$field]]);
    $link->fields = [$field->handle => $global->toArray(), 'unrelated' => 'unchanged'];
    $link->setFieldValue($field->handle, $global);
    $hyper = new TestHyperField(['handle' => 'links']);
    $links = new TestHyperCollection();
    $links->testLinks = [$link];
    $omeo->setFieldValue('links', $links);
    $hyper->trigger(Field::EVENT_BEFORE_ELEMENT_SAVE, new craft\events\FieldElementEvent(['element' => $omeo]));
    $payload = $link->getSerializedValues();
    same('bg-green', $payload['fields'][$field->handle]['color'][0]['background'], 'Hyper 3 persisted JSON contains owner-site metadata');
    same('unchanged', $payload['fields']['unrelated'], 'Hyper save hooks leave unrelated fields unchanged');
    $link->setFieldValue($field->handle, null);
    $link->fields = [];
    $field->setRandom = true;
    $hyper->trigger(Field::EVENT_BEFORE_ELEMENT_SAVE, new craft\events\FieldElementEvent(['element' => $omeo]));
    $payload = $link->getSerializedValues();
    same(true, in_array($payload['fields'][$field->handle]['handle'], ['primary', 'orange'], true), 'Hyper 3 saves random choices in its actual JSON payload');
    $field->setRandom = false;
    $link->setFieldValue($field->handle, null);
    $settings->loadConfig($defaults);
    $hyper->trigger(Field::EVENT_BEFORE_ELEMENT_SAVE, new craft\events\FieldElementEvent(['element' => $omeo]));
    same('orange', $link->getSerializedValues()['fields'][$field->handle]['handle'], 'Hyper 3 saves site-specific defaults');
    $settings->loadConfig($fixture);
} elseif (class_exists(verbb\hyper\base\Link::class)) {
    class TestLegacyLayout extends craft\models\FieldLayout
    {
        public array $testFields = [];
        public function getCustomFields(): array { return $this->testFields; }
    }
    class TestLegacyLink extends verbb\hyper\base\Link
    {
        public ?craft\models\FieldLayout $testLayout = null;
        public array $testValues = [];
        public function init(): void {}
        public function getFieldLayout(): ?craft\models\FieldLayout { return $this->testLayout; }
        public function getSite(): Site { return new Site(['id' => $this->siteId, 'handle' => $this->siteId === 2 ? 'omeo' : 'default']); }
        public function getFieldValue(string $handle): mixed { return $this->testValues[$handle] ?? null; }
        public function setFieldValue(string $handle, mixed $value): void { $this->testValues[$handle] = $value; }
    }
    class TestLegacyHyperField extends verbb\hyper\fields\HyperField
    {
        public array $testLinks = [];
        public function init(): void {}
        public function getLinkTypes(): array { return $this->testLinks; }
    }
    class TestLegacyTab extends craft\models\FieldLayoutTab
    {
        public array $testElements = [];
        public function getElements(): array { return $this->testElements; }
    }
    $linkLayout = new TestLegacyLayout(['testFields' => [$field]]);
    $prototype = new TestLegacyLink(['siteId' => 1, 'testLayout' => $linkLayout]);
    $link = clone $prototype;
    $unrelated = new TestLegacyLink(['siteId' => 1]);
    $hyper = new TestLegacyHyperField(['handle' => 'links', 'uid' => 'e9d7215d-69fb-44f6-9871-194ea1ebbc93', 'testLinks' => [$prototype, $unrelated]]);
    $omeo->testLayout = new TestLegacyLayout(['testFields' => [$hyper]]);
    $omeo->setFieldValue('links', new verbb\hyper\models\LinkCollection($hyper, [$link, $unrelated], $omeo));
    Event::trigger(craft\elements\db\ElementQuery::class, craft\elements\db\ElementQuery::EVENT_AFTER_POPULATE_ELEMENT,
        new craft\events\PopulateElementEvent(['element' => $omeo]));
    same(2, $link->siteId, 'Legacy Hyper queried links inherit their owner site');
    same(2, $prototype->siteId, 'Legacy Hyper new-link prototypes inherit the owner site');
    same(1, $unrelated->siteId, 'Legacy integration does not modify unrelated link layouts');
    same('#4a665a', $field->normalizeValue($global, $link)->color[0]['color'], 'Legacy Hyper template values use the owner palette');
    $link->siteId = 1;
    $omeo->trigger(Element::EVENT_BEFORE_VALIDATE);
    same(2, $link->siteId, 'Legacy Hyper validation restores owner context for posted collections');
    $link->siteId = 1;
    $hyper->trigger(Field::EVENT_BEFORE_ELEMENT_SAVE, new craft\events\FieldElementEvent(['element' => $omeo]));
    same('bg-green', $field->serializeValue($global, $link)['color'][0]['background'], 'Legacy Hyper saves use owner metadata even when validation is skipped');
    $field->setRandom = true;
    $hyper->trigger(Field::EVENT_BEFORE_ELEMENT_SAVE, new craft\events\FieldElementEvent(['element' => $omeo]));
    same(true, in_array($link->getFieldValue($field->handle)->handle, ['primary', 'orange'], true), 'Hyper invokes random-on-save for its JSON-backed child fields');
    $field->setRandom = false;
    $field->uid = '6bd80a14-c587-49d5-af64-19f061a05733';
    $field->layoutElement = new craft\fieldlayoutelements\CustomField($field, ['uid' => 'ce3b7992-55e6-41b6-9aa4-143935c11850']);
    $link->fields = [$field->handle => $global->toArray()];
    $link->setFieldValue($field->handle, $global);
    $hyper->trigger(Field::EVENT_BEFORE_ELEMENT_SAVE, new craft\events\FieldElementEvent(['element' => $omeo]));
    $payload = $link->getSerializedValues();
    same('bg-green', $payload['fields'][$field->layoutElement->uid]['color'][0]['background'], 'Legacy Hyper persisted JSON uses the normalized site palette');
    $link->fields = [];
    $link->setFieldValue($field->handle, null);
    $field->setRandom = true;
    $hyper->trigger(Field::EVENT_BEFORE_ELEMENT_SAVE, new craft\events\FieldElementEvent(['element' => $omeo]));
    $payload = $link->getSerializedValues();
    same(true, isset($payload['fields'][$field->layoutElement->uid]), 'Legacy Hyper persists a random choice in its JSON payload');
    $field->setRandom = false;
    $settings->loadConfig($defaults);
    $link->setFieldValue($field->handle, null);
    $hyper->trigger(Field::EVENT_BEFORE_ELEMENT_SAVE, new craft\events\FieldElementEvent(['element' => $omeo]));
    same('orange', $link->getSerializedValues()['fields'][$field->layoutElement->uid]['handle'], 'Legacy Hyper persists site-specific defaults');
    $settings->loadConfig($fixture);
    $link->siteId = 1;
    $prototype->siteId = 1;
    $tab = new TestLegacyTab(['testElements' => [new craft\fieldlayoutelements\CustomField($hyper)]]);
    Event::trigger(craft\models\FieldLayout::class, craft\models\FieldLayout::EVENT_CREATE_FORM,
        new craft\events\CreateFieldLayoutFormEvent(['element' => $omeo, 'tabs' => [$tab]]));
    same(2, $link->siteId, 'Legacy Hyper form rendering restores the saved link site');
    same(2, $prototype->siteId, 'Legacy Hyper form rendering restores new-link prototype sites');
    $omeo->testLayout = null;
}

if (class_exists(verbb\hyper\base\Link::class)) {
    $frameworkSettings = $settings->toArray();
    $settings->framework = 'tailwind4';
    $settings->siteFrameworks = ['omeo' => 'bootstrap5'];
    $hasOwner = method_exists($link, 'setOwner');
    $payloadKey = $hasOwner ? $field->handle : $field->layoutElement->uid;
    foreach ([$omeo, $default] as $owner) {
        $owner->setFieldValue('links', $omeo->getFieldValue('links'));
        if ($hasOwner) {
            $link->setOwner($owner);
        }
        foreach ($owner === $omeo ? [10, 50] : [0, 50] as $opacity) {
            $selection = $global->withSelection('200', $opacity);
            $link->fields = [$field->handle => $selection->toArray()];
            $link->setFieldValue($field->handle, $selection);
            $hyper->trigger(Field::EVENT_BEFORE_ELEMENT_SAVE, new craft\events\FieldElementEvent(['element' => $owner]));
            $saved = $link->getSerializedValues()['fields'][$payloadKey];
            // Bootstrap has no shade classes, so the owner's Bootstrap site drops the shade.
            same([$owner === $omeo ? null : '200', $opacity], [$saved['shade'], $saved['opacity']], 'Hyper persists the shade and opacity the owner site supports, including transparent');
            $reloaded = $field->normalizeValue(Json::encode($saved), $link);
            same($settings->getFrameworkForSite($owner->testSite), $reloaded->getFramework(), 'Reloaded Hyper modifiers use the owner framework');
            same($owner === $omeo ? '#4a665a' : '#ef730b', $reloaded->color[0]['color'], 'Reloaded Hyper modifiers retain the owner palette');
            same($owner === $omeo ? "bg-primary bg-opacity-$opacity" : "bg-primary-200/$opacity", $reloaded->bg(), 'Reloaded Hyper modifiers output the correct framework classes');
            if ($owner === $omeo && $opacity === 50) {
                $buttonTwig = new Environment(new ArrayLoader(['button' => <<<'TWIG'
{% set swatch = button.colourPickerButton ?? null %}{% set buttonType = {outline: 'buttonOutline', underline: 'buttonLink'}[(button.buttonStyle ?? '')|string] ?? 'button' %}<a class="btn {{ swatch ? swatch.utility(buttonType) }}"{% if swatch and swatch.style(buttonType) %} style="{{ swatch.style(buttonType) }}"{% endif %}>
TWIG]), ['autoescape' => 'html', 'strict_variables' => true]);
                $buttonTwig->addFilter(new TwigFilter('string', static fn($value) => (string)$value));
                $rendered = [];
                foreach (['solid', 'outline', 'underline', null] as $buttonStyle) {
                    $buttonStyle = $buttonStyle === null ? null : new craft\fields\data\SingleOptionFieldData(ucfirst($buttonStyle), $buttonStyle, true, true);
                    $rendered[] = $buttonTwig->render('button', ['button' => ['colourPickerButton' => $reloaded, 'buttonStyle' => $buttonStyle]]);
                }
                $rendered[] = $buttonTwig->render('button', ['button' => ['colourPickerButton' => null, 'buttonStyle' => 'solid']]);
                $solidVars = '--bs-btn-bg: rgba(var(--bs-primary-rgb), 0.5); --bs-btn-border-color: rgba(var(--bs-primary-rgb), 0.5); --bs-btn-disabled-bg: rgba(var(--bs-primary-rgb), 0.5); --bs-btn-disabled-border-color: rgba(var(--bs-primary-rgb), 0.5);';
                same([
                    "<a class=\"btn btn-primary\" style=\"$solidVars\">",
                    '<a class="btn btn-outline-primary" style="--bs-btn-color: rgba(var(--bs-primary-rgb), 0.5); --bs-btn-border-color: rgba(var(--bs-primary-rgb), 0.5); --bs-btn-disabled-color: rgba(var(--bs-primary-rgb), 0.5); --bs-btn-disabled-border-color: rgba(var(--bs-primary-rgb), 0.5);">',
                    '<a class="btn btn-link text-primary text-opacity-50" style="--bs-text-opacity: 0.5;">',
                    "<a class=\"btn btn-primary\" style=\"$solidVars\">",
                    '<a class="btn ">',
                ], $rendered, 'Hyper header buttons render Bootstrap button styles with opacity');
            }
        }
    }
    $settings->setAttributes($frameworkSettings, false);
}

$html = $field->getInputHtml($global, $omeo);
same(true, str_contains($html, '#4a665a'), 'Rendered input contains the element site colour');
same(false, str_contains($html, '#ef730b'), 'Rendered input excludes the global colour when overridden');
same(1, substr_count($html, 'aria-pressed="true"'), 'Exactly the selected option is active');
same(true, str_contains($field->getInputHtml($stale, $omeo), 'no longer available'), 'Removed selection has a visible warning');
same(true, str_contains($field->getInputHtml(null, $empty), 'No colours are configured'), 'Empty palette has a visible warning');
same(true, str_contains($field->getSettingsHtml(), 'siteOnly'), 'Settings render palettes that only exist on another site');
same(true, str_contains($field->getPreviewHtml($global, $omeo), '#4a665a'), 'Element table previews resolve the same site palette');
same('Primary', $field->getSearchKeywords($global, $omeo), 'Search keywords contain the current label');
Craft::$app->view->namespace = 'fields-nested-block';
$field->getInputHtml($global, $omeo);
same([], Craft::$app->view->js, 'Hyper inputs do not depend on queued per-field initialization scripts');
same(7, count($field->serializeValue($global, $omeo)), 'Serialized values contain exactly the seven supported fields');

$schema = new Schema(['query' => new ObjectType([
    'name' => 'Query',
    'fields' => ['swatch' => [
        'type' => $field->getContentGqlType(),
        'resolve' => fn() => $field->normalizeValue($global, $omeo),
    ]],
])]);
$gql = GraphQL::executeQuery($schema, '{ swatch { handle label class default color } }')->toArray();
same(false, isset($gql['errors']), 'GraphQL resolves the field type');
same('primary', $gql['data']['swatch']['handle'], 'GraphQL exposes the stable handle');
same('#4a665a', Json::decode($gql['data']['swatch']['color'][0])['color'], 'GraphQL returns site-specific colour metadata');
same('bg-green', $site->collection()->first()->get('background'), 'Recursive collections preserve metadata without global macros');

$field->useConfigFile = false;
$field->options = [['handle' => '', 'label' => 'Local colour', 'color' => '#123456,#abcdef', 'default' => true]];
same('localColour', $field->normalizeValue(null, $omeo)->handle, 'Empty optional handles are generated for local table rows');
same('linear-gradient(to bottom right, #123456, #abcdef)', $field->normalizeValue(null, $default)->getCssBackground(), 'Local gradients remain supported');
same($field->serializeValue(null, $default), $field->serializeValue(null, $omeo), 'Local palettes share options, not selection storage');

$unnamed = new Settings();
$unnamed->loadConfig(['colors' => [$legacy], 'sites' => ['empty' => ['colors' => []]]]);
same('primary', $unnamed->getOptions('default', '')[0]->handle, 'Legacy unnamed colors are supported');
same([], $unnamed->getOptions('empty', null), 'Site-specific empty unnamed colors are respected');
rejects(fn() => $settings->loadConfig(['sites' => 'invalid']), 'Malformed sites configuration is rejected');
rejects(fn() => $settings->loadConfig(['sites' => null]), 'Null sites configuration is rejected');
rejects(fn() => $settings->loadConfig(['production' => []]), 'Environment keys are rejected instead of silently ignored');
rejects(fn() => Settings::normalizeOptions([['label' => 'Broken']]), 'Missing colors are rejected');
rejects(fn() => Settings::normalizeOptions([$legacy, $legacy]), 'Duplicate generated handles are rejected');
rejects(fn() => Settings::normalizeOptions([
    ['label' => 'One', 'color' => '#fff', 'default' => true],
    ['label' => 'Two', 'color' => '#000', 'default' => true],
]), 'Multiple defaults are rejected');
rejects(fn() => Swatch::fromArray(['label' => 'Bad', 'color' => '#fff; background:url(https://example.invalid)']), 'Invalid CSS colour values are rejected');
rejects(fn() => Swatch::fromValue('{malformed'), 'Malformed saved/submitted JSON is not silently discarded');
same('0', Swatch::fromValue(['handle' => '0', 'label' => 'Zero', 'color' => '#fff'])->handle, 'A string zero is a valid explicit handle');

$example = new Settings();
$example->loadConfig(require dirname(__DIR__) . '/config.php');
same(6, count($example->getOptions('siteA', 'brand')), 'The public example combines common and site A colours');
same(6, count($example->getOptions('siteB', 'brand')), 'The public example combines common and site B colours');
same(4, count($example->getOptions('unconfigured', 'brand')), 'An unconfigured site receives the common palette');
same('#f37b4d', $example->getOptions('siteA', 'brand')[4]->color[0]['color'], 'The example site override is preserved');

// The commented-out plugin settings in the public example must work when uncommented.
$documentedConfig = tempnam(sys_get_temp_dir(), 'mcs');
file_put_contents($documentedConfig, preg_replace("/^(\s*)\/\/ ('[A-Za-z]+' =>)/m", '$1$2', file_get_contents(dirname(__DIR__) . '/config.php')));
try {
    $documented = new Settings();
    $documented->loadConfig(require $documentedConfig);
} finally {
    unlink($documentedConfig);
}
same(true, $documented->isOverriddenByConfig('framework') && $documented->isOverriddenByConfig('outputPath'), 'The example plugin settings are read from the config file');
same(['tailwind4', 'bootstrap5'], [$documented->getFrameworkForSite('siteA'), $documented->getFrameworkForSite('siteB')], 'The example sets a global framework and a site framework');
same(['950', '975'], array_slice($documented->getShades('tailwind4'), -2), 'The example adds a Tailwind shade');
same([5, 10, 15, 25, 50, 75, 100], $documented->getOpacities('bootstrap5'), 'The example adds Bootstrap opacities');

require __DIR__ . '/frameworks.php';

echo "PASS: $checks checks (Craft field APIs, site resolution, serialization, validation, Twig, GraphQL, shades, opacity and framework files).\n";

if (($browserFixture = getenv('SWATCH_BROWSER_FIXTURE')) !== false && $browserFixture !== '') {
    $settings->loadConfig($fixture);
    $render = static fn(ColourSwatches $field, TestElement $element, mixed $value, string $id): string => Craft::$app->view->twig->render('input.twig',
        $field->inputVariables($field->normalizeValue($value, $element), $element, $id, "fields[$id][brandColour]"));
    $field = new ColourSwatches(['handle' => 'brandColour']);
    $limited = new ColourSwatches(['handle' => 'brandColour', 'shades' => '100, 200, 900', 'opacities' => '50, 80']);
    $gradients = new ColourSwatches(['handle' => 'brandColour', 'useConfigFile' => false, 'options' => [
        ['handle' => 'rainbow', 'label' => 'Rainbow', 'color' => '#ff0000,#0000ff'],
        ['handle' => 'navy', 'label' => 'Navy', 'color' => '#000080'],
    ]]);
    $html = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Swatch regression fixture</title><body>'
        . $render($field, $default, null, 'site-default')
        . $render($field, $omeo, $stale, 'site-omeo')
        . $render($limited, $omeo, ['handle' => 'primary', 'label' => 'Primary', 'color' => '#4a665a', 'shade' => '950', 'opacity' => 33], 'site-limited')
        . $render($gradients, $default, null, 'site-gradient');
    if (file_put_contents($browserFixture, $html . '</body></html>') === false) {
        throw new RuntimeException('Unable to write the browser test fixture.');
    }
}

if (($frameworkFixtures = getenv('SWATCH_FRAMEWORK_FIXTURES')) !== false && $frameworkFixtures !== '') {
    require __DIR__ . '/frameworks/build.php';
}
