// Export Table to Excel/CSV
class TableExporter {
    constructor() {
        this.init();
    }

    init() {
        // Add export buttons to tables with data-exportable attribute
        document.querySelectorAll('[data-exportable]').forEach(table => {
            this.addExportButton(table);
        });
    }

    addExportButton(table) {
        const container = table.closest('.table-container');
        if (!container) return;

        if (container.querySelector('.export-btn-group')) {
            return;
        }

        const buttonGroup = document.createElement('div');
        buttonGroup.className = 'export-btn-group flex flex-wrap gap-2 mb-4';
        buttonGroup.innerHTML = `
            <button class="export-btn inline-flex items-center gap-2 rounded-xl bg-[#1e4620] text-white px-4 py-2 text-sm font-semibold shadow-sm hover:bg-[#25682d] transition" data-format="excel">
                <span class="inline-flex items-center justify-center w-5 h-5 bg-white/10 rounded-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" class="w-4 h-4 text-white">
                        <path fill="#4CAF50" d="M41,10H25v28h16c0.553,0,1-0.447,1-1V11C42,10.447,41.553,10,41,10z"></path>
                        <path fill="#FFF" d="M32 15H39V18H32zM32 25H39V28H32zM32 30H39V33H32zM32 20H39V23H32zM25 15H30V18H25zM25 25H30V28H25zM25 30H30V33H25zM25 20H30V23H25z"></path>
                        <path fill="#2E7D32" d="M27 42L6 38 6 10 27 6z"></path>
                        <path fill="#FFF" d="M19.129,31l-2.411-4.561c-0.092-0.171-0.186-0.483-0.284-0.938h-0.037c-0.046,0.215-0.154,0.541-0.324,0.979L13.652,31H9.895l4.462-7.001L10.274,17h3.837l2.001,4.196c0.156,0.331,0.296,0.725,0.42,1.179h0.04c0.078-0.271,0.224-0.68,0.439-1.22L19.237,17h3.515l-4.199,6.939l4.316,7.059h-3.74V31z"></path>
                    </svg>
                </span>
                Export Excel
            </button>
            <button class="export-btn inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white text-gray-700 px-4 py-2 text-sm font-semibold shadow-sm hover:bg-gray-50 transition" data-format="csv">
                <i class="fa-solid fa-file-csv"></i>
                Export CSV
            </button>
        `;

        container.insertBefore(buttonGroup, table);

        buttonGroup.querySelectorAll('.export-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const format = btn.dataset.format;
                this.exportTable(table, format);
            });
        });
    }

    exportTable(table, format = 'excel') {
        const data = this.extractTableData(table);
        const title = table.dataset.exportTitle || 'Asset';

        if (format === 'excel') {
            this.exportToExcel(data, table.dataset.exportName || 'export', title);
        } else if (format === 'csv') {
            this.exportToCSV(data, table.dataset.exportName || 'export');
        }
    }

    extractTableData(table) {
        const data = [];
        const headers = [];

        // Extract headers
        headers.push('S.No');
        table.querySelectorAll('thead th').forEach(th => {
            headers.push(th.textContent.trim());
        });
        data.push(headers);

        // Extract rows
        let serial = 1;

		table.querySelectorAll('tbody tr').forEach(tr => {
		const row = [];

		tr.querySelectorAll('td').forEach(td => {
		if (!td.classList.contains('actions')) {
		row.push(td.textContent.trim());
		}
		});

		if (row.length > 0) {
		row.unshift(String(serial++));
		data.push(row);
		}
		});

        return data;
    }

    exportToCSV(data, filename) {
        const csv = data.map(row =>
            row.map(cell => `"${cell}"`).join(',')
        ).join('\n');

        this.downloadFile(csv, `${filename}.csv`, 'text/csv');
    }

    exportToExcel(data, filename, title = 'Asset') {
        const columns = data[0] ? data[0].length : 1;

        // Simple Excel XML format with a title row and indentation styling
        let excel = '<?xml version="1.0"?>\n';
        excel += '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"\n';
        excel += ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n';
        excel += '<Styles>\n';
        excel += '<Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14"/><Alignment ss:Horizontal="Center"/></Style>\n';
        excel += '<Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#f3f4f6" ss:Pattern="Solid"/></Style>\n';
        excel += '<Style ss:ID="Indented"><Alignment ss:Indent="1" ss:Horizontal="Left"/></Style>\n';
        excel += '</Styles>\n';
        excel += '<Worksheet ss:Name="Sheet1">\n<Table>\n';

        // Column widths (added)
        excel += '<Column ss:Width="40"/>\n';   // S.No
        excel += '<Column ss:Width="70"/>\n';   // Date
        excel += '<Column ss:Width="110"/>\n';  // Expense Category
        excel += '<Column ss:Width="60"/>\n';   // Amount
        excel += '<Column ss:Width="70"/>\n';   // Paid By
        excel += '<Column ss:Width="180"/>\n';  // Description
        excel += '<Column ss:Width="90"/>\n';   // Updated/Info
        excel += '<Column ss:Width="90"/>\n';   // Actions

        // Blank first row
        excel += '<Row><Cell/></Row>\n';

        // Title row
        excel += `<Row><Cell ss:StyleID="Title" ss:MergeAcross="${columns - 1}"><Data ss:Type="String">${this.escapeXml(title)}</Data></Cell></Row>\n`;

        data.forEach((row, rowIndex) => {
            excel += '<Row>\n';
            row.forEach(cell => {
                const style = rowIndex === 0 ? ' ss:StyleID="Header"' : ' ss:StyleID="Indented"';
                excel += `<Cell${style}><Data ss:Type="String">${this.escapeXml(cell)}</Data></Cell>\n`;
            });
            excel += '</Row>\n';
        });

        excel += '</Table>\n</Worksheet>\n</Workbook>';

        this.downloadFile(excel, `${filename}.xls`, 'application/vnd.ms-excel');
    }

    escapeXml(text) {
        return text.replace(/[<>&'"]/g, char => {
            switch (char) {
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '&': return '&amp;';
                case "'": return '&apos;';
                case '"': return '&quot;';
            }
        });
    }

    downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);

        window.toast.success(`File exported successfully: ${filename}`);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    window.tableExporter = new TableExporter();
});

// Reinitialize after Livewire updates
document.addEventListener('livewire:navigated', () => {
    window.tableExporter = new TableExporter();
});
