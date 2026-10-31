<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationsController extends Controller
{
    /**
     * Get notifications from last 7 days + unread count
     */
    public function index(Request $request)
    {
        $userId = Auth::user()->id;
        $currentTeamId = Auth::user()->currentTeam->id;

        Log::info("Fetching notifications for user {$userId} in team {$currentTeamId}");

        // notifications from last 7 days for this user in this team
        $notifications = DB::table('notifications')
            ->where('team_id', $currentTeamId)
            ->where('user_id', $userId)
            ->where('date', '>=', Carbon::now()->subDays(7)->toDateString())
            ->orderBy('created_at', 'desc')
            ->get();

        // unread count
        $unreadCount = $notifications->where('is_read', 0)->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markAsRead(Request $request, $ids)
    {
        $userId = Auth::user()->id;
        $currentTeamId = Auth::user()->currentTeam->id;

        $idsArray = explode(',', $ids);

        DB::table('notifications')
            ->where('team_id', $currentTeamId)
            ->where('user_id', $userId)
            ->whereIn('id', $idsArray)
            ->update(['is_read' => 1]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }

    public function markAsReadIndividual(Request $request, $id)
    {
        $userId = Auth::user()->id;
        $currentTeamId = Auth::user()->currentTeam->id;

        DB::table('notifications')
            ->where('id', $id)
            ->where('team_id', $currentTeamId)
            ->where('user_id', $userId)
            ->update(['is_read_individual' => 1]);

        return response()->json(['message' => 'Notification marked as read individually.']);
    }
}
