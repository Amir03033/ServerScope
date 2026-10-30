import Chart from 'chart.js/auto';

const chartColors = {
    cyan: '#38bdf8',
    purple: '#a78bfa',
    green: '#4ade80',
    grid: 'rgba(148, 163, 184, 0.10)',
    border: 'rgba(148, 163, 184, 0.16)',
    text: '#8397b1',
    white: '#f8fafc',
    tooltip: '#0b1220',
};

Chart.defaults.font.family =
    'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

Chart.defaults.color = chartColors.text;
Chart.defaults.animation.duration = 650;
Chart.defaults.animation.easing = 'easeOutQuart';

function parseDate(value) {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

// Volledige datum + tijd, voor de tooltip.
function formatTimestamp(value) {
    const date = parseDate(value);

    if (!date) {
        return 'Onbekend';
    }

    return date.toLocaleString('nl-NL', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

// Korte tijdlabels voor de x-as: alleen "14:00", of "12 okt 14:00" bij meer dan 36 uur.
function formatTick(value, includeDay) {
    const date = parseDate(value);

    if (!date) {
        return '';
    }

    const time = date.toLocaleTimeString('nl-NL', {
        hour: '2-digit',
        minute: '2-digit',
    });

    if (!includeDay) {
        return time;
    }

    const day = date.toLocaleDateString('nl-NL', {
        day: 'numeric',
        month: 'short',
    });

    return `${day} ${time}`;
}

function formatNumber(value, decimals = 1) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) {
        return 'Geen data';
    }

    return Number(value).toLocaleString('nl-NL', {
        maximumFractionDigits: decimals,
        minimumFractionDigits: decimals,
    });
}

function hexToRgba(hex, alpha) {
    const value = hex.replace('#', '');
    const r = parseInt(value.slice(0, 2), 16);
    const g = parseInt(value.slice(2, 4), 16);
    const b = parseInt(value.slice(4, 6), 16);

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function createGradient(context, color) {
    const { ctx, chartArea } = context.chart;

    if (!chartArea) {
        return hexToRgba(color, 0.2);
    }

    const gradient = ctx.createLinearGradient(
        0,
        chartArea.top,
        0,
        chartArea.bottom,
    );

    gradient.addColorStop(0, hexToRgba(color, 0.38));
    gradient.addColorStop(1, hexToRgba(color, 0));

    return gradient;
}

function createLineChart(elementId, datasets, options = {}) {
    const canvas = document.getElementById(elementId);

    if (!canvas) {
        return null;
    }

    const labels = options.labels ?? [];

    if (!labels.length) {
        return null;
    }

    const existingChart = Chart.getChart(canvas);

    if (existingChart) {
        existingChart.destroy();
    }

    const chartDatasets = datasets.map((dataset) => {
        const color = dataset.color ?? chartColors.cyan;

        return {
            label: dataset.label,
            data: dataset.data ?? [],
            borderColor: color,
            backgroundColor: (context) => createGradient(context, color),
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 5,
            pointHitRadius: 12,
            pointHoverBackgroundColor: chartColors.white,
            pointHoverBorderColor: color,
            pointHoverBorderWidth: 3,
            tension: 0.35,
            fill: dataset.fill ?? true,
            spanGaps: false,
            borderCapStyle: 'round',
            borderJoinStyle: 'round',
        };
    });

    return new Chart(canvas, {
        type: 'line',

        data: {
            labels,
            datasets: chartDatasets,
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,
            resizeDelay: 100,

            interaction: {
                intersect: false,
                mode: 'index',
            },

            animation: {
                duration: 650,
            },

            layout: {
                padding: {
                    top: 4,
                    right: 6,
                    bottom: 0,
                    left: 0,
                },
            },

            plugins: {
                // De legenda staat als HTML boven de load-grafiek.
                legend: {
                    display: false,
                },

                tooltip: {
                    enabled: true,
                    backgroundColor: chartColors.tooltip,
                    titleColor: chartColors.white,
                    bodyColor: '#cbd5e1',
                    borderColor: chartColors.border,
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 10,
                    displayColors: true,
                    usePointStyle: true,

                    callbacks: {
                        title(items) {
                            const index = items[0]?.dataIndex;

                            return formatTimestamp(options.rawLabels?.[index]);
                        },

                        label(context) {
                            const value = context.parsed.y;

                            if (value === null || !Number.isFinite(value)) {
                                return `${context.dataset.label}: Geen data`;
                            }

                            const formatted = formatNumber(
                                value,
                                options.decimals ?? 1,
                            );

                            return `${context.dataset.label}: ${formatted}${options.percent ? '%' : ''}`;
                        },
                    },
                },
            },

            scales: {
                x: {
                    type: 'category',

                    grid: {
                        display: true,
                        color: chartColors.grid,
                        drawTicks: false,
                    },

                    border: {
                        color: chartColors.border,
                    },

                    ticks: {
                        color: chartColors.text,
                        maxTicksLimit: 7,
                        maxRotation: 0,
                        autoSkip: true,
                        padding: 10,
                        font: {
                            size: 10,
                        },
                    },
                },

                y: {
                    beginAtZero: true,
                    min: 0,

                    ...(options.yMax !== undefined
                        ? { max: options.yMax }
                        : {}),

                    grid: {
                        color: chartColors.grid,
                        drawTicks: false,
                        lineWidth: 1,
                    },

                    border: {
                        display: false,
                    },

                    ticks: {
                        color: chartColors.text,
                        maxTicksLimit: options.percent ? 5 : 6,
                        stepSize: options.percent ? 25 : undefined,
                        padding: 10,
                        font: {
                            size: 10,
                        },

                        callback(value) {
                            return options.percent
                                ? `${value}%`
                                : value;
                        },
                    },
                },
            },

            ...(options.extraOptions ?? {}),
        },
    });
}

function initializeDashboardCharts() {
    const data = window.serverScopeChartData;

    if (!data || !Array.isArray(data.labels)) {
        return;
    }

    const rawLabels = data.labels;

    // Bij een periode langer dan 36 uur ook de dag in het label zetten.
    const first = parseDate(rawLabels[0]);
    const last = parseDate(rawLabels[rawLabels.length - 1]);
    const spanHours = first && last
        ? (last.getTime() - first.getTime()) / 3600000
        : 0;

    const labels = rawLabels.map((value) => formatTick(value, spanHours > 36));

    const alignedData = (values) =>
        rawLabels.map((_, index) => {
            const value = values?.[index];

            if (value === null || value === undefined || value === '') {
                return null;
            }

            const number = Number(value);

            return Number.isFinite(number) ? number : null;
        });

    createLineChart(
        'cpu-chart',
        [
            {
                label: 'CPU-gebruik',
                data: alignedData(data.cpu),
                color: chartColors.cyan,
            },
        ],
        {
            labels,
            rawLabels,
            percent: true,
            yMax: 100,
        },
    );

    createLineChart(
        'memory-chart',
        [
            {
                label: 'RAM-gebruik',
                data: alignedData(data.memory),
                color: chartColors.purple,
            },
        ],
        {
            labels,
            rawLabels,
            percent: true,
            yMax: 100,
        },
    );

    createLineChart(
        'load-chart',
        [
            {
                label: '1 minuut',
                data: alignedData(data.load1),
                color: chartColors.cyan,
                fill: false,
            },
            {
                label: '5 minuten',
                data: alignedData(data.load5),
                color: chartColors.purple,
                fill: false,
            },
            {
                label: '15 minuten',
                data: alignedData(data.load15),
                color: chartColors.green,
                fill: false,
            },
        ],
        {
            labels,
            rawLabels,
            decimals: 2,
        },
    );
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeDashboardCharts, {
        once: true,
    });
} else {
    initializeDashboardCharts();
}
