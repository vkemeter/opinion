<?php

declare(strict_types=1);

// Read state of a conversation per backend user; written by ConversationRepository only
return [
    'ctrl' => [
        'title' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_read',
        'label' => 'feedback',
        'rootLevel' => 1,
        'adminOnly' => true,
        'readOnly' => true,
        'hideTable' => true,
        'typeicon_classes' => [
            'default' => 'opinion-feedback',
        ],
    ],
    'types' => [
        1 => [
            'showitem' => 'feedback,be_user,last_message',
        ],
    ],
    'columns' => [
        'feedback' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_read.feedback',
            'config' => [
                'type' => 'number',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'be_user' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_read.be_user',
            'config' => [
                'type' => 'number',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'last_message' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_read.last_message',
            'config' => [
                'type' => 'number',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
    ],
];
