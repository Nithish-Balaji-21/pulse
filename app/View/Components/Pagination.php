<?php

namespace App\View\Components;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\Component;

class Pagination extends Component
{
    public LengthAwarePaginator $items;

    public string $perPageBinding;

    public function __construct(LengthAwarePaginator $items, string $perPageBinding = 'perPage')
    {
        $this->items = $items;
        $this->perPageBinding = $perPageBinding;
    }

    public function render()
    {
        return view('components.pagination');
    }
}
