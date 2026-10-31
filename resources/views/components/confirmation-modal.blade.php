@props([
    'show' => false,
    'title' => 'Confirm Action',
    'message' => 'Are you sure you want to proceed?',
    'confirmText' => 'Confirm',
    'cancelText' => 'Cancel',
    'type' => 'danger', // danger, warning, info
    'onConfirm' => '',
    'onCancel' => ''
])

<div 
    x-data="{ show: @js($show) }"
    x-show="show"
    x-cloak
    class="modal-overlay"
    style="display: none;"
    @click.self="show = false; {{ $onCancel }}"
>
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-icon modal-icon-{{ $type }}">
                @if($type === 'danger')
                    <i class="fa-solid fa-triangle-exclamation"></i>
                @elseif($type === 'warning')
                    <i class="fa-solid fa-exclamation-circle"></i>
                @else
                    <i class="fa-solid fa-info-circle"></i>
                @endif
            </div>
            <h3 class="modal-title">{{ $title }}</h3>
        </div>
        <div class="modal-body">
            {{ $message }}
        </div>
        <div class="modal-actions">
            <button 
                type="button" 
                class="cancel-btn"
                @click="show = false; {{ $onCancel }}"
            >
                {{ $cancelText }}
            </button>
            <button 
                type="button" 
                class="delete-btn"
                @click="show = false; {{ $onConfirm }}"
            >
                {{ $confirmText }}
            </button>
        </div>
    </div>
</div>
