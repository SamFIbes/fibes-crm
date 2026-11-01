<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

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
            $url = url(route('password.reset', [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject(__('mail.reset_subject'))
                ->greeting(__('mail.reset_greeting', ['name' => $notifiable->name]))
                ->line(__('mail.reset_line', ['count' => $minutes]))
                ->action(__('mail.reset_action'), $url)
                ->line(__('mail.reset_ignore'))
                ->salutation(__('mail.salutation'));
        } finally {
            app()->setLocale($previous);
        }
    }
}
