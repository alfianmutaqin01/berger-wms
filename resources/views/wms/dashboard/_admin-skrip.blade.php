@isset($m['tren'])
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('grafikTren');
        if (!ctx) return;

        const chartContext = ctx.getContext('2d');
        const gradientMasuk = chartContext.createLinearGradient(0, 0, 0, 300);
        gradientMasuk.addColorStop(0, 'rgba(18, 57, 98, 0.22)');
        gradientMasuk.addColorStop(1, 'rgba(18, 57, 98, 0.01)');

        const gradientSelesai = chartContext.createLinearGradient(0, 0, 0, 300);
        gradientSelesai.addColorStop(0, 'rgba(16, 185, 129, 0.22)');
        gradientSelesai.addColorStop(1, 'rgba(16, 185, 129, 0.01)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($m['tren']['label']),
                datasets: [
                    {
                        label: 'Pesanan Masuk',
                        data: @json($m['tren']['masuk']),
                        borderColor: '#123962',
                        backgroundColor: gradientMasuk,
                        borderWidth: 2.5,
                        pointBackgroundColor: '#123962',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.38,
                    },
                    {
                        label: 'Pesanan Selesai',
                        data: @json($m['tren']['selesai']),
                        borderColor: '#10b981',
                        backgroundColor: gradientSelesai,
                        borderWidth: 2.5,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.38,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            padding: 20,
                            font: { family: "'Inter', sans-serif", size: 12, weight: 500 },
                        },
                    },
                    tooltip: {
                        backgroundColor: '#0d2540',
                        titleFont: { family: "'Inter', sans-serif", size: 12, weight: 600 },
                        bodyFont: { family: "'Inter', sans-serif", size: 12 },
                        padding: 12,
                        cornerRadius: 8,
                        boxPadding: 4,
                        usePointStyle: true,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: "'Inter', sans-serif", size: 11 } },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(226, 232, 240, 0.6)' },
                        ticks: {
                            precision: 0,
                            font: { family: "'Inter', sans-serif", size: 11 },
                            callback: function (val) { return val + ' PO'; },
                        },
                    },
                },
            },
        });
    });
</script>
@endisset
