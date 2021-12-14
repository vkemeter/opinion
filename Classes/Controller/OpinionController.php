<?php

declare(strict_types=1);

namespace Supseven\Opinion\Controller;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Supseven\Opinion\Service\Email;
use Supseven\Opinion\Service\OpinionService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

class OpinionController extends ActionController
{
    /** @var array Settings */
    protected $tsSettings = [];

    /** @var mixed|object|LoggerAwareInterface|ExtensionConfiguration|SingletonInterface */
    protected $extensionConfiguration;

    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $this->tsSettings = $this->extensionConfiguration->get('opinion');
    }

    public function opinionAction(): void
    {
        try {
            $data = OpinionService::getData();

            $mergedData = OpinionService::mergeData($data, [
                'cookies' => $_COOKIE
            ]);

            $opinion = OpinionService::getOpinionDto($mergedData);
            $email = GeneralUtility::makeInstance(Email::class)->create($opinion);

            GeneralUtility::makeInstance(Mailer::class)->send($email);
        } catch (Exception|TransportExceptionInterface $e) {
            DebuggerUtility::var_dump($e);
            // @TODO: add log and do something
            die();
        }
    }

    public function opinionBackendAction(
        ServerRequestInterface $request,
        ResponseInterface $response = null
    ): ResponseInterface {
        try {
            $data = OpinionService::getData();

            $mergedData = OpinionService::mergeData($data, [
                'cookies' => $_COOKIE
            ]);

            $opinion = OpinionService::getOpinionDto($mergedData);
            $email = GeneralUtility::makeInstance(Email::class)->create($opinion);

            GeneralUtility::makeInstance(Mailer::class)->send($email);

            return new HtmlResponse('');
        } catch (Exception|TransportExceptionInterface $e) {
            DebuggerUtility::var_dump($e);
            // @TODO: add log and do something
            die();
        }
    }
}
