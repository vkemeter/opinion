<?php

declare(strict_types=1);

use Supseven\Opinion\Frontend\InjectOpinion;

return [
    'frontend' => [
        'supseven/opinion/inject' => [
            'target' => InjectOpinion::class,
            'after'  => [
                'typo3/cms-frontend/timetracker',
            ],
        ],
    ],
];
