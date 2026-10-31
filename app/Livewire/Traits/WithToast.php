<?php

namespace App\Livewire\Traits;

trait WithToast
{
    /**
     * Dispatch a toast notification
     *
     * @param  string  $message
     * @param  string  $type  (success, error, warning, info)
     * @param  int  $duration  in milliseconds
     */
    public function toast($message, $type = 'info', $duration = 5000)
    {
        $this->dispatch('toast', [
            'message' => $message,
            'type' => $type,
            'duration' => $duration,
        ]);
    }

    /**
     * Show success toast
     */
    public function toastSuccess($message, $duration = 5000)
    {
        $this->toast($message, 'success', $duration);
    }

    /**
     * Show error toast
     */
    public function toastError($message, $duration = 5000)
    {
        $this->toast($message, 'error', $duration);
    }

    /**
     * Show warning toast
     */
    public function toastWarning($message, $duration = 5000)
    {
        $this->toast($message, 'warning', $duration);
    }

    /**
     * Show info toast
     */
    public function toastInfo($message, $duration = 5000)
    {
        $this->toast($message, 'info', $duration);
    }
}
