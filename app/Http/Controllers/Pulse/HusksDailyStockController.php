<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\HusksDailyStock;
use App\Models\Pulse\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class HusksDailyStockController extends Controller
{
    /**
     * Get the timeline history for the Husks Inventory tab.
     */
    public function index(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $perPage = (int) $request->get('per_page', 31);

        $query = HusksDailyStock::where('team_id', $currentTeamId);

        if ($request->has('month') && $request->has('year')) {
            $query->whereMonth('date', $request->month)
                ->whereYear('date', $request->year);
        }

        $stocks = $query->orderBy('date', 'desc')
            ->paginate($perPage)
            ->through(function ($stock) {
                return [
                    'id' => $stock->id,
                    'date' => $stock->date->format('d/m/Y'),
                    'closing_stock' => (float) $stock->closing_stock,
                    'opening_stock' => (float) $stock->opening_stock,
                    'husks_received' => is_null($stock->husks_received) ? null : (float) $stock->husks_received,
                    'cft' => (float) $stock->cft,
                    'husks_correction' => (float) ($stock->husks_correction ?? 0),
                    'correction_comment' => $stock->correction_comment,
                    'correction_type' => $stock->husks_correction >= 0 ? 'Added' : 'Deducted',
                ];
            });

        return response()->json($stocks);
    }

    /**
     * Get the summary for the Husks Inventory tab.
     */
    public function summary(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        $yesterdayDate = Carbon::yesterday()->toDateString();
        $record = HusksDailyStock::where('team_id', $currentTeamId)
            ->where('date', $yesterdayDate)
            ->first();

        // If no record exists for yesterday, and we are explicitly requested to initialize it
        if (! $record && $request->has('initialize_yesterday')) {
            $openingStock = HusksDailyStock::where('team_id', $currentTeamId)
                ->latest('date')
                ->value('closing_stock') ?? 0;

            $record = HusksDailyStock::create([
                'team_id' => $currentTeamId,
                'date' => $yesterdayDate,
                'opening_stock' => $openingStock,
                'closing_stock' => $openingStock,
                'cft' => 0,
                'husks_received' => null,
                'husks_correction' => 0,
                'correction_comment' => null,
            ]);

            // Run stored procedure for initial reconciliation
            DB::statement('CALL reconcile_husks_stock(?, ?)', [$currentTeamId, $yesterdayDate]);
            $record->refresh();
        }

        $isPending = ! $record || is_null($record->husks_received);

        $stock = HusksDailyStock::where('team_id', $currentTeamId)
            ->orderBy('date', 'desc')
            ->first();

        if (! $stock) {
            return response()->json(['data' => null]);
        }

        $received = is_null($stock->husks_received) ? null : (float) $stock->husks_received;
        $correction = (float) ($stock->husks_correction ?? 0);
        $opening = (float) $stock->opening_stock;
        $closing = (float) $stock->closing_stock;

        // processed = opening + received + correction - closing
        $processed = $opening + $received + $correction - $closing;

        return response()->json([
            'data' => [
                'id' => $stock->id,
                'date' => $stock->date->format('Y-m-d'),
                'opening_stock' => $opening,
                'received' => $received,
                'correction' => $correction,
                'in_stock' => $closing,
                'processed' => $processed > 0 ? $processed : 0,
                'correction_comment' => $stock->correction_comment,
                'is_yesterday_pending' => $isPending,
            ],
        ]);
    }

    /**
     * Get the historical trend for the last N days.
     */
    public function trend(Request $request)
    {
        $days = (int) request('days', 7);
        $currentTeamId = Auth::user()->currentTeam->id;

        $trend = HusksDailyStock::where('team_id', $currentTeamId)
            ->orderBy('date', 'desc')
            ->take($days)
            ->get()
            ->map(function ($stock) {
                return [
                    'date' => $stock->date->format('Y-m-d'),
                    'date_short' => $stock->date->format('d/m'),
                    'closing_stock' => (float) $stock->closing_stock,
                    'husks_correction' => (float) ($stock->husks_correction ?? 0),
                    'has_correction' => ! is_null($stock->husks_correction) && $stock->husks_correction != 0,
                ];
            })
            ->reverse()
            ->values();

        return response()->json(['data' => $trend]);
    }

    /**
     * Check if the module is enabled for the team.
     */
    public function status()
    {
        return response()->json([
            'enabled' => (bool) Auth::user()->currentTeam->has_husks_stock_module,
        ]);
    }

    /**
     * Check if the user needs to see the daily checklist popup.
     */
    public function showDailyChecklist(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        $currentTeam = $user->currentTeam;

        // The prompt needs both halves: `update` so we never nag someone who could not submit the
        // entry anyway (this also covers read-only impersonation, which withdraws write actions),
        // and `daily_reminder` so only the role an owner designates actually gets interrupted.
        if (! $currentTeam->has_husks_stock_module
            || ! Gate::allows('husk_inventory.update')
            || ! Gate::allows('husk_inventory.daily_reminder')) {
            return response()->json(['show_checklist' => false]);
        }

        // Team must have shifts configured before the checklist applies
        if (! Shift::activeForTeam($currentTeam->id)->exists()) {
            return response()->json(['show_checklist' => false]);
        }

        $shiftStartTime = Carbon::parse(Shift::anchorForTeam($currentTeam->id));

        $triggerTime = $shiftStartTime->copy()->addMinutes(config('app.daily_husks_cron_interval_mins'));

        // If Current Time is BEFORE the Trigger Time, do not show checklist
        if (Carbon::now()->lessThan($triggerTime)) {
            return response()->json(['show_checklist' => false]);
        }

        // Check Yesterday's Record
        $yesterday = Carbon::yesterday()->format('Y-m-d');

        $stock = HusksDailyStock::where('team_id', $currentTeam->id)
            ->where('date', $yesterday)
            ->first();

        // If no record exists (e.g. cron failed or didn't run yet), do not show checklist
        if (! $stock) {
            return response()->json(['show_checklist' => false]);
        }

        // Check if pending (Unfilled)
        // Condition: Show checklist (if user_id is NULL) OR husks_received is NULL
        if (is_null($stock->husks_received)) {
            return response()->json([
                'show_checklist' => true,
                'stock_id' => $stock->id,
            ]);
        }

        // If we reach here, it means it is filled (user_id is set AND husks_received is set)
        return response()->json(['show_checklist' => false]);
    }

    /**
     * Get the latest stock record (yesterday's entry) for Checklist Page.
     */
    public function latest(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $yesterday = Carbon::yesterday()->format('Y-m-d');

        $stock = HusksDailyStock::where('team_id', $currentTeamId)
            ->where('date', $yesterday)
            ->first();

        if (! $stock) {
            // Fallback if no cron entry exists yet (e.g., new team)
            return response()->json([
                'message' => 'No stock record found for yesterday.',
                'data' => null,
            ], 200);
        }

        $lastKnown = HusksDailyStock::where('team_id', $currentTeamId)
            ->whereNotNull('husks_received')
            ->latest('date')
            ->first();

        return response()->json([
            'data' => [
                'id' => $stock->id,
                'current_date' => $stock->date->format('d/m/Y'),
                'opening_stock' => (float) $stock->opening_stock,
                'last_received_date' => $lastKnown ? $lastKnown->date->format('d/m/Y') : 'N/A',
                'last_husks_received' => $lastKnown ? (float) $lastKnown->husks_received : 0,
            ],
        ], 200);
    }

    /**
     * Update the stock details (Received & Correction).
     */
    public function update(Request $request)
    {
        // Permission Check
        if (! Gate::allows('husk_inventory.update')) {
            return response()->json(['error' => 'You are not authorized to manage stock records.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        $userId = Auth::id();

        try {
            // Validate Inputs
            $validated = $request->validate([
                'id' => 'required|integer|exists:pulse_husks_daily_stock,id',
                'husks_received' => 'required|numeric|min:0',
                'husks_correction' => 'nullable|numeric',
                'correction_comment' => 'nullable|string|max:100',
            ]);

            // Find the Record
            $stock = HusksDailyStock::where('team_id', $currentTeamId)
                ->where('id', $validated['id'])
                ->first();

            if (! $stock) {
                return response()->json(['error' => 'Record not found or unauthorized.'], 404);
            }

            // Update Database
            $stock->update([
                'user_id' => $userId,
                'husks_received' => $validated['husks_received'],
                'husks_correction' => $validated['husks_correction'],
                'correction_comment' => $validated['correction_comment'],
            ]);

            // Refresh Model
            // Since the DB (Trigger/SP) updated the closing_stock, we must refresh
            // the model to get the new calculated value back to the UI.
            $stock->refresh();

            return response()->json([
                'message' => 'Stock updated successfully.',
                'data' => $stock,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error updating husk stock: '.$e->getMessage());

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
