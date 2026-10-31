@if (app(\App\Services\ExpenseConnection::class)->supports(app('current.team')))
<script>
(() => {
    const root = document.getElementById('expense-summary-card');
    if (!root) return;
    const value = root.querySelector('#totalExpenses');
    const breakdown = root.querySelector('[data-expense-breakdown]');
    const categories = {hourly_cost: 'Hourly', activity_cost: 'Activity', materials_vehicles_cost: 'Materials & vehicles'};
    let pending;
    window.loadAlamExpenseWidget = async (start, end) => {
        if (pending) pending.abort();
        pending = new AbortController();
        const signal = pending.signal;
        const from = moment(start).format('YYYY-MM-DD');
        const to = moment(end || start).format('YYYY-MM-DD');
        const reportLink = root;
        const reportUrl = new URL(reportLink.href, window.location.origin);
        reportUrl.searchParams.set('from', from);
        reportUrl.searchParams.set('to', to);
        reportLink.href = reportUrl.toString();
        value.textContent = '—';
        breakdown.querySelectorAll('[data-expense-segment]').forEach(segment => segment.style.width = '0%');
        breakdown.title = 'Loading expense breakdown';
        breakdown.setAttribute('aria-label', breakdown.title);
        root.title = 'Loading expenses…';
        try {
            const url = new URL(root.dataset.url, window.location.origin);
            url.searchParams.set('from', from); url.searchParams.set('to', to);
            const response = await fetch(url, {signal, headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error('Unavailable');
            const data = await response.json();
            if (!data.connected || !Number.isFinite(Number(data.totals?.total_cost))) throw new Error('Unavailable');
            const format = new Intl.NumberFormat('en-IN', {style: 'currency', currency: 'INR', minimumFractionDigits: 0, maximumFractionDigits: 0});
            if (signal.aborted) return;
            value.textContent = format.format(data.totals.total_cost);
            const total = Number(data.totals.total_cost);
            const lines = Object.entries(categories).map(([key, label]) => {
                const amount = Number(data.totals[key] || 0);
                const share = total > 0 ? Math.max(0, amount) / total * 100 : 0;
                breakdown.querySelector(`[data-expense-segment="${key}"]`).style.width = `${share}%`;
                return `${label}: ${format.format(amount)} (${share.toFixed(1)}%)`;
            });
            breakdown.title = lines.join('\n');
            breakdown.setAttribute('aria-label', lines.join('; '));
            root.title = 'View expense report';
        } catch (error) {
            if (error.name !== 'AbortError' && !signal.aborted) {
                value.textContent = 'Unavailable';
                breakdown.title = 'Expense breakdown unavailable';
                breakdown.setAttribute('aria-label', breakdown.title);
                root.title = 'Expenses unavailable. Open the report to try again.';
            }
        }
    };
})();
</script>
@endif
