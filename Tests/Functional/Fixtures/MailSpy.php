<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Fixtures;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;

/**
 * Mail transport boundary for tests: records every message instead of sending it.
 */
final class MailSpy implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->messages[] = $message;
    }

    public function getSentMessage(): ?SentMessage
    {
        return null;
    }

    public function getTransport(): TransportInterface
    {
        return new NullTransport();
    }

    public function getRealTransport(): TransportInterface
    {
        return new NullTransport();
    }
}
