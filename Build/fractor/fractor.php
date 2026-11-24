<?php

declare(strict_types=1);

use a9f\Fractor\Configuration\FractorConfiguration;
use a9f\Typo3Fractor\Set\Typo3LevelSetList;

$root = dirname(__DIR__, 2);

return FractorConfiguration::configure()
    ->withPaths([
        $root . '/Configuration',
        $root . '/Resources/Private',
    ])
    ->withSets([Typo3LevelSetList::UP_TO_TYPO3_14]);
