import Chart from 'chart.js/auto';

const data = window.serverScopeChartData;

if (data) {
    const labels = (data.labels ?? []).map((value) => {
        const date = new Date(value);

        return date.toLocaleString('nl-NL', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    });

    const chartColors = {
        cyan: '#67e8f9',
        purple: '#a78bfa',
        green: '#4ade80',
        yellow: '#facc15',
        grid: 'rgba(148, 163, 184, 0.12)',
        text: '#94a3b8',
    };

    function createLineChart(elementId, datasets, options = {}) {
        const canvas = document.getElementById(elementId);

        if (!canvas) {
            return;
        }

        new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: datasets.map((dataset) => ({
                    ...dataset,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.3,
                    spanGaps: false,
                    fill: false,
                })),
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
                        display: datasets.length > 1,
                        labels: {
                            color: chartColors.text,
                            usePointStyle: true,
                            boxWidth: 7,
                            padding: 18,
                        },
                    },
                    tooltip: {
                        backgroundColor: '#0b1220',
                        borderColor: '#263246',
                        borderWidth: 1,
                        titleColor: '#e5e7eb',
                        bodyColor: '#cbd5e1',
                        padding: 12,
                    },
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                        ticks: {
                            color: chartColors.text,
                            maxTicksLimit: 6,
                            maxRotation: 0,
                        },
                        border: {
                            color: chartColors.grid,
                        },
                    },
                    y: {
                        beginAtZero: true,
                        ...(options.yMax !== undefined
                            ? { max: options.yMax }
                            : {}),
                        grid: {
                            color: chartColors.grid,
                        },
                        ticks: {
                            color: chartColors.text,
                            maxTicksLimit: 5,
                            ...(options.percent
                                ? { callback: (value) => `${value}%` }
                                : {}),
                        },
                        border: {
                            display: false,
                        },
                    },
                },
            },
        });
    }

    createLineChart(
        'cpu-chart',
        [
            {
                label: 'CPU-gebruik',
                data: data.cpu,
                borderColor: chartColors.cyan,
                backgroundColor: chartColors.cyan,
            },
        ],
        { percent: true, yMax: 100 },
    );

    createLineChart(
        'memory-chart',
        [
            {
                label: 'RAM-gebruik',
                data: data.memory,
                borderColor: chartColors.purple,
                backgroundColor: chartColors.purple,
            },
        ],
        { percent: true, yMax: 100 },
    );

    createLineChart('load-chart', [
        {
            label: '1 minuut',
            data: data.load1,
            borderColor: chartColors.cyan,
            backgroundColor: chartColors.cyan,
        },
        {
            label: '5 minuten',
            data: data.load5,
            borderColor: chartColors.purple,
            backgroundColor: chartColors.purple,
        },
        {
            label: '15 minuten',
            data: data.load15,
            borderColor: chartColors.green,
            backgroundColor: chartColors.green,
        },
    ]);
}
