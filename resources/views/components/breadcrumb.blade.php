@props(['items' => []])

<nav class="breadcrumb" aria-label="Breadcrumb">
    @foreach($items as $index => $item)
        <div class="breadcrumb-item {{ $loop->last ? 'active' : '' }}">
            @if($loop->last)
                <span>{{ $item['label'] }}</span>
            @else
                <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                <span class="breadcrumb-separator">
                    <i class="fa-solid fa-chevron-right text-xs"></i>
                </span>
            @endif
        </div>
    @endforeach
</nav>
