<?php

namespace App\Http\Controllers\Pulse\Api;

use App\Http\Controllers\Controller;
use App\Services\ExpenseConnection;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Mobile counterpart of ExpensesController. Same access rule as the web: any
 * member of a factory whose Expenses connection is live, no extra permission.
 *
 * Responses separate "not connected" (the app hides the card / report) from
 * "connected but the Expenses API failed" (503, the app offers Retry), which
 * the web summary endpoint folds into one connected:false.
 */
class ExpensesApiController extends Controller
{
    private const CATEGORIES = ['all', 'staff', 'activity', 'vehicle', 'material'];

    public function status(ExpenseConnection $connection)
    {
        return response()->json(['connected' => $connection->connected(app('current.team'))])->header('Cache-Control', 'no-store');
    }

    public function summary(Request $request, ExpenseConnection $connection)
    {
        [$from, $to] = $this->range($request);
        $team = app('current.team');
        if (! $connection->connected($team)) {
            return response()->json(['connected' => false])->header('Cache-Control', 'no-store');
        }
        $report = $connection->report($team, $from, $to);
        if (! $report) {
            return response()->json(['connected' => true, 'message' => 'Expenses unavailable'], 503);
        }

        return response()->json(['connected' => true, 'from' => $from, 'to' => $to, 'totals' => $report['totals'], 'has_entries' => count($report['rows']) > 0])->header('Cache-Control', 'no-store');
    }

    public function report(Request $request, ExpenseConnection $connection)
    {
        [$from, $to] = $this->range($request);
        $team = app('current.team');
        if (! $connection->connected($team)) {
            return response()->json(['connected' => false])->header('Cache-Control', 'no-store');
        }
        $details = $connection->details($team, $from, $to);
        if (! $details) {
            return response()->json(['connected' => true, 'message' => 'Expenses unavailable'], 503);
        }
        $rows = $connection->rows($details);

        return response()->json([
            'connected' => true,
            'from' => $from,
            'to' => $to,
            'summary' => [
                'staff' => (float) $details['totals']['staff'],
                'staff_count' => count($details['staff']['rows']),
                'activity' => ExpenseConnection::activityWages($rows),
                'other' => (float) $details['totals']['other'],
                'total' => (float) $details['totals']['total'],
            ],
            'rows' => $rows,
        ])->header('Cache-Control', 'no-store');
    }

    public function pdf(Request $request, ExpenseConnection $connection)
    {
        [$from, $to] = $this->range($request);
        $category = $request->query('category', 'all');
        abort_unless(in_array($category, self::CATEGORIES, true), 422);
        $team = app('current.team');
        $report = $connection->connected($team) ? $connection->details($team, $from, $to) : null;
        abort_unless($report, 503, 'Expenses unavailable');
        $rows = $connection->rows($report);
        $activityWages = ExpenseConnection::activityWages($rows);
        $rows = ExpenseConnection::filterRows($rows, $category);
        $filteredTotal = array_sum(array_column($rows, 'amount'));
        $timezone = config('app.timezone', 'Asia/Kolkata');

        return Pdf::loadView('pulse::expenses.report-pdf', compact('team', 'from', 'to', 'report', 'category', 'timezone', 'rows', 'filteredTotal', 'activityWages'))
            ->setPaper('a4', 'landscape')
            ->download('alam-cocos-expenses-'.$from.'-'.$to.'.pdf');
    }

    private function range(Request $request): array
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if(Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 366, 422, 'Choose a range of at most 367 days.');

        return [$data['from'], $data['to']];
    }
}
