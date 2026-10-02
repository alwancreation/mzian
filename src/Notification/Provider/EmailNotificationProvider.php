<?php

declare(strict_types=1);

namespace App\Notification\Provider;

use App\Notification\Entity\Notification;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * E-mail channel based on Symfony Mailer (SMTP, SES, Mailgun... via MAILER_DSN).
 */
final readonly class EmailNotificationProvider implements NotificationProviderInterface
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MZIAN_MAIL_FROM)%')]
        private string $from,
    ) {
    }

    public function supports(string $channel): bool
    {
        return 'email' === $channel;
    }

    public function send(Notification $notification): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->from, 'Mzian.net'))
            ->to($notification->getRecipientEmail())
            ->subject($notification->getSubject())
            ->htmlTemplate('emails/notification.html.twig')
            ->textTemplate('emails/notification.txt.twig')
            ->context([
                'subject' => $notification->getSubject(),
                'body' => $notification->getBody(),
                'action_url' => $notification->getContext()['url'] ?? null,
                'action_label' => $notification->getContext()['action_label'] ?? null,
            ]);
        $email->getHeaders()->addTextHeader('X-Mzian-Notification', $notification->getType()->value);

        // Sent synchronously here: this provider already runs inside an async job.
        $this->mailer->send($email);
    }
}
