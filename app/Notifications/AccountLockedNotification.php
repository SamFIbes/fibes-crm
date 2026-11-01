<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountLockedNotification extends Notification
{
    use Queueable;

    public function __construct(public int $minutes) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $previous = app()->getLocale();
        $candidate = $notifiable->locale ?? null;
        $locale = is_string($candidate) && $candidate !== '' ? $candidate : $previous;

        app()->setLocale($locale);

        try {
            return (new MailMessage)
                ->subject(__('mail.lockout_subject'))
                ->greeting(__('mail.lockout_greeting', ['name' => $notifiable->name]))
                ->line(__('mail.lockout_line', ['count' => $this->minutes]))
                ->line(__('mail.lockout_ignore'))
                ->salutation(__('mail.salutation'));
        } finally {
            app()->setLocale($previous);
        }
    }
}
