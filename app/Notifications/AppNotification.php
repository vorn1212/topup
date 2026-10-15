<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base class for every notification of the shop.
 *
 *  - Always stored in the database (shown on the website).
 *  - Also e-mailed when NOTIFY_BY_MAIL=true (and the user has an e-mail address).
 *  - Queued and sent only after the surrounding database transaction committed, so a customer is
 *    never told about something that was rolled back.
 *  - Carries plain text only (no models), so a deleted row can never break a queued job.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    abstract protected function title(): string;

    abstract protected function body(): string;

    /** A path on this site, for example /orders/ORD-... (never a full external URL). */
    abstract protected function link(): string;

    abstract protected function kind(): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('topup.notifications.mail') && filled($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @return array<string,string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->link(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->action('Open', url($this->link()));
    }
}