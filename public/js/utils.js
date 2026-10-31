// Converts seconds to a human-readable format like "2M 3d" "5d 2h", "15m", etc., including days and months
function convertSecondsToTime(seconds) {
    if (seconds < 1) {
        return '0';
    }

    const SECONDS_IN_MINUTE = 60;
    const SECONDS_IN_HOUR = 3600;
    const SECONDS_IN_DAY = 86400;

    let days = Math.floor(seconds / SECONDS_IN_DAY);
    seconds %= SECONDS_IN_DAY;

    let hours = Math.floor(seconds / SECONDS_IN_HOUR);
    let minutes = Math.floor((seconds % SECONDS_IN_HOUR) / SECONDS_IN_MINUTE);
    let secs = seconds % SECONDS_IN_MINUTE;

    let timeUnits = [];

    if (days > 0) {
        timeUnits.push(days + 'd');
    }
    if (hours > 0) {
        timeUnits.push(hours + 'h');
    }
    if (minutes > 0) {
        timeUnits.push(minutes + 'm');
    }
    if (secs > 0 && timeUnits.length === 0) {
        timeUnits.push(secs + 's'); // Only add seconds if nothing else exists
    }

    return timeUnits.slice(0, 3).join(' ');
}

function convertSecondsToDecimalTime(seconds) {
    if (seconds < 1) {
        return '0';
    }

    const SECONDS_IN_MINUTE = 60;
    const SECONDS_IN_HOUR = 3600;

    if (seconds >= SECONDS_IN_HOUR) {
        let hours = seconds / SECONDS_IN_HOUR;
        return (hours === Math.floor(hours) ? Math.floor(hours) : +hours.toFixed(1)) + 'h';
    }
    if (seconds >= SECONDS_IN_MINUTE) {
        return Math.round(seconds / SECONDS_IN_MINUTE) + 'm';
    }

    return Math.round(seconds) + 's';
}

// Core formatting logic
function formatToIndianNumber(value, isCurrency = false, returnObject = false, disableCompact = true, withHover = false) {
    if (value === null || value === undefined || isNaN(value)) {
        let zero = isCurrency ? '₹0' : '0';
        return returnObject ? { compact: zero, full: zero } : zero;
    }
    
    let num = Number(value);
    let absNum = Math.abs(num);
    let prefix = isCurrency ? '₹' : '';
    let sign = num < 0 ? '-' : '';
    
    let fullString = sign + prefix + new Intl.NumberFormat('en-IN', { maximumFractionDigits: 1 }).format(absNum);
    let compactString = "";
    
    if (!disableCompact && absNum >= 10000000) {
        let crValue = Number((absNum / 10000000).toFixed(2));
        let val = crValue.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
        compactString = sign + prefix + val + 'Cr';
    } else if (!disableCompact && absNum >= 100000) {
        let lValue = Number((absNum / 100000).toFixed(2));
        if (lValue >= 100) {
            let crValue = Number((lValue / 100).toFixed(2));
            let val = crValue.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
            compactString = sign + prefix + val + 'Cr';
        } else {
            let val = lValue.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
            compactString = sign + prefix + val + 'L';
        }
    } else if (!disableCompact && absNum >= 10000) {
        let kValue = Number((absNum / 1000).toFixed(1));
        if (kValue >= 100) {
            let lValue = Number((kValue / 100).toFixed(2));
            let val = lValue.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
            compactString = sign + prefix + val + 'L';
        } else {
            let val = kValue.toFixed(1).replace(/\.0$/, '');
            compactString = sign + prefix + val + 'K';
        }
    } else {
        let decimals = absNum % 1 !== 0 ? 1 : 0;
        let formattedAbs = new Intl.NumberFormat('en-IN', { maximumFractionDigits: decimals }).format(absNum);
        compactString = sign + prefix + formattedAbs;
    }
    
    if (withHover && compactString !== fullString) {
        let result = `<span title="${fullString}">${compactString}</span>`;
        return returnObject ? { compact: result, full: fullString } : result;
    }

    return returnObject ? { compact: compactString, full: fullString } : (disableCompact ? fullString : compactString);
}

function formatToIndianRupees(amount) {
    return formatToIndianNumber(amount, true);
}

function formatToIndianNumberNormal(amount) {
    return formatToIndianNumber(amount, false, false, true);
}

function formatToIndianRupeesNormal(amount) {
    return formatToIndianNumber(amount, true, false, true);
}

function formatToIndianNumberWithHover(amount) {
    return formatToIndianNumber(amount, false, false, false, true);
}

/**
 * The compact form on its own, for the places that draw text rather than parse markup —
 * chart data labels and axis ticks are SVG <text>, where the hover variant's <span title>
 * is shown to the reader verbatim instead of becoming a tooltip.
 */
function formatToIndianNumberCompact(amount) {
    return formatToIndianNumber(amount, false, false, false, false);
}

function formatToIndianRupeesWithHover(amount) {
    return formatToIndianNumber(amount, true, false, false, true);
}

function formatToShortNumbers(amount, digits = 1, showCurrency = false) {
    return formatToIndianNumber(amount, showCurrency);
}

// Ellipsis for texts
function fixedWidthString(input, length) {
    if (input.length > length) {
        return input.substring(0, length - 3) + '...';
    }

    return input;
}

// Ceil to nearest 10 for 2 digits, nearest 100 for 3 digits, etc.
function roundUpToNearest(value) {
    const numberOfDigits = Math.floor(Math.log10(value)) + 1;
    const roundFactor = Math.pow(10, numberOfDigits - 1);
    return Math.ceil(value / roundFactor) * roundFactor;
}

function switchToTeam(el) {
    var newTeamId = el.getAttribute('data-team-id');
    var currentPath = window.location.pathname;
    var newPath;

    if (/^\/\d+/.test(currentPath)) {
        // Pattern: /{team_id}/... → replace leading numeric segment
        newPath = currentPath.replace(/^\/\d+/, '/' + newTeamId);
    } else if (/^\/teams\/\d+/.test(currentPath)) {
        // Pattern: /teams/{team_id} → replace the numeric part after /teams/
        newPath = currentPath.replace(/^\/teams\/\d+/, '/teams/' + newTeamId);
    } else {
        // Fallback: just go to the new team's dashboard
        newPath = '/' + newTeamId + '/dashboard';
    }

    window.location.href = newPath;
}

// Filters the team-switcher dropdown list (superadmin only) as the user types.
function filterTeamSwitchList(query) {
    const term = query.trim().toLowerCase();
    const items = document.querySelectorAll('#team-switch-list .team-switch-item');
    let anyVisible = false;

    items.forEach(function (item) {
        const isMatch = item.getAttribute('data-team-name').includes(term);
        item.style.display = isMatch ? '' : 'none';
        if (isMatch) {
            anyVisible = true;
        }
    });

    const emptyMessage = document.getElementById('team-switch-empty');
    if (emptyMessage) {
        emptyMessage.classList.toggle('hidden', anyVisible || items.length === 0);
    }
}

// Calculate a slotMaxTime string based on a slotMinTime and a span in hours.
// Returns a string like '32:00:00' when slotMinTime is '08:00:00' and spanHours is 24.
function calculateSlotMaxTime(slotMinTime, spanHours = 24) {
    if (!slotMinTime) return `${24 + 0}:00:00`;
    const parts = slotMinTime.split(':');
    const hour = parseInt(parts[0], 10) || 0;
    const minute = parseInt(parts[1], 10) || 0;
    const second = parseInt(parts[2], 10) || 0;

    const totalHour = hour + Number(spanHours);
    const hh = String(totalHour).padStart(2, '0');
    const mm = String(minute).padStart(2, '0');
    const ss = String(second).padStart(2, '0');

    return `${hh}:${mm}:${ss}`;
}
