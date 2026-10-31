(function () {
    /**
     * Renders the dedicated WhatsApp summary card template into an isolated iframe
     * (sidestepping Tailwind oklch styling issues) and captures it as a PNG image.
     */
    function captureCardImage(htmlContent, filename) {
        if (typeof html2canvas === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
            script.onload = function () {
                renderAndDownload(htmlContent, filename);
            };
            document.head.appendChild(script);
        } else {
            renderAndDownload(htmlContent, filename);
        }
    }

    function renderAndDownload(htmlContent, filename) {
        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.top = '-99999px';
        iframe.style.left = '-99999px';
        iframe.style.width = '720px';
        iframe.style.height = '1600px';
        document.body.appendChild(iframe);

        new Promise(function (resolve) {
            iframe.onload = resolve;
            iframe.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;background:#faf6ef;">' + htmlContent + '</body></html>';
        }).then(function () {
            const target = iframe.contentDocument.body.firstElementChild || iframe.contentDocument.body;
            return html2canvas(target, { backgroundColor: '#faf6ef', scale: 2 });
        }).then(function (canvas) {
            const link = document.createElement('a');
            link.download = filename || 'summary-card.png';
            link.href = canvas.toDataURL('image/png');
            document.body.appendChild(link);
            link.click();
            link.remove();
        }).catch(function (err) {
            console.error('Summary card image export failed:', err);
        }).finally(function () {
            iframe.remove();
        });
    }

    window.addEventListener('trigger-summary-card-export', function (event) {
        const source = document.getElementById('report-summary-card-image-source');
        if (source) {
            const reportName = (event.detail && event.detail.reportName) || 'report';
            const startDate = (event.detail && event.detail.startDate) || 'start';
            const endDate = (event.detail && event.detail.endDate) || 'end';
            captureCardImage(source.innerHTML, `${reportName}-${startDate}-to-${endDate}.png`);
        }
    });
})();
