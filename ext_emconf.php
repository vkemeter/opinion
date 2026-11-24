<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Opinion',
    'description' => 'Collects feedback from editors including a screenshot and environment details and delivers it by email',
    'category' => 'be',
    'state' => 'stable',
    'author' => 'Volker Kemeter',
    'author_email' => 'v.kemeter@fonda.at',
    'version' => '2.0.0',
    'constraints' => [
        'depends' => [
            'php' => '8.4.0-8.5.99',
            'typo3' => '13.4.0-13.4.99',
            'adminpanel' => '13.4.0-13.4.99',
            'backend' => '13.4.0-13.4.99',
            'frontend' => '13.4.0-13.4.99',
            'setup' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'dashboard' => '13.4.0-13.4.99',
        ],
    ],
];
