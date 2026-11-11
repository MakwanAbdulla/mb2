<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\SectionPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SectionPostCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SectionPost $post,
        public readonly Comment     $comment,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsEmailForNotifications()) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $commenter = $this->comment->author->name ?? 'Någon';

        return (new MailMessage)
            ->subject("{$commenter} kommenterade i {$this->post->section->name}")
            ->line("{$commenter} har kommenterat i \"{$this->post->section->name}\".")
            ->line('"' . \Illuminate\Support\Str::limit($this->comment->body, 120) . '"')
            ->action('Se diskussionen', route('groups.show', $this->post->section));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $commenter = $this->comment->author->name ?? 'Någon';

        return [
            'message' => "{$commenter} kommenterade i {$this->post->section->name}.",
            'url'     => route('groups.show', $this->post->section),
            'icon'    => 'chat',
        ];
    }
}
