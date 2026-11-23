<?php

namespace walkerdesigns\multisitecolourswatches\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ColourSwatchesAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['swatches.js'];
        $this->css = ['swatches.css'];
        parent::init();
    }
}
