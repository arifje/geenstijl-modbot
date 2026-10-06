<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * Login code for the admin. Sent right away instead of queued (Filament's
 * default): the queue only runs once a minute and waits for moderation runs.
 */
class LoginCode extends Notification
{
    public function __construct(
        #[SensitiveParameter]
        public string $code,
        public int $codeExpiryMinutes,
    ) {}

    /**
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Je inlogcode voor GeenStijl ModBot')
            ->greeting('Hoi '.$notifiable->name.',')
            ->line("Je inlogcode is: **{$this->code}**")
            ->line("De code is {$this->codeExpiryMinutes} minuten geldig.")
            ->line('Heb je niet geprobeerd in te loggen? Dan kun je deze mail negeren; zonder je wachtwoord kan niemand iets met deze code.')
            ->salutation('GeenStijl ModBot');
    }
}
