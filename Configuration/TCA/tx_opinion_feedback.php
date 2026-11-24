<?php

declare(strict_types=1);

use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\FeedbackStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;

$enumItems = static fn(string $prefix, array $cases): array => array_map(
    static fn(\BackedEnum $case): array => ['label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:' . $prefix . '.' . $case->value, 'value' => $case->value],
    $cases,
);
$readOnlyJson = ['type' => 'json', 'readOnly' => true, 'searchable' => false];

return [
    'ctrl' => [
        'title' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback',
        'label' => 'message',
        'label_alt' => 'category,severity',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'crdate DESC',
        'rootLevel' => 1,
        'adminOnly' => true,
        'readOnly' => true,
        // Shown in the module "Feedback" instead of the List module
        'hideTable' => true,
        'typeicon_classes' => [
            'default' => 'opinion-feedback',
        ],
    ],
    'types' => [
        1 => [
            'showitem' => 'status,source,category,severity,be_user,message,
            --div--;LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.tab.details, client_info, system_info, context,
            --div--;LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.tab.delivery, delivery_status, delivery_attempts, delivery_log',
        ],
    ],
    'columns' => [
        'status' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => $enumItems('status', FeedbackStatus::cases()),
                'default' => FeedbackStatus::New->value,
                'readOnly' => true,
            ],
        ],
        'source' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.source',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => $enumItems('source', Source::cases()),
                'readOnly' => true,
            ],
        ],
        'category' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.category',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => $enumItems('category', Category::cases()),
                'readOnly' => true,
            ],
        ],
        'severity' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.severity',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => $enumItems('severity', Severity::cases()),
                'readOnly' => true,
            ],
        ],
        'be_user' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.be_user',
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
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.message',
            'config' => [
                'type' => 'text',
                'rows' => 8,
                'readOnly' => true,
            ],
        ],
        'screenshot' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.screenshot',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'allowed' => 'png,jpg',
                'readOnly' => true,
            ],
        ],
        'client_info' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.client_info',
            'config' => $readOnlyJson,
        ],
        'system_info' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.system_info',
            'config' => $readOnlyJson,
        ],
        'context' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.context',
            'config' => $readOnlyJson,
        ],
        // The page of the collected context, for the lists per page; 0 = no page collected
        'page_uid' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.page_uid',
            'config' => [
                'type' => 'number',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'delivery_status' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.delivery_status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => $enumItems('deliveryStatus', DeliveryStatus::cases()),
                'default' => DeliveryStatus::Pending->value,
                'readOnly' => true,
            ],
        ],
        'delivery_attempts' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.delivery_attempts',
            'config' => [
                'type' => 'number',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'delivery_log' => [
            'label' => 'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:tx_opinion_feedback.delivery_log',
            'config' => [
                'type' => 'text',
                'rows' => 5,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
    ],
];
