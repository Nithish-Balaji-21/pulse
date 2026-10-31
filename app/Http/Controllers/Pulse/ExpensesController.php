<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Services\ExpenseConnection;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ExpensesController extends Controller
{
    public function summary(Request $request, ExpenseConnection $connection)
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if(Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 366, 422);
        $report = $connection->report(app('current.team'), $data['from'], $data['to']);

        return response()->json($report ? ['connected' => true, 'totals' => $report['totals'], 'has_entries' => count($report['rows']) > 0] : ['connected' => false])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, ExpenseConnection $connection)
    {
        $team = app('current.team');
        $timezone = $request->query('timezone', config('app.timezone', 'Asia/Kolkata'));
        $request->merge(['timezone' => $timezone]);
        $request->validate(['timezone' => 'required|timezone']);
        $from = $request->query('from', $request->query('start_date', now($timezone)->startOfMonth()->toDateString()));
        $to = $request->query('to', $request->query('end_date', now($timezone)->toDateString()));
        $request->merge(['from' => $from, 'to' => $to]);
        $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            return back()->withErrors(['to' => 'Choose a range of at most 367 days.']);
        }
        $tab = $request->query('tab', 'staff');
        if ($tab === 'overview') {
            $tab = 'staff';
        }
        abort_unless(in_array($tab, ['staff', 'other'], true), 422);
        $report = $connection->details($team, $from, $to);

        if ($request->routeIs('reports.expenses')) {
            $category = $request->query('category', 'all');
            abort_unless(in_array($category, ['all', 'staff', 'activity', 'vehicle', 'material'], true), 422);
            $rows = $report ? $connection->rows($report) : [];
            $activityWages = ExpenseConnection::activityWages($rows);
            $rows = ExpenseConnection::filterRows($rows, $category);
            $filteredTotal = array_sum(array_column($rows, 'amount'));
            $data = compact('team', 'from', 'to', 'report', 'category', 'timezone', 'rows', 'filteredTotal', 'activityWages');
            if ($request->query('export') === 'pdf') {
                abort_unless($report, 503, 'Expenses unavailable');

                return Pdf::loadView('pulse::expenses.report-pdf', $data)->setPaper('a4', 'landscape')->download('alam-cocos-expenses-'.$from.'-'.$to.'.pdf');
            }

            return response()->view('pulse::expenses.report', $data)->header('Cache-Control', 'no-store');
        }

        return response()->view('pulse::expenses.index', compact('team', 'from', 'to', 'report', 'tab'))->header('Cache-Control', 'no-store');
    }
}
