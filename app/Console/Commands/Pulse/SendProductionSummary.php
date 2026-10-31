<?php

namespace App\Console\Commands\Pulse;

use App\Models\Team;
use App\Services\Pulse\ProductionSummaryService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendProductionSummary extends Command
{
    protected $signature = 'production:send-summary {--type=weekly} {--team_ids=*}';

    protected $description = 'Send production summary for weekly, monthly, or yearly periods';

    public function handle()
    {
        $inputTeams = $this->option('team_ids') ?? null;
        $teams = Team::query()
            ->when($inputTeams, fn ($query) => $query->whereIn('id', $inputTeams))
            ->whereHas('pulse', function ($query) {
                $query->where(function ($pulseQuery) {
                    $pulseQuery->where('modules->pulse->modules->pith->is_active', true)
                        ->orWhere('modules->pulse->modules->fibre->is_active', true)
                        ->orWhere('modules->pulse->modules->growbags->is_active', true);
                });
            })->get();

        $type = $this->option('type');
        $now = Carbon::now();

        switch ($type) {
            case 'weekly':
                $startDate = $now->copy()->subWeek()->startOfWeek(Carbon::MONDAY);
                $endDate = $now->copy()->subWeek()->endOfWeek(Carbon::SUNDAY);

                if ($startDate->format('M') === $endDate->format('M')) {
                    $dateRange = $startDate->format('M j').' - '.$endDate->format('j');
                } else {
                    $dateRange = $startDate->format('M j').' - '.$endDate->format('M j');
                }

                $subject = "Week ({$dateRange})";
                break;

            case 'monthly':
                $startDate = $now->copy()->subMonthNoOverflow()->startOfMonth();
                $endDate = $now->copy()->subMonthNoOverflow()->endOfMonth();
                $subject = "Month ({$startDate->format('M Y')})";
                break;

            case 'yearly':
            default:
                $startDate = $now->copy()->subYear()->startOfYear();
                $endDate = $now->copy()->subYear()->endOfYear();
                $subject = "Year ({$startDate->format('Y')})";
                break;
        }

        foreach ($teams as $team) {
            // $this->info("Team: {$team->name}");
            $service = new ProductionSummaryService($team->id, $team);

            if ($team->has_pith_module) {
                $mainModuleSummary = $service->getPithLoadsSummaryByShift($startDate, $endDate);
                $service->buildSummaryAndNotify($mainModuleSummary, $subject, $startDate, $endDate, 'pith', $type);
            }

            if ($team->has_fibre_module) {
                $mainModuleSummary = $service->getFibreBalesSummaryByShift($startDate, $endDate);
                $service->buildSummaryAndNotify($mainModuleSummary, $subject, $startDate, $endDate, 'fibre', $type);
            }

            if ($team->has_growbags_module) {
                $mainModuleSummary = $service->getGrowBagsSummaryByShift($startDate, $endDate);
                $service->buildSummaryAndNotify($mainModuleSummary, $subject, $startDate, $endDate, 'growbags', $type);
            }
        }

        $this->info("All {$type} summaries sent successfully! Range: {$startDate} to {$endDate}");
    }
}
