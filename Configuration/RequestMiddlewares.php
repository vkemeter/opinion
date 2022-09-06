<?php

declare(strict_types=1);

return [
    'frontend' => [
        'supseven/opinion/inject' => [
            'target' => \Supseven\Opinion\Frontend\InjectOpinion::class,
            'after'  => [
                'typo3/cms-frontend/timetracker',
            ],
        ],
    ],
];
