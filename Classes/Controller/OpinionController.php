<?php

declare(strict_types=1);

namespace Supseven\Opinion\Controller;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Supseven\Opinion\Service\Email;
use Supseven\Opinion\Service\OpinionService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class OpinionController extends ActionController
{
    /** @var array Settings */
    protected $tsSettings = [];

    /** @var mixed|object|LoggerAwareInterface|ExtensionConfiguration|SingletonInterface */
    protected $extensionConfiguration;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $this->tsSettings = $this->extensionConfiguration->get('opinion');
        $this->logger = $logger;
    }

    public function opinionAction(): ResponseInterface
    {
        try {
            $data = OpinionService::getData();

            $mergedData = OpinionService::mergeData($data, [
                'cookies' => $this->request->getCookieParams(),
            ]);

            $opinion = OpinionService::getOpinionDto($mergedData);
            $email = GeneralUtility::makeInstance(Email::class)->create($opinion);

            GeneralUtility::makeInstance(Mailer::class)->send($email);
        } catch (Exception|TransportExceptionInterface $e) {
            $this->logger->error('FE-Mail send failed: {message} with code {code}', [
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
            ]);
        }

        return $this->htmlResponse();
    }

    public function opinionBackendAction(
        ServerRequestInterface $request,
        ResponseInterface $response = null
    ): ResponseInterface {
        try {
            $data = OpinionService::getData();

            $mergedData = OpinionService::mergeData($data, [
                'cookies' => $request->getCookieParams(),
            ]);

            $opinion = OpinionService::getOpinionDto($mergedData);
            $email = GeneralUtility::makeInstance(Email::class)->create($opinion);

            GeneralUtility::makeInstance(Mailer::class)->send($email);

            $message = GeneralUtility::makeInstance(FlashMessage::class,
                'The Message was sent successfully',
                '[OPINION] Message sent.',
                FlashMessage::OK,
                true
            );
        } catch (Exception|TransportExceptionInterface $e) {
            $message = GeneralUtility::makeInstance(FlashMessage::class,
                'The Message was not sent',
                '[OPINION] Message not sent.',
                FlashMessage::ERROR,
                true
            );

            $this->logger->error('FE-Mail send failed: {message} with code {code}', [
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
            ]);
        }

        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $messageQueue->addMessage($message);

        return new HtmlResponse('');
    }
}
