<?php

namespace walkerdesigns\multisitecolourswatches\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use walkerdesigns\multisitecolourswatches\helpers\Generator;
use walkerdesigns\multisitecolourswatches\MultisiteColourSwatches;
use yii\console\ExitCode;

/**
 * Writes Tailwind, Bootstrap and plugin CSS files for every colour, shade and opacity editors can choose.
 */
class GenerateController extends Controller
{
    public $defaultAction = 'index';

    /** @var string|null Output directory. Defaults to the plugin’s “Output path” setting. */
    public ?string $output = null;

    /** @var string|null Generate for this framework (tailwind3, tailwind4, bootstrap5 or plugin) on every site. */
    public ?string $framework = null;

    /** @var string|null Only include this site’s palettes, adding the site handle to the file names. */
    public ?string $site = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'output', 'framework', 'site'];
    }

    /**
     * Generates framework files.
     */
    public function actionIndex(): int
    {
        $plugin = MultisiteColourSwatches::getInstance();
        $settings = $plugin->getPaletteSettings();
        try {
            $result = (new Generator($settings))->generate($this->framework, $this->site);
        } catch (\Throwable $exception) {
            $this->stderr($exception->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }
        foreach ($result['warnings'] as $warning) {
            $this->stderr("Warning: $warning" . PHP_EOL, Console::FG_YELLOW);
        }
        if ($result['files'] === []) {
            $this->stdout('No Multisite Colour Swatches fields use these palettes yet, so there is nothing to generate.' . PHP_EOL);
            return ExitCode::OK;
        }
        try {
            $written = Generator::write($result['files'], $this->output ?? $settings->outputPath, $this->site);
        } catch (\Throwable $exception) {
            $this->stderr('Could not write the files: ' . $exception->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::IOERR;
        }
        foreach ($written as $path) {
            $this->stdout("Wrote $path" . PHP_EOL, Console::FG_GREEN);
        }
        return ExitCode::OK;
    }
}
