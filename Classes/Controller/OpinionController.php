<?php
declare(strict_types = 1);
namespace Supseven\Opinion\Controller;

use Supseven\Opinion\Domain\Model\Dto\Opinion;
use Supseven\Opinion\Service\Image;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class OpinionController extends ActionController
{
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

            $opinion = new Opinion();
            $opinion->setTime(new \DateTime($decodedData['time']));
            $opinion->setBrowser($decodedData['browser']);
            $opinion->setDocument($decodedData['document']);
            $opinion->setDisplay($decodedData['display']);
            $opinion->setPage($decodedData['page']);
            $opinion->setScreenshot(Image::saveImage($decodedData['screenshot']));
            $opinion->setCookies($decodedData['cookies']);

            $email = GeneralUtility::makeInstance(FluidEmail::class);
            $email
                ->to('v.kemeter@supseven.at')
                ->from(new Address('volker@kemeter.de', 'Volker'))
                ->subject('Opinion Feedback')
                ->format('html') // send HTML and plaintext mail
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
}
