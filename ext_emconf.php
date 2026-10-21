<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Opinion',
    'description' => 'Collects feedback from editors including a screenshot and environment details and delivers it by email',
    'category' => 'be',
    'state' => 'stable',
    'author' => 'Volker Kemeter',
    'author_email' => 'v.kemeter@fonda.at',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'php' => '8.4.0-8.5.99',
            'typo3' => '14.3.0-14.99.99',
            'adminpanel' => '14.3.0-14.99.99',
            'backend' => '14.3.0-14.99.99',
            'frontend' => '14.3.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
