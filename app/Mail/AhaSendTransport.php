<?php

namespace App\Mail;

use App\Services\Reporting\AhaSendMailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel mail driver ("ahasend") that sends through the AhaSend API, so
 * login codes use the same account as the reports and need no SMTP setup.
 */
class AhaSendTransport extends AbstractTransport
{
    public function __construct(private AhaSendMailer $mailer)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? null;

        $id = $this->mailer->deliver(
            recipients: array_map(
                fn (Address $address) => array_filter(['email' => $address->getAddress(), 'name' => $address->getName()]),
                $message->getEnvelope()->getRecipients(),
            ),
            subject: (string) $email->getSubject(),
            html: $email->getHtmlBody() ? (string) $email->getHtmlBody() : null,
            text: $email->getTextBody() ? (string) $email->getTextBody() : null,
            from: $from ? array_filter(['email' => $from->getAddress(), 'name' => $from->getName()]) : null,
        );

        if ($id) {
            $message->setMessageId($id);
        }
    }

    public function __toString(): string
    {
        return 'ahasend';
    }
}
