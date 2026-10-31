// Toast Notification System
class ToastManager {
    constructor() {
        this.container = null;
        this.toasts = new Set();
        this.maxToasts = 5;
        this.init();
    }

    init() {
        // Ensure DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.createContainer());
        } else {
            this.createContainer();
        }
    }

    createContainer() {
        // Create container if it doesn't exist
        if (!document.querySelector('.toast-container')) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            this.container.setAttribute('role', 'alert');
            this.container.setAttribute('aria-live', 'polite');
            document.body.appendChild(this.container);
        } else {
            this.container = document.querySelector('.toast-container');
        }
    }

    show(message, type = 'info', duration = 5000) {
        // Ensure container exists
        if (!this.container) {
            this.createContainer();
        }

        // Sanitize message to prevent XSS
        const sanitizedMessage = this.sanitize(message);
        
        // Limit number of toasts
        if (this.toasts.size >= this.maxToasts) {
            const oldestToast = Array.from(this.toasts)[0];
            this.removeToast(oldestToast);
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        
        const icon = this.getIcon(type);
        
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="${icon}"></i>
            </div>
            <div class="toast-message">${sanitizedMessage}</div>
            <button class="toast-close" type="button" aria-label="Close notification">
                <i class="fa-solid fa-xmark"></i>
            </button>
        `;
        
        // Add close button event listener
        const closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', () => this.removeToast(toast));
        
        this.container.appendChild(toast);
        this.toasts.add(toast);
        
        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('toast-show');
        });
        
        // Auto remove after duration
        if (duration > 0) {
            const timeoutId = setTimeout(() => {
                this.removeToast(toast);
            }, duration);
            
            // Store timeout ID to allow cancellation
            toast.dataset.timeoutId = timeoutId;
        }
        
        return toast;
    }

    removeToast(toast) {
        if (!toast || !toast.parentElement) return;
        
        // Clear timeout if exists
        if (toast.dataset.timeoutId) {
            clearTimeout(parseInt(toast.dataset.timeoutId));
        }
        
        toast.classList.remove('toast-show');
        toast.classList.add('hiding');
        
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
            this.toasts.delete(toast);
        }, 300);
    }

    sanitize(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    getIcon(type) {
        const icons = {
            success: 'fa-solid fa-circle-check',
            error: 'fa-solid fa-circle-xmark',
            warning: 'fa-solid fa-triangle-exclamation',
            info: 'fa-solid fa-circle-info'
        };
        return icons[type] || icons.info;
    }

    success(message, duration = 5000) {
        return this.show(message, 'success', duration);
    }

    error(message, duration = 6000) {
        return this.show(message, 'error', duration);
    }

    warning(message, duration = 5500) {
        return this.show(message, 'warning', duration);
    }

    info(message, duration = 4000) {
        return this.show(message, 'info', duration);
    }

    clear() {
        this.toasts.forEach(toast => this.removeToast(toast));
    }
}

// Initialize global toast manager
if (typeof window !== 'undefined') {
    window.toast = new ToastManager();
}

// Livewire integration
if (typeof Livewire !== 'undefined') {
    document.addEventListener('livewire:init', () => {
        Livewire.on('toast', (event) => {
            // Handle both array and object event formats
            const data = Array.isArray(event) ? event[0] : event;
            const { message, type = 'info', duration = 5000 } = data;
            window.toast.show(message, type, duration);
        });
    });
} else {
    // Fallback for when Livewire loads later
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            if (typeof Livewire !== 'undefined') {
                Livewire.on('toast', (event) => {
                    const data = Array.isArray(event) ? event[0] : event;
                    const { message, type = 'info', duration = 5000 } = data;
                    window.toast.show(message, type, duration);
                });
            }
        }, 100);
    });
}

// Listen for flash messages from Laravel
document.addEventListener('DOMContentLoaded', () => {
    // Small delay to ensure toast manager is fully initialized
    setTimeout(() => {
        // Check for Laravel flash messages
        const flashMessages = [
            { selector: '[data-flash-success]', type: 'success' },
            { selector: '[data-flash-error]', type: 'error' },
            { selector: '[data-flash-warning]', type: 'warning' },
            { selector: '[data-flash-info]', type: 'info' }
        ];
        
        flashMessages.forEach(({ selector, type }) => {
            const element = document.querySelector(selector);
            if (element) {
                const message = element.dataset[`flash${type.charAt(0).toUpperCase() + type.slice(1)}`];
                if (message && window.toast) {
                    window.toast[type](message);
                }
            }
        });
    }, 100);
});
