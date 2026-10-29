<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** One entry in the admin's notification bell (database channel only). */
class AdminNotice extends Notification
{
    /** @param  array<string, string>  $lines  label => value details shown under the title */
    public function __construct(
        public string $kind,
        public string $title,
        public array $lines = [],
        public ?string $path = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'lines' => $this->lines, 'path' => $this->path];
    }
}
