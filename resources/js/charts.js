import Chart from 'chart.js/auto';

import { t, numberLocale } from './i18n';

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

// Colours come from the design tokens, so the charts follow the theme.
function token(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value || fallback;
}

function palette() {
    return {
        series: [token('--ss-accent', '#2f5fd0'), token('--ss-muted', '#52525b'), token('--ss-subtle', '#63636b')],
        grid: token('--ss-line', '#e4e4e7'),
        border: token('--ss-line-strong', '#d4d4d8'),
        text: token('--ss-muted', '#52525b'),
        surface: token('--ss-surface', '#ffffff'),
        fg: token('--ss-text', '#18181b'),
    };
}

let chartColors = palette();

Chart.defaults.font.family =
    'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

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
        return t('chart.unknown');
    }

    return date.toLocaleString(numberLocale(), {
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

    const time = date.toLocaleTimeString(numberLocale(), {
        hour: '2-digit',
        minute: '2-digit',
    });

    if (!includeDay) {
        return time;
    }

    const day = date.toLocaleDateString(numberLocale(), {
        day: 'numeric',
        month: 'short',
    });

    return `${day} ${time}`;
}

function formatNumber(value, decimals = 1) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) {
        return t('chart.no_data');
    }

    return Number(value).toLocaleString(numberLocale(), {
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

    gradient.addColorStop(0, hexToRgba(color, 0.22));
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
        const color = dataset.color ?? chartColors.series[0];

        return {
            label: dataset.label,
            data: dataset.data ?? [],
            borderColor: color,
            backgroundColor: (context) => createGradient(context, color),
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 5,
            pointHitRadius: 12,
            pointHoverBackgroundColor: chartColors.surface,
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
                duration: reducedMotion.matches ? 0 : 650,
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
                    backgroundColor: chartColors.surface,
                    titleColor: chartColors.fg,
                    bodyColor: chartColors.text,
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
    // Data from <script type="application/json" id="chart-data">, or the
    // older global that screens not yet rebuilt still set.
    let data = window.serverScopeChartData;
    const source = document.getElementById('chart-data');

    if (source) {
        try {
            data = JSON.parse(source.textContent);
        } catch {
            data = null;
        }
    }

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
                label: t('chart.cpu'),
                data: alignedData(data.cpu),
                color: chartColors.series[0],
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
                label: t('chart.ram'),
                data: alignedData(data.memory),
                color: chartColors.series[0],
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
                label: t('chart.load_1'),
                data: alignedData(data.load1),
                color: chartColors.series[0],
                fill: false,
            },
            {
                label: t('chart.load_5'),
                data: alignedData(data.load5),
                color: chartColors.series[1],
                fill: false,
            },
            {
                label: t('chart.load_15'),
                data: alignedData(data.load15),
                color: chartColors.series[2],
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

function render() {
    chartColors = palette();
    Chart.defaults.color = chartColors.text;
    initializeDashboardCharts();
}

// Redraw when the theme changes (switch or system setting).
new MutationObserver(render).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', render);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render, {
        once: true,
    });
} else {
    render();
}
