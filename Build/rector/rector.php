<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

$root = dirname(__DIR__, 2);

// Migrations only (TYPO3 v14, PHP 8.4); style sets like codeQuality would fight php-cs-fixer and the code style
return RectorConfig::configure()
    ->withPaths([
        $root . '/Classes',
        $root . '/Configuration',
        $root . '/Tests',
        $root . '/ext_localconf.php',
    ])
    ->withCache($root . '/.Build/.cache/rector')
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withPhpSets(php84: true)
    ->withSets([Typo3LevelSetList::UP_TO_TYPO3_14]);
