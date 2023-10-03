<?php

declare(strict_types=1);

namespace Supseven\Opinion\Service;

use DateTime;
use Exception;

use function json_decode;

use Supseven\Opinion\Domain\Model\Dto\Opinion;

use TYPO3\CMS\Core\Utility\GeneralUtility;

class OpinionService
{
    /**
     * the whole data sent by the system, including base64 encoded image
     *
     * @return string
     * @throws Exception
     */
    public static function getData(): string
    {
        $data = file_get_contents('php://input');

        if (!$data) {
            throw new Exception('Data is not available', 1639475489);
        }

        return $data;
    }

    /**
     * returns a merged array from the decoded json string and additional data
     *
     * @param string $jsonString
     * @param array $data
     * @return array
     * @throws Exception
     */
    public static function mergeData(string $jsonString, array $data): array
    {
        $json = json_decode($jsonString, true);

        if (!$json) {
            throw new Exception('Unable to decode JSON String.', 1639476217);
        }

        return array_merge($json, $data);
    }

    /**
     * returns the logged in username
     *
     * @return string
     */
    public static function getBeUserName(): string
    {
        if (!$GLOBALS['BE_USER']->user) {
            throw new Exception('No BE_USER Object available. User must be logged in.', 1639476847);
        }

        return $GLOBALS['BE_USER']->user['username'];
    }

    /**
     * checks the be user object and the inserted email address
     *
     * @return string
     * @throws Exception
     */
    public static function checkBeUser(): string
    {
        if (!$GLOBALS['BE_USER']->user) {
            throw new Exception('No BE_USER Object available. User must be logged in.', 1639476847);
        }

        if ($GLOBALS['BE_USER']->user['email'] === '') {
            throw new Exception('No E-Mail Address available. Please check BE-User Settings', 1639477116);
        }

        self::checkEmail($GLOBALS['BE_USER']->user['email']);

        return $GLOBALS['BE_USER']->user['email'];
    }

    public static function checkEmail(string $email)
    {
        if (GeneralUtility::validEmail($email) === false) {
            throw new Exception('The E-Mail Address is invalid', 1639476971);
        }

        return $email;
    }

    /**
     * returns a fully filled opinion dto
     *
     * @param array $data
     * @return Opinion
     * @throws Exception
     */
    public static function getOpinionDto(array $data): Opinion
    {
        $opinion = new Opinion();
        $opinion->setTime(new DateTime($data['time']));
        $opinion->setMessage($data['message'] ?? '');
        $opinion->setBrowser($data['browser'] ?? []);
        $opinion->setDocument($data['document'] ?? []);
        $opinion->setDisplay($data['display'] ?? []);
        $opinion->setPage($data['page'] ?? []);

        if ($data['screenshot']) {
            $opinion->setScreenshot(Image::saveImage($data['screenshot']));
        }

        $opinion->setCookies($data['cookies'] ?: []);

        return $opinion;
    }
}
