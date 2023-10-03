<?php

declare(strict_types=1);

namespace Supseven\Opinion\Service;

use Exception;
use Psr\Log\LoggerAwareInterface;
use Supseven\Opinion\Domain\Model\Dto\Opinion;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

class Email
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

    public function create(Opinion $opinion): FluidEmail
    {
        try {
            $beUserEmailAddress = OpinionService::checkBeUser();
            $toAddress = OpinionService::checkEmail($this->tsSettings['emailAddress']);
        } catch (Exception $e) {
            DebuggerUtility::var_dump($e);
            // @TODO: add log and do something
            die();
        }

        $fluidEmail = GeneralUtility::makeInstance(FluidEmail::class);
        $fluidEmail
            ->to($toAddress)
            ->from(
                new Address(
                    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'],
                    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName']
                )
            )
            ->replyTo(
                new Address(
                    $beUserEmailAddress,
                    $GLOBALS['BE_USER']->user['realName'] ?: $GLOBALS['BE_USER']->user['username']
                )
            )
            ->subject($this->tsSettings['subject'] ?: 'Opinion E-Mail Subject not set in TypoScript Settings')
            ->format('html')
            ->setTemplate('EMail')
            ->assignMultiple([
                'opinion'  => $opinion,
                'siteName' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'],
            ]);

        $logoPath = GeneralUtility::makeInstance(PackageManager::class)->resolvePackagePath('EXT:opinion/Resources/Public/Icons/actions-document-opinion.svg');
        $fluidEmail->assign('test', $logoPath);
        $fluidEmail->embedFromPath(
            $logoPath,
            'logo'
        );

        if ($opinion->getScreenshot()) {
            $path = Environment::getPublicPath() . DIRECTORY_SEPARATOR . $opinion->getScreenshot()->getPublicUrl();
            $fluidEmail->embedFromPath(
                $path,
                'screenshot'
            );
            $fluidEmail->assign('hasScreenshot', 'true');
        }

        return $fluidEmail;
    }
}
