@props(['active'])

@php
$classes = ($active ?? false)
            ? 'flex px-1 text-sm text-black z-50'
            : 'flex px-1 text-sm text-black z-50';
@endphp
{{-- hover:border-black hover:border-x hover:border-t hover:bg-gray-100 group-hover:border-x group-hover:border-t group-hover:bg-gray-100  --}}
<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
