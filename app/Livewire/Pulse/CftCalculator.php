<?php

namespace App\Livewire\Pulse;

use Livewire\Component;

class CftCalculator extends Component
{
    public string $instanceKey = '';

    public array $dimensions = [
        'length' => '',
        'width' => '',
        'height' => '',
    ];

    public function mount(): void
    {
        $this->instanceKey = bin2hex(random_bytes(5));
    }

    public function getCftProperty(): ?float
    {
        $length = $this->toFeet($this->dimensions['length'] ?? '');
        $width = $this->toFeet($this->dimensions['width'] ?? '');
        $height = $this->toFeet($this->dimensions['height'] ?? '');

        if ($length === null || $width === null || $height === null) {
            return null;
        }

        return $length * $width * $height;
    }

    public function resetCalculator(): void
    {
        foreach (['length', 'width', 'height'] as $dimension) {
            $this->dimensions[$dimension] = '';
        }
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

        // The fractional token is the inch portion (for example, 10.6 = 10 ft 6 in).
        // Carry values of 12+ inches into feet so entries such as 8.77 remain usable.
        $inches = isset($parts[2]) ? (int) $parts[2] : 0;

        return (float) $parts[1] + ($inches / 12);
    }

    public function render()
    {
        return view('Pulse.livewire.cft-calculator');
    }
}
