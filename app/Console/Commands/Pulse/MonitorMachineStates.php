<?php

namespace App\Console\Commands\Pulse;

use App\Models\Team;
use App\Services\NotificationSenderService;
use App\Services\Pulse\DefibreStateClassifier;
use App\Support\Notifications\NotificationType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorMachineStates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pulse:monitor-machine-states';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitors IoT devices for ON/OFF/SHUTDOWN state changes and sends bundled notifications.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $notifier = app(NotificationSenderService::class);

        $this->monitorPithTeams($notifier);
        $this->monitorFibreTeams($notifier);
        $this->monitorGrowbagTeams($notifier);
    }

    private function monitorPithTeams(NotificationSenderService $notifier): void
    {
        $teams = Team::with([
            'pulse',
            'devices' => function ($query) {
                $query->where('module', 'pith')->where('status', 'active');
            },
        ])->activePulse()->get();

        $eligibleTeams = $teams->filter(function ($team) {
            return $team->has_pith_module && $team->devices->isNotEmpty();
        });

        foreach ($eligibleTeams as $team) {
            $device = $team->devices->first();
            $deviceId = $device->device_id;

            $onUpdates = [];
            $machineOffUpdates = [];
            $shutdownUpdates = [];
            $offTh = $device->off_th ?? 10;

            // ---------------------------------------------------------
            // 1. DEFIBRE (runs in real-time based on last 5 mins)
            // ---------------------------------------------------------
            $latestDefibre = DB::table('pulse_device_data')
                ->where('device_id', $deviceId)
                ->where('datetime', '>=', now()->subMinutes(5))
                ->orderBy('datetime', 'desc')
                ->first();

            if ($latestDefibre) {
                $currentStateDefibre = DefibreStateClassifier::classifyForMonitoring(
                    $latestDefibre->d7,
                    $latestDefibre->d4,
                    $latestDefibre->d5,
                    $latestDefibre->d6,
                    $offTh
                );
                $cacheKeyDefibre = "machine_state_defibre_{$deviceId}";
                $defibreWindowStart = now()->subMinutes(5);

                if (! Cache::has($cacheKeyDefibre)) {
                    Cache::put($cacheKeyDefibre, $currentStateDefibre);
                    Log::info("Set Defibre state '{$currentStateDefibre}' for team {$team->id}.");
                } else {
                    $lastStateDefibre = Cache::get($cacheKeyDefibre);
                    if ($currentStateDefibre !== $lastStateDefibre) {
                        Cache::put($cacheKeyDefibre, $currentStateDefibre);
                        $time = $this->firstDefibreActivityTime(
                            $deviceId,
                            $defibreWindowStart,
                            $currentStateDefibre,
                            $offTh
                        );

                        if ($currentStateDefibre === DefibreStateClassifier::STATE_ON) {
                            $onUpdates[] = "Defibre turned ON at {$time}";
                        } elseif ($currentStateDefibre === DefibreStateClassifier::STATE_SHUTDOWN) {
                            $shutdownUpdates[] = "Power cut detected at {$time}";
                        } else {
                            $machineOffUpdates[] = "Defibre turned OFF at {$time}";
                        }
                    }
                }
            }

            // ---------------------------------------------------------
            // 2. BLOCKS (runs on 10 min window)
            // ---------------------------------------------------------
            if ($team->has_pith_module_blocks) {
                $result = $this->detectActivityStateChange(
                    "machine_state_blocks_{$deviceId}",
                    $deviceId,
                    'pulse_device_data_pith_blocks',
                    'block_datetime',
                    now()->subMinutes(10),
                    'Blocks',
                    $team->id
                );
                $onUpdates = array_merge($onUpdates, $result['on']);
                $machineOffUpdates = array_merge($machineOffUpdates, $result['off']);
            }

            // ---------------------------------------------------------
            // 3. BALES (runs on 15 min window)
            // ---------------------------------------------------------
            if ($team->has_pith_module_bales) {
                $result = $this->detectActivityStateChange(
                    "machine_state_bales_{$deviceId}",
                    $deviceId,
                    'pulse_device_data_fibre_bales',
                    'bale_datetime',
                    now()->subMinutes(15),
                    'Baling',
                    $team->id
                );
                $onUpdates = array_merge($onUpdates, $result['on']);
                $machineOffUpdates = array_merge($machineOffUpdates, $result['off']);
            }

            $this->dispatchMachineAlerts($notifier, $team, $onUpdates, $machineOffUpdates, $shutdownUpdates);
        }
    }

    private function monitorFibreTeams(NotificationSenderService $notifier): void
    {
        $teams = Team::with([
            'pulse',
            'devices' => function ($query) {
                $query->where('module', 'fibre')->where('status', 'active');
            },
        ])->activePulse()->get();

        $eligibleTeams = $teams->filter(function ($team) {
            return $team->has_fibre_module && $team->devices->isNotEmpty();
        });

        foreach ($eligibleTeams as $team) {
            $deviceId = $team->devices->first()->device_id;
            $onUpdates = [];
            $machineOffUpdates = [];

            // ---------------------------------------------------------
            // BALING (15 min window — same table as pith mini-bales)
            // ---------------------------------------------------------
            $result = $this->detectActivityStateChange(
                "machine_state_fibre_bales_{$deviceId}",
                $deviceId,
                'pulse_device_data_fibre_bales',
                'bale_datetime',
                now()->subMinutes(15),
                'Baling',
                $team->id,
                fn ($query) => $query->where('is_duplicate', 0)
            );
            $onUpdates = array_merge($onUpdates, $result['on']);
            $machineOffUpdates = array_merge($machineOffUpdates, $result['off']);

            // Weighing alerts are intentionally not sent at present.

            $this->dispatchMachineAlerts($notifier, $team, $onUpdates, $machineOffUpdates);
        }
    }

    private function monitorGrowbagTeams(NotificationSenderService $notifier): void
    {
        $teams = Team::with([
            'pulse',
            'devices' => function ($query) {
                $query->where('module', 'growbags')->where('status', 'active');
            },
        ])->activePulse()->get();

        $eligibleTeams = $teams->filter(function ($team) {
            return $team->has_growbags_module && $team->devices->isNotEmpty();
        });

        foreach ($eligibleTeams as $team) {
            $activeDeviceIds = $team->devices->pluck('device_id');
            $defaultDeviceId = $activeDeviceIds->first();
            $onUpdates = [];
            $machineOffUpdates = [];

            $machines = DB::table('pulse_master_growbag_machines')
                ->where('team_id', $team->id)
                ->where('is_active', 1)
                ->orderBy('field_id')
                ->get(['id', 'device_id', 'field_id', 'machine_name']);

            foreach ($machines as $machine) {
                $deviceId = $activeDeviceIds->contains($machine->device_id)
                    ? $machine->device_id
                    : $defaultDeviceId;
                $machineLabel = $machine->machine_name ?: "Growbag {$machine->id}";

                $result = $this->detectActivityStateChange(
                    "machine_state_growbag_{$team->id}_{$machine->id}",
                    $deviceId,
                    'pulse_device_data_growbags',
                    'growbag_datetime',
                    now()->subMinutes(10),
                    $machineLabel,
                    $team->id,
                    fn ($query) => $query
                        ->where('field_id', $machine->field_id)
                        ->where('is_duplicate', 0)
                );
                $onUpdates = array_merge($onUpdates, $result['on']);
                $machineOffUpdates = array_merge($machineOffUpdates, $result['off']);
            }

            $this->dispatchMachineAlerts($notifier, $team, $onUpdates, $machineOffUpdates);
        }
    }

    /**
     * Activity-window ON/OFF detection used by blocks, baling, weighing, and growbags.
     *
     * @return array{on: string[], off: string[]}
     */
    private function detectActivityStateChange(
        string $cacheKey,
        string $deviceId,
        string $table,
        string $timeColumn,
        Carbon $windowStart,
        string $machineLabel,
        int $teamId,
        ?callable $extraWhere = null
    ): array {
        $query = DB::table($table)
            ->where('device_id', $deviceId)
            ->where($timeColumn, '>=', $windowStart);

        if ($extraWhere) {
            $extraWhere($query);
        }

        $latest = $query->orderBy($timeColumn, 'desc')->first();
        $currentState = $latest ? 'on' : 'off';

        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, $currentState);
            Log::info("Set {$machineLabel} state '{$currentState}' for team {$teamId}.");

            return ['on' => [], 'off' => []];
        }

        $lastState = Cache::get($cacheKey);
        if ($currentState === $lastState) {
            return ['on' => [], 'off' => []];
        }

        Cache::put($cacheKey, $currentState);
        $time = $currentState === 'on'
            ? $this->firstActivityTime($table, $deviceId, $timeColumn, $windowStart, $extraWhere)
            : now()->format('g:i a');
        $msg = "{$machineLabel} ".($currentState === 'on' ? 'started' : 'stopped')." at {$time}";

        return $currentState === 'on'
            ? ['on' => [$msg], 'off' => []]
            : ['on' => [], 'off' => [$msg]];
    }

    private function dispatchMachineAlerts(
        NotificationSenderService $notifier,
        Team $team,
        array $onUpdates,
        array $machineOffUpdates,
        array $shutdownUpdates = []
    ): void {
        $allUpdates = array_merge($onUpdates, $machineOffUpdates, $shutdownUpdates);

        if (empty($allUpdates)) {
            return;
        }

        $types = [];
        if (! empty($onUpdates)) {
            $types[] = NotificationType::MACHINE_ON;
        }
        if (! empty($machineOffUpdates)) {
            $types[] = NotificationType::MACHINE_OFF;
        }
        if (! empty($shutdownUpdates)) {
            $types[] = NotificationType::MACHINE_SHUTDOWN;
        }

        $notifier->notifyTeamUsers($team->id, [], "{$team->name} - Machine Alerts", $allUpdates, now(), null, true, $types);
        Log::info("Sent MACHINE ALERTS for team {$team->id}.");
    }

    private function firstDefibreActivityTime(
        string $deviceId,
        Carbon $windowStart,
        string $state,
        int $offTh
    ): string {
        return $this->firstActivityTime(
            'pulse_device_data',
            $deviceId,
            'datetime',
            $windowStart,
            match ($state) {
                DefibreStateClassifier::STATE_ON => fn ($query) => $query->where('d7', '>=', $offTh),
                DefibreStateClassifier::STATE_SHUTDOWN => fn ($query) => $query
                    ->where('d7', 0)
                    ->whereRaw('(d4 + d5 + d6) = 0'),
                default => fn ($query) => $query
                    ->where('d7', '<', $offTh)
                    ->whereRaw('(d4 + d5 + d6) > 0'),
            }
        );
    }

    /**
     * Earliest activity timestamp in the window — used for ON notifications
     * so we report when production started, not the latest pulse in the window.
     */
    private function firstActivityTime(
        string $table,
        string $deviceId,
        string $timeColumn,
        Carbon $windowStart,
        ?callable $extraWhere = null
    ): string {
        $query = DB::table($table)
            ->where('device_id', $deviceId)
            ->where($timeColumn, '>=', $windowStart);

        if ($extraWhere) {
            $extraWhere($query);
        }

        $first = $query->orderBy($timeColumn, 'asc')->first();

        return $first
            ? Carbon::parse($first->{$timeColumn})->format('g:i a')
            : now()->format('g:i a');
    }
}
