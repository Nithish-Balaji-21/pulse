<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ExpenseConnection
{
    public function supports(Team $team): bool
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($team->name))) === 'alamcocos';
    }

    public function connected(Team $team): bool
    {
        if (! $this->supports($team) || ! $this->configured()) {
            return false;
        }

        return Cache::remember('alam-expense-connection:'.hash('sha256', config('expenses.url').config('expenses.api_key')), 30, function () {
            try {
                $response = $this->client()->get(rtrim(config('expenses.url'), '/').'/api/pulse/alam-cocos/connection');

                return $response->successful() && $response->json('connected') === true && $response->json('company') === 'alam_cocos';
            } catch (ConnectionException $exception) {
                return false;
            }
        });
    }

    public function report(Team $team, string $from, string $to): ?array
    {
        if (! $this->supports($team) || ! $this->configured()) {
            return null;
        }
        try {
            $response = $this->client()->get(rtrim(config('expenses.url'), '/').'/api/pulse/alam-cocos/expenses', ['from' => $from, 'to' => $to]);
            $data = $response->json();

            return $response->successful() && is_array($data) && ($data['company'] ?? null) === 'alam_cocos' && ($data['connected'] ?? false) === true && is_array($data['rows'] ?? null) && is_array($data['totals'] ?? null) ? $data : null;
        } catch (ConnectionException $exception) {
            return null;
        }
    }

    public function details(Team $team, string $from, string $to): ?array
    {
        if (! $this->supports($team) || ! $this->configured()) {
            return null;
        }
        try {
            $response = $this->client()->get(rtrim(config('expenses.url'), '/').'/api/pulse/alam-cocos/details', ['from' => $from, 'to' => $to]);
            $data = $response->json();

            return $response->successful() && is_array($data) && ($data['company'] ?? null) === 'alam_cocos' && ($data['connected'] ?? false) === true && is_array($data['staff']['columns'] ?? null) && is_array($data['staff']['rows'] ?? null) && is_array($data['staff']['column_totals'] ?? null) && is_array($data['charges'] ?? null) && is_array($data['totals'] ?? null) ? $data : null;
        } catch (ConnectionException $exception) {
            return null;
        }
    }

    /**
     * Flatten a details() payload into report rows: one row per non-empty
     * staff task cell, then one per vehicle / material charge.
     */
    public function rows(array $details): array
    {
        $rows = [];
        foreach ($details['staff']['rows'] as $staff) {
            foreach ($details['staff']['columns'] as $column) {
                $cell = $staff['cells'][$column['key']];
                if ((float) $cell['amount'] == 0 && (float) $cell['hours'] == 0 && (float) $cell['quantity'] == 0) {
                    continue;
                }
                $rows[] = ['category' => 'staff', 'wage_type' => $column['group'], 'name' => $staff['name'], 'description' => $column['label'],
                    'detail' => $column['group'] === 'hourly' ? $cell['hours'].' hrs' : $cell['quantity'].' '.$column['unit'],
                    'date' => null, 'paid_by' => 'Alam Cocos', 'billed_to' => 'Alam Cocos', 'amount' => (float) $cell['amount']];
            }
        }
        foreach ($details['charges'] as $charge) {
            $rows[] = ['category' => $charge['type'], 'name' => $charge['item'], 'description' => $charge['notes'] ?? '',
                'detail' => '', 'date' => $charge['date'], 'paid_by' => $charge['paid_by'], 'billed_to' => $charge['billed_to'], 'amount' => (float) $charge['amount']];
        }

        return $rows;
    }

    public static function activityWages(array $rows): float
    {
        return array_sum(array_column(array_filter($rows, fn ($row) => ($row['wage_type'] ?? null) === 'activity'), 'amount'));
    }

    public static function filterRows(array $rows, string $category): array
    {
        return array_values(array_filter($rows, fn ($row) => $category === 'all' || ($category === 'activity' ? ($row['wage_type'] ?? null) === 'activity' : $row['category'] === $category)));
    }

    private function configured(): bool
    {
        return config('expenses.url') && strlen((string) config('expenses.api_key')) >= 32
            && (! app()->environment('production') || str_starts_with(config('expenses.url'), 'https://'));
    }

    private function client()
    {
        return Http::withToken(config('expenses.api_key'))->acceptJson()->connectTimeout(2)->timeout(8);
    }
}
