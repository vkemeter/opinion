<?php

declare(strict_types=1);

// Messages of the conversation of a feedback; written by ConversationRepository only
return [
    'ctrl' => [
        'title' => 'opinion.messages:tx_opinion_message',
        'label' => 'message',
        'crdate' => 'crdate',
        'default_sortby' => 'crdate ASC',
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
            'showitem' => 'feedback,be_user,message',
        ],
    ],
    'columns' => [
        'feedback' => [
            'label' => 'opinion.messages:tx_opinion_message.feedback',
            'config' => [
                'type' => 'number',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'be_user' => [
            'label' => 'opinion.messages:tx_opinion_message.be_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'items' => [['label' => '', 'value' => 0]],
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'message' => [
            'label' => 'opinion.messages:tx_opinion_message.message',
            'config' => [
                'type' => 'text',
                'rows' => 5,
                'readOnly' => true,
            ],
        ],
    ],
];
