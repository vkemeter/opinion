<?php

declare(strict_types = 1);
$EM_CONF[$_EXTKEY] = [
    'title'            => 'Opinion',
    'description'      => 'Getting user opinions like a boss',
    'category'         => 'Plugin',
    'author'           => 'Volker Kemeter',
    'author_email'     => 'v.kemeter@supseven.at',
    'author_company'   => 'https://www.supseven.at/opinion',
    'state'            => 'stable',
    'uploadfolder'     => '0',
    'createDirs'       => '',
    'clearCacheOnLoad' => 1,
    'version'          => '1.0.0',
    'constraints'      => [
        'depends' => [
            'typo3' => '10.4.0-10.99.99',
            'php'   => '7.2.0-7.4.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Supseven\\Opinion\\' => 'Classes',
        ],
    ],
    'autoload-dev' => [
        'psr-4' => [
            'Supseven\\Opinion\\Tests\\' => 'Tests',
        ],
    ],
];
