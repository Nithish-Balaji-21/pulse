<?php

namespace App\Livewire\Pulse;

use Livewire\Component;

class SumpCalculator extends Component
{
    public string $instanceKey = '';

    public string $shape = 'rectangular';

    public array $dimensions = [
        'length' => '',
        'width' => '',
        'depth' => '',
    ];

    public function mount(): void
    {
        $this->instanceKey = bin2hex(random_bytes(5));
    }

    public function updatedShape(): void
    {
        if (! in_array($this->shape, ['rectangular', 'circular'], true)) {
            $this->shape = 'rectangular';
        }

        $this->resetErrorBag();
    }

    public function getCapacityProperty(): ?float
    {
        $length = $this->toFeet($this->dimensions['length'] ?? '');
        $depth = $this->toFeet($this->dimensions['depth'] ?? '');
        $width = $this->shape === 'rectangular'
            ? $this->toFeet($this->dimensions['width'] ?? '')
            : null;

        $dimensions = [$length, $depth];
        if ($this->shape === 'rectangular') {
            $dimensions[] = $width;
        }

        foreach ($dimensions as $value) {
            if ($value === null || $value <= 0) {
                return null;
            }
        }

        return $this->shape === 'circular'
            ? M_PI * ($length / 2) ** 2 * $depth
            : $length * $width * $depth;
    }

    public function resetCalculator(): void
    {
        $this->shape = 'rectangular';
        foreach (['length', 'width', 'depth'] as $dimension) {
            $this->dimensions[$dimension] = '';
        }

        $this->resetErrorBag();
    }

    private function toFeet(mixed $value): ?float
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $measurement = (float) $value;

        if (! is_finite($measurement) || $measurement < 0) {
            return null;
        }

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $parts)) {
            return null;
        }

        // The fractional token is the inch portion; carry 12+ inches into feet.
        $inches = isset($parts[2]) ? (int) $parts[2] : 0;

        return (float) $parts[1] + ($inches / 12);
    }

    public function render()
    {
        return view('Pulse.livewire.sump-calculator');
    }
}
