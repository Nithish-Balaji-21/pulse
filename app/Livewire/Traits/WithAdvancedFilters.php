<?php

namespace App\Livewire\Traits;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Shared chrome for advanced list filters: open/close, category tabs,
 * draft ↔ applied sync, AND/OR logic, and chip helpers.
 *
 * Consuming components must:
 * - Declare draft* / applied* (or unprefixed applied) filter properties
 * - Implement advancedFilterKeys(): list of property names that exist as draft{Key} + applied key
 * - Implement syncDraftFromApplied(), copyDraftToApplied(), clearFilterValues(),
 *   activeFilterChips(), categoryHasValue(), and applyAdvancedFilters() as needed
 */
trait WithAdvancedFilters
{
    public bool $filtersOpen = false;

    public string $filterCategory = 'date';

    #[Url(except: 'and')]
    public string $filterLogic = 'and';

    public function openFilters(): void
    {
        $this->syncDraftFromApplied();
        $this->filtersOpen = true;
    }

    public function closeFilters(): void
    {
        $this->filtersOpen = false;
    }

    public function toggleFilters(): void
    {
        if ($this->filtersOpen) {
            $this->closeFilters();
        } else {
            $this->openFilters();
        }
    }

    public function setFilterCategory(string $category): void
    {
        $this->filterCategory = $category;
    }

    public function applyFilters(): void
    {
        $this->copyDraftToApplied();
        $this->filtersOpen = false;
        $this->resetPage();
    }

    public function clearAllFilters(): void
    {
        $this->clearFilterValues();
        $this->filtersOpen = false;
        $this->resetPage();
    }

    public function setFilterLogic(string $mode): void
    {
        $this->filterLogic = in_array($mode, ['and', 'or'], true) ? $mode : 'and';
        $this->resetPage();
    }

    public function removeFilterChip(string $key): void
    {
        $this->clearFilterChip($key);
        $this->resetPage();
    }

    /**
     * Apply an array of constraint closures with AND or OR semantics.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     * @param  array<int, callable>  $constraints
     */
    protected function applyConstraintGroup($query, array $constraints, ?string $logic = null)
    {
        $constraints = array_values(array_filter($constraints));
        if ($constraints === []) {
            return $query;
        }

        $logic = $logic ?? $this->filterLogic;

        if ($logic === 'or') {
            return $query->where(function ($q) use ($constraints) {
                foreach ($constraints as $i => $constraint) {
                    if ($i === 0) {
                        $q->where($constraint);
                    } else {
                        $q->orWhere($constraint);
                    }
                }
            });
        }

        foreach ($constraints as $constraint) {
            $query->where($constraint);
        }

        return $query;
    }

    /**
     * Resolve a date preset into [from, to] Y-m-d strings, or null if unset.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function resolveDatePresetRange(?string $preset, ?string $from, ?string $to): ?array
    {
        if (! $preset) {
            return null;
        }

        $today = now()->startOfDay();

        return match ($preset) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'week' => [$today->copy()->startOfWeek()->toDateString(), $today->copy()->endOfWeek()->toDateString()],
            'month' => [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()],
            '30d' => [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
            'custom' => ($from && $to) ? [$from, $to] : (($from || $to) ? [$from ?: $to, $to ?: $from] : null),
            default => null,
        };
    }
}
