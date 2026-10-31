<?php

namespace App\Livewire;

use App\Services\Impersonation;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The header bell: the web view of the in-app inbox the mobile app reads
 * through NotificationsController — same table, same seven-day window.
 */
class NotificationBell extends Component
{
    private const INBOX_DAYS = 7;

    private const INBOX_LIMIT = 20;

    private const TYPE_LABELS = [
        'daily' => 'Daily summary',
        'weekly' => 'Weekly summary',
        'monthly' => 'Monthly summary',
        'yearly' => 'Yearly summary',
        'custom' => 'Summary',
    ];

    private const TYPE_ICONS = [
        'daily' => 'fa-calendar-day',
        'weekly' => 'fa-calendar-week',
        'monthly' => 'fa-calendar',
        'yearly' => 'fa-calendar',
        'custom' => 'fa-file-lines',
    ];

    #[Locked]
    public int $teamId;

    public function mount(int $teamId): void
    {
        $this->teamId = $teamId;
    }

    public function markRead(int $notificationId): void
    {
        if (Impersonation::isReadOnly()) {
            return;
        }

        $this->inbox()->where('id', $notificationId)->update(['is_read' => 1, 'updated_at' => now()]);
    }

    public function markAllRead(): void
    {
        if (Impersonation::isReadOnly()) {
            return;
        }

        $this->inbox()->where('is_read', 0)->update(['is_read' => 1, 'updated_at' => now()]);
    }

    public function render(): View
    {
        $notifications = $this->inbox()
            ->orderByDesc('created_at')
            ->limit(self::INBOX_LIMIT)
            ->get(['id', 'type', 'subject', 'body', 'is_read', 'created_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'subject' => $row->subject,
                'body' => $row->body,
                'unread' => ! $row->is_read,
                'icon' => self::TYPE_ICONS[$row->type] ?? 'fa-bell',
                'meta' => (self::TYPE_LABELS[$row->type] ?? 'Notification').' · '.Carbon::parse($row->created_at)->diffForHumans(),
            ]);

        return view('livewire.notification-bell', [
            'notifications' => $notifications,
            'unreadCount' => $this->inbox()->where('is_read', 0)->count(),
            'canMarkRead' => ! Impersonation::isReadOnly(),
        ]);
    }

    /**
     * This user's notifications for this factory, inside the inbox window.
     */
    private function inbox(): Builder
    {
        return DB::table('notifications')
            ->where('team_id', $this->teamId)
            ->where('user_id', Auth::id())
            ->where('date', '>=', now()->subDays(self::INBOX_DAYS)->toDateString());
    }
}
