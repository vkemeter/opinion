<?php

declare(strict_types=1);

return [
    // Main backend rendering setup (previously called backend.php) for the TYPO3 Backend
    'opinion-backend' => [
        'path'   => '/opinion/backend',
        'target' => \Supseven\Opinion\Controller\OpinionController::class . '::opinionBackendAction',
    ],
];
