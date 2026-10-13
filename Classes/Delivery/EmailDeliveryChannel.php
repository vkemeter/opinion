<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Delivery;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MailUtility;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Formatting\DataFlattener;

final readonly class EmailDeliveryChannel implements DeliveryChannelInterface
{
    private const int SUBJECT_MESSAGE_LENGTH = 60;

    public function __construct(
        private MailerInterface $mailer,
        private DataFlattener $dataFlattener,
    ) {}

    public function getIdentifier(): string
    {
        return 'email';
    }

    public function deliver(Submission $submission, ?File $screenshot, OpinionConfiguration $configuration): DeliveryResult
    {
        if ($configuration->recipients === []) {
            return DeliveryResult::failure($this->getIdentifier(), 'No valid recipient configured in the extension configuration.');
        }

        $sender = $submission->systemInfo['backendUser'] ?? [];
        $sender = is_array($sender) ? $sender : [];

        $email = new FluidEmail();
        $email
            ->to(...$configuration->recipients)
            ->from(new Address(MailUtility::getSystemFromAddress(), MailUtility::getSystemFromName() ?? ''))
            ->subject($this->buildSubject($submission, $configuration))
            ->format(FluidEmail::FORMAT_BOTH)
            ->setTemplate('Opinion/Feedback')
            ->assignMultiple([
                'submission' => $submission,
                'sender' => $sender,
                'clientInfo' => $this->dataFlattener->flatten($submission->clientInfo),
                'systemInfo' => $this->dataFlattener->flatten(array_diff_key($submission->systemInfo, ['backendUser' => true])),
                'context' => $this->dataFlattener->flatten($submission->context),
                'hasScreenshot' => $screenshot instanceof File,
            ]);

        $senderEmail = (string)($sender['email'] ?? '');
        if (GeneralUtility::validEmail($senderEmail)) {
            $senderName = (string)($sender['realName'] ?? '');
            $email->replyTo(new Address($senderEmail, $senderName !== '' ? $senderName : (string)($sender['username'] ?? '')));
        }

        if ($screenshot instanceof File) {
            $content = $screenshot->getContents();
            $email
                ->embed($content, 'screenshot', $screenshot->getMimeType())
                ->attach($content, 'screenshot.' . $screenshot->getExtension(), $screenshot->getMimeType());
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $email->setRequest($request);
        }

        $this->mailer->send($email);

        return DeliveryResult::success($this->getIdentifier(), 'sent to ' . implode(', ', $configuration->recipients));
    }

    public function buildSubject(Submission $submission, OpinionConfiguration $configuration): string
    {
        $message = trim((string)preg_replace('/\s+/u', ' ', $submission->message));
        if (mb_strlen($message) > self::SUBJECT_MESSAGE_LENGTH) {
            $message = rtrim(mb_substr($message, 0, self::SUBJECT_MESSAGE_LENGTH)) . '…';
        }

        return ltrim(sprintf('%s [%s/%s] %s', $configuration->subjectPrefix, $submission->category->value, $submission->severity->value, $message));
    }
}
