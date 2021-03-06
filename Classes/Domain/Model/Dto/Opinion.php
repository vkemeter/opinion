<?php

declare(strict_types=1);

namespace Supseven\Opinion\Domain\Model\Dto;

use DateTime;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Opinion extends AbstractEntity
{
    /** @var DateTime */
    protected $time;

    /** @var string */
    protected $message;

    /** @var array */
    protected $browser = [];

    /** @var array */
    protected $document = [];

    /** @var array */
    protected $display = [];

    /** @var array */
    protected $page = [];

    /** @var File */
    protected $screenshot;

    /** @var array */
    protected $cookies = [];

    /** @var BackendUserAuthentication */
    protected $be_user;

    /**
     * @return DateTime
     */
    public function getTime(): DateTime
    {
        return $this->time;
    }

    /**
     * @param DateTime $time
     */
    public function setTime(DateTime $time): void
    {
        $this->time = $time;
    }

    /**
     * @return array
     */
    public function getBrowser(): array
    {
        return $this->browser;
    }

    /**
     * @param array $browser
     */
    public function setBrowser(array $browser): void
    {
        $this->browser = $browser;
    }

    /**
     * @return array
     */
    public function getDocument(): array
    {
        return $this->document;
    }

    /**
     * @param array $document
     */
    public function setDocument(array $document): void
    {
        $this->document = $document;
    }

    /**
     * @return array
     */
    public function getDisplay(): array
    {
        return $this->display;
    }

    /**
     * @param array $display
     */
    public function setDisplay(array $display): void
    {
        $this->display = $display;
    }

    /**
     * @return array
     */
    public function getPage(): array
    {
        return $this->page;
    }

    /**
     * @param array $page
     */
    public function setPage(array $page): void
    {
        $this->page = $page;
    }

    /**
     * @return File|null
     */
    public function getScreenshot(): ?File
    {
        if ($this->screenshot instanceof File) {
            return $this->screenshot;
        }

        return null;
    }

    /**
     * @param File $screenshot
     */
    public function setScreenshot(File $screenshot): void
    {
        $this->screenshot = $screenshot;
    }

    /**
     * @return array
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * @param array $cookies
     */
    public function setCookies(array $cookies): void
    {
        $this->cookies = $cookies;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @param string $message
     */
    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    /**
     * @return BackendUserAuthentication
     */
    public function getBeUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
