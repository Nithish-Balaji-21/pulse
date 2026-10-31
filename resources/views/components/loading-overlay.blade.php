@props(['show' => false, 'message' => 'Loading...'])

<div 
    x-data="{ show: @js($show) }"
    x-show="show"
    x-cloak
    class="loading-overlay"
    style="display: none;"
>
    <div class="text-center">
        <div class="loading-spinner mx-auto"></div>
        <p class="text-white mt-4 font-medium">{{ $message }}</p>
    </div>
</div>
