<?php
declare(strict_types = 1);
namespace Supseven\Opinion\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Supseven\Opinion\Domain\Model\Dto\Opinion;
use Supseven\Opinion\Service\Image;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class OpinionController extends ActionController
{
    /** @var array Settings */
    protected $tsSettings = [];

    /** @var mixed|object|\Psr\Log\LoggerAwareInterface|ExtensionConfiguration|\TYPO3\CMS\Core\SingletonInterface */
    protected $extensionConfiguration;

    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $this->tsSettings = $this->extensionConfiguration->get('opinion');
    }

    /**
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     * @return void
     */
    public function opinionAction(): void
    {
        $data = file_get_contents('php://input');

        if ($data) {
            $decodedData = array_merge(\json_decode($data, true), [
               'cookies' => $_COOKIE,
            ]);

            if ($GLOBALS['BE_USER']->user['email'] === '') {
                throw new \TYPO3\CMS\Extbase\Exception('No E-Mail Address set for Your BE-User.');
            }

            $opinion = new Opinion();
            $opinion->setTime(new \DateTime($decodedData['time']));
            $opinion->setMessage($decodedData['message'] ?: 'fix mich');
            $opinion->setBrowser($decodedData['browser']);
            $opinion->setDocument($decodedData['document']);
            $opinion->setDisplay($decodedData['display']);
            $opinion->setPage($decodedData['page']);
            $opinion->setScreenshot(Image::saveImage($decodedData['screenshot']));
            $opinion->setCookies($decodedData['cookies']);

            $email = GeneralUtility::makeInstance(FluidEmail::class);
            $email
                ->to($this->tsSettings['emailAddress'])
                ->from(new Address($GLOBALS['BE_USER']->user['email'], $GLOBALS['BE_USER']->user['realName'] ?: $GLOBALS['BE_USER']->user['username']))
                ->subject($this->tsSettings['subject'])
                ->format('html')
                ->setTemplate('EMail')
                ->assignMultiple([
                    'opinion' => $opinion,
                 ]);

            $path = Environment::getPublicPath() . DIRECTORY_SEPARATOR . $opinion->getScreenshot()->getPublicUrl();
            $email->embedFromPath(
                $path,
                'screenshot'
            );

            GeneralUtility::makeInstance(Mailer::class)->send($email);
        }
    }

    public function opinionBackendAction(ServerRequestInterface $request, ResponseInterface $response = null): ResponseInterface
    {
        $data = file_get_contents('php://input');

        if ($data) {
            $decodedData = array_merge(\json_decode($data, true), [
                'cookies' => $_COOKIE,
            ]);

            if ($GLOBALS['BE_USER']->user['email'] === '') {
                throw new \TYPO3\CMS\Extbase\Exception('No E-Mail Address set for Your BE-User.');
            }

            $opinion = new Opinion();
            $opinion->setTime(new \DateTime($decodedData['time']));
            $opinion->setMessage($decodedData['message'] ?: 'fix mich');
            $opinion->setBrowser($decodedData['browser']);
            $opinion->setDocument($decodedData['document']);
            $opinion->setDisplay($decodedData['display']);
            $opinion->setPage($decodedData['page']);
            $opinion->setScreenshot(Image::saveImage($decodedData['screenshot']));
            $opinion->setCookies($decodedData['cookies']);

            $email = GeneralUtility::makeInstance(FluidEmail::class);
            $email
                ->to($this->tsSettings['emailAddress'])
                ->from(new Address($GLOBALS['BE_USER']->user['email'], $GLOBALS['BE_USER']->user['realName'] ?: $GLOBALS['BE_USER']->user['username']))
                ->subject($this->tsSettings['subject'])
                ->format('html')
                ->setTemplate('EMail')
                ->assignMultiple([
                                     'opinion' => $opinion,
                                 ]);

            $path = Environment::getPublicPath() . DIRECTORY_SEPARATOR . $opinion->getScreenshot()->getPublicUrl();
            $email->embedFromPath(
                $path,
                'screenshot'
            );

            GeneralUtility::makeInstance(Mailer::class)->send($email);
        }

        return new HtmlResponse('<h1>foo</h1>');
    }
}
