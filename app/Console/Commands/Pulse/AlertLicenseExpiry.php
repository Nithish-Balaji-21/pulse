<?php

namespace App\Console\Commands\Pulse;

use App\Models\Team;
use App\Services\NotificationSenderService;
use App\Support\Notifications\NotificationType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Warns a factory owner before their licence runs out.
 *
 * Two reminders: a week ahead, and again the day before. Because the command
 * matches on the licence date each morning, a factory that renews in between
 * simply stops matching and the second reminder never goes out.
 *
 * `teams:deactivate-expired` then switches the factory off the morning after
 * the date passes.
 */
class AlertLicenseExpiry extends Command
{
    protected $signature = 'alerts:license-expiry';

    protected $description = 'Warn factory owners a week and a day before their licence expires';

    /**
     * Days before expiry that trigger a reminder.
     *
     * @var array<int, int>
     */
    private const REMINDER_DAYS = [7, 1];

    public function handle(NotificationSenderService $notifier): int
    {
        $today = Carbon::today();
        $sent = 0;

        foreach (self::REMINDER_DAYS as $daysAhead) {
            $expiryDate = $today->copy()->addDays($daysAhead);

            $teams = Team::query()
                ->activePulse()
                ->whereHas('pulse', fn ($query) => $query->whereDate('license_valid_till', $expiryDate))
                ->get();

            foreach ($teams as $team) {
                $notifier->notifyTeamUsers(
                    (int) $team->id,
                    [],
                    "{$team->name} - ".$this->subjectFor($daysAhead),
                    $this->bodyFor($team, $expiryDate, $daysAhead),
                    $today->toDateString(),
                    $expiryDate->toDateString(),
                    true,
                    NotificationType::LICENSE_EXPIRY
                );

                Log::info("Licence expiry warning sent for team {$team->id} ({$daysAhead} day(s) ahead).");
                $sent++;
            }
        }

        $this->info("Licence expiry warnings sent: {$sent}");

        return self::SUCCESS;
    }

    private function subjectFor(int $daysAhead): string
    {
        return $daysAhead === 1
            ? 'Licence expires tomorrow'
            : "Licence expires in {$daysAhead} days";
    }

    /**
     * @return array<int, string>
     */
    private function bodyFor(Team $team, Carbon $expiryDate, int $daysAhead): array
    {
        $formattedDate = $expiryDate->format(config('app.date_format', 'd/m/Y'));

        return [
            $daysAhead === 1
                ? "Your CoiioT licence for {$team->name} expires tomorrow, {$formattedDate}."
                : "Your CoiioT licence for {$team->name} expires on {$formattedDate}, in {$daysAhead} days.",
            'Access to the factory is suspended the day after it expires.',
            'Please contact CoiioT support to renew.',
        ];
    }
}
