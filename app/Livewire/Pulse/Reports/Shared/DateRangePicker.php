<?php

namespace App\Livewire\Pulse\Reports\Shared;

use Carbon\Carbon;
use Livewire\Component;

class DateRangePicker extends Component
{
    public $startDate;

    public $endDate;

    public $presetLabel = 'Yesterday';

    public $displayLabel;

    public function mount($startDate = null, $endDate = null, $presetLabel = 'Yesterday')
    {
        $yesterday = now()->subDay()->toDateString();
        $this->startDate = $startDate ?: $yesterday;
        $this->endDate = $endDate ?: $yesterday;
        $this->presetLabel = $presetLabel;
        $this->updateDisplayLabel();
    }

    public function updateDates($start, $end, $preset = 'Custom Range')
    {
        if ($start && $end && $start > $end) {
            [$start, $end] = [$end, $start];
        }

        $this->startDate = $start;
        $this->endDate = $end;
        $this->presetLabel = $preset;
        $this->updateDisplayLabel();
        $this->dispatch('date-range-updated', startDate: $this->startDate, endDate: $this->endDate, preset: $this->presetLabel)->to(self::class);
        $this->dispatch('date-range-updated', startDate: $this->startDate, endDate: $this->endDate, preset: $this->presetLabel);
    }

    private function updateDisplayLabel()
    {
        $s = Carbon::parse($this->startDate);
        $e = Carbon::parse($this->endDate);
        $sFormatted = $s->format('M j, Y');
        $eFormatted = $e->format('M j, Y');
        $this->displayLabel = $s->isSameDay($e) ? $sFormatted : "{$sFormatted} – {$eFormatted}";
    }

    public function render()
    {
        return view('Pulse.livewire.reports.shared.date-range-picker');
    }
}
