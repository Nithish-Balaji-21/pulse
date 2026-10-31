/**
 * Application Helper Functions
 * Common utilities for the Coir Power application
 */

// Confirmation Dialog Helper
window.confirmAction = function(message, callback) {
    if (confirm(message || 'Are you sure you want to proceed?')) {
        if (typeof callback === 'function') {
            callback();
        }
        return true;
    }
    return false;
};

// Format Number to Indian Currency
window.formatCurrency = function(amount, decimals = 2) {
    return formatToIndianRupees(amount);
};

// Format Number with Indian Number System
window.formatNumber = function(number, decimals = 0) {
    return formatToIndianNumber(number);
};

// Debounce Function
window.debounce = function(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
};

// Copy to Clipboard
window.copyToClipboard = function(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            window.toast.success('Copied to clipboard!');
        }).catch(() => {
            window.toast.error('Failed to copy to clipboard');
        });
    } else {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            window.toast.success('Copied to clipboard!');
        } catch (err) {
            window.toast.error('Failed to copy to clipboard');
        }
        document.body.removeChild(textarea);
    }
};

// Scroll to Top
window.scrollToTop = function(smooth = true) {
    window.scrollTo({
        top: 0,
        behavior: smooth ? 'smooth' : 'auto'
    });
};

// Scroll to Element
window.scrollToElement = function(elementId, offset = 0) {
    const element = document.getElementById(elementId);
    if (element) {
        const top = element.getBoundingClientRect().top + window.pageYOffset - offset;
        window.scrollTo({
            top: top,
            behavior: 'smooth'
        });
    }
};

// Print Element
window.printElement = function(elementId) {
    const element = document.getElementById(elementId);
    if (!element) {
        window.toast.error('Element not found');
        return;
    }
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Print</title>
                <link href="${window.location.origin}/css/output.css" rel="stylesheet">
                <style>
                    @media print {
                        body { margin: 0; padding: 20px; }
                        .no-print { display: none !important; }
                    }
                </style>
            </head>
            <body>
                ${element.innerHTML}
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
};

// Download as JSON
window.downloadJSON = function(data, filename = 'data.json') {
    const json = JSON.stringify(data, null, 2);
    const blob = new Blob([json], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    window.toast.success(`Downloaded ${filename}`);
};

// Local Storage Helpers
window.storage = {
    set: function(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            console.error('Storage error:', e);
            return false;
        }
    },
    get: function(key, defaultValue = null) {
        try {
            const item = localStorage.getItem(key);
            return item ? JSON.parse(item) : defaultValue;
        } catch (e) {
            console.error('Storage error:', e);
            return defaultValue;
        }
    },
    remove: function(key) {
        try {
            localStorage.removeItem(key);
            return true;
        } catch (e) {
            console.error('Storage error:', e);
            return false;
        }
    },
    clear: function() {
        try {
            localStorage.clear();
            return true;
        } catch (e) {
            console.error('Storage error:', e);
            return false;
        }
    }
};

// Date Formatting Helpers
window.dateHelpers = {
    formatDate: function(date, format = 'DD/MM/YYYY') {
        if (!date) return '';
        const d = new Date(date);
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();
        const hours = String(d.getHours()).padStart(2, '0');
        const minutes = String(d.getMinutes()).padStart(2, '0');
        
        return format
            .replace('DD', day)
            .replace('MM', month)
            .replace('YYYY', year)
            .replace('HH', hours)
            .replace('mm', minutes);
    },
    
    isToday: function(date) {
        const d = new Date(date);
        const today = new Date();
        return d.toDateString() === today.toDateString();
    },
    
    isYesterday: function(date) {
        const d = new Date(date);
        const yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);
        return d.toDateString() === yesterday.toDateString();
    },
    
    daysAgo: function(date) {
        const d = new Date(date);
        const today = new Date();
        const diffTime = Math.abs(today - d);
        return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    },
    
    relativeTime: function(date) {
        const d = new Date(date);
        const now = new Date();
        const diffMs = now - d;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);
        
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
        if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
        if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
        return this.formatDate(date);
    }
};

// Form Validation Helpers
window.validation = {
    isEmail: function(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    },
    
    isPhone: function(phone) {
        return /^[6-9]\d{9}$/.test(phone.replace(/\s/g, ''));
    },
    
    isNumber: function(value) {
        return !isNaN(parseFloat(value)) && isFinite(value);
    },
    
    isEmpty: function(value) {
        return value === null || value === undefined || value === '' || 
               (Array.isArray(value) && value.length === 0) ||
               (typeof value === 'object' && Object.keys(value).length === 0);
    },
    
    minLength: function(value, min) {
        return String(value).length >= min;
    },
    
    maxLength: function(value, max) {
        return String(value).length <= max;
    },
    
    inRange: function(value, min, max) {
        const num = parseFloat(value);
        return num >= min && num <= max;
    }
};

// URL Helpers
window.urlHelpers = {
    getParam: function(name) {
        const params = new URLSearchParams(window.location.search);
        return params.get(name);
    },
    
    setParam: function(name, value) {
        const url = new URL(window.location);
        url.searchParams.set(name, value);
        window.history.pushState({}, '', url);
    },
    
    removeParam: function(name) {
        const url = new URL(window.location);
        url.searchParams.delete(name);
        window.history.pushState({}, '', url);
    },
    
    getAllParams: function() {
        const params = new URLSearchParams(window.location.search);
        const result = {};
        for (const [key, value] of params) {
            result[key] = value;
        }
        return result;
    }
};

// Array Helpers
window.arrayHelpers = {
    unique: function(array) {
        return [...new Set(array)];
    },
    
    groupBy: function(array, key) {
        return array.reduce((result, item) => {
            const group = item[key];
            if (!result[group]) {
                result[group] = [];
            }
            result[group].push(item);
            return result;
        }, {});
    },
    
    sortBy: function(array, key, direction = 'asc') {
        return [...array].sort((a, b) => {
            const aVal = a[key];
            const bVal = b[key];
            if (direction === 'asc') {
                return aVal > bVal ? 1 : -1;
            } else {
                return aVal < bVal ? 1 : -1;
            }
        });
    },
    
    chunk: function(array, size) {
        const chunks = [];
        for (let i = 0; i < array.length; i += size) {
            chunks.push(array.slice(i, i + size));
        }
        return chunks;
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('App helpers loaded');
    
    // Add smooth scroll behavior to all anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href !== '#' && href !== '#!') {
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });
    });
    
    // Add "Back to Top" button functionality if exists
    const backToTopBtn = document.getElementById('back-to-top');
    if (backToTopBtn) {
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                backToTopBtn.style.display = 'block';
            } else {
                backToTopBtn.style.display = 'none';
            }
        });
        
        backToTopBtn.addEventListener('click', function() {
            window.scrollToTop();
        });
    }
});

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        confirmAction,
        formatCurrency,
        formatNumber,
        debounce,
        copyToClipboard,
        scrollToTop,
        scrollToElement,
        printElement,
        downloadJSON,
        storage,
        dateHelpers,
        validation,
        urlHelpers,
        arrayHelpers
    };
}
