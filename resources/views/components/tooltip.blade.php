@props(['text', 'position' => 'top'])

<div class="tooltip-wrapper">
    {{ $slot }}
    <span class="tooltip tooltip-{{ $position }}">{{ $text }}</span>
</div>
