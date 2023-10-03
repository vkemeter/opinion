<?php

declare(strict_types=1);

namespace Supseven\Opinion\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Supseven\Opinion\Service\Email;
use Supseven\Opinion\Service\OpinionService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class OpinionController extends ActionController
{
    /** @var array Settings */
    protected array $tsSettings = [];

    /** @var ExtensionConfiguration */
    protected ExtensionConfiguration $extensionConfiguration;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $this->tsSettings = $this->extensionConfiguration->get('opinion') ?? [];
        $this->logger = $logger;
    }

    public function opinionBackendAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $data = OpinionService::getData();

            $mergedData = OpinionService::mergeData($data, [
                'cookies' => $request->getCookieParams(),
            ]);

            $opinion = OpinionService::getOpinionDto($mergedData);
            $email = GeneralUtility::makeInstance(Email::class)->create($opinion);

            GeneralUtility::makeInstance(Mailer::class)->send($email);

            $success = true;
        } catch (\Throwable $e) {
            $success = false;

            $this->logger->error('Mail send failed: ' . $e->getMessage(), [
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
            ]);
        }

        $message = [
            'title'   => LocalizationUtility::translate('LLL:EXT:opinion/Resources/Private/Language/locallang_be.xlf:msg.title'),
            'body'    => LocalizationUtility::translate('LLL:EXT:opinion/Resources/Private/Language/locallang_be.xlf:msg.' . ($success ? 'success' : 'error')),
            'success' => $success,
        ];

        return new JsonResponse($message);
    }
}
