(function(app_charts, $) {
    'use strict';

    const PALETTE = ['#015ce4', '#d97706', '#0b7a4b', '#7c3aed', '#0496f9', '#db2777', '#0d9488', '#92400e', '#56657f', '#b42318'];
    const MAX_VALUE_LABELS = 24;

    const css = function(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    };

    const isRtl = function() {
        return document.documentElement.dir === 'rtl';
    };

    const isolate = function(text) {
        return '⁦' + text + '⁩';
    };

    const formatter = function(currency) {
        const number = new Intl.NumberFormat('en-US', {maximumFractionDigits: 2});

        return function(value) {
            return isolate((currency.prefix || '') + number.format(value) + (currency.suffix || ''));
        };
    };

    const valueLabels = function(format, rtl, horizontal) {
        return {
            id: 'valueLabels',
            afterDatasetsDraw: function(chart) {
                const dataset = chart.data.datasets[0];
                if (!dataset || dataset.data.length > MAX_VALUE_LABELS) {
                    return;
                }
                const context = chart.ctx;
                context.save();
                context.font = '600 11px ' + chart.options.font.family;
                context.fillStyle = css('--text', '#0b1b3a');
                context.textAlign = 'center';
                context.textBaseline = 'bottom';
                chart.getDatasetMeta(0).data.forEach(function(element, index) {
                    const value = dataset.data[index];
                    if (value === 0) {
                        return;
                    }
                    const text = format(value);
                    if (horizontal) {
                        context.textAlign = rtl ? 'right' : 'left';
                        context.textBaseline = 'middle';
                        context.fillText(text, element.x + (rtl ? -6 : 6), element.y);
                    } else {
                        context.fillText(text, element.x, element.y - 6);
                    }
                });
                context.restore();
            }
        };
    };

    const axisTitle = function(text, color) {
        return text ? {display: true, text: text, color: color, font: {weight: '600'}} : {display: false};
    };

    const pieConfig = function(spec, format, rtl, font) {
        const total = spec.values.reduce(function(sum, value) {
            return sum + value;
        }, 0) || 1;

        return {
            type: 'pie',
            data: {
                labels: spec.labels,
                datasets: [{
                    data: spec.values,
                    backgroundColor: spec.labels.map(function(label, index) {
                        return PALETTE[index % PALETTE.length];
                    }),
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                plugins: {
                    legend: {
                        position: window.innerWidth < 640 ? 'bottom' : 'right',
                        rtl: rtl,
                        textDirection: rtl ? 'rtl' : 'ltr',
                        labels: {boxWidth: 12, font: font, color: css('--text-2', '#3d4c70')}
                    },
                    tooltip: {
                        rtl: rtl,
                        textDirection: rtl ? 'rtl' : 'ltr',
                        callbacks: {
                            label: function(item) {
                                return ' ' + item.label + ': ' + format(item.parsed) + ' (' + isolate((item.parsed * 100 / total).toFixed(1) + '%') + ')';
                            }
                        }
                    }
                }
            }
        };
    };

    const cartesianConfig = function(spec, format, rtl, font) {
        const kind = spec.kind;
        const horizontal = kind === 'hbar';
        const color = css('--blue-500', '#015ce4');
        const muted = css('--muted', '#56657f');
        const line = css('--line', '#dce3ef');
        const category = {
            grid: {display: false},
            ticks: {color: css('--text-2', '#3d4c70'), autoSkip: true, maxRotation: 60, font: font},
            title: axisTitle(horizontal ? spec.yTitle : spec.xTitle, muted)
        };
        const value = {
            beginAtZero: true,
            grid: {color: line},
            ticks: {color: muted, font: font, callback: function(tick) {
                return format(tick);
            }},
            title: axisTitle(horizontal ? spec.xTitle : spec.yTitle, muted)
        };

        if (kind === 'line') {
            category.offset = true;
        }

        if (horizontal) {
            category.position = rtl ? 'right' : 'left';
            category.reverse = false;
            value.reverse = rtl;
        } else {
            category.reverse = rtl;
            value.position = rtl ? 'right' : 'left';
        }

        return {
            type: kind === 'line' ? 'line' : 'bar',
            data: {
                labels: spec.labels,
                datasets: [{
                    label: spec.name || '',
                    data: spec.values,
                    backgroundColor: kind === 'line' ? 'rgba(1, 92, 228, .12)' : color,
                    borderColor: color,
                    borderWidth: kind === 'line' ? 2 : 0,
                    borderRadius: kind === 'line' ? 0 : 4,
                    pointRadius: kind === 'line' ? 3 : 0,
                    pointHoverRadius: 5,
                    fill: kind === 'line',
                    tension: 0
                }]
            },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                layout: {padding: {top: 18, right: horizontal ? 56 : 8}},
                scales: horizontal ? {y: category, x: value} : {x: category, y: value},
                plugins: {
                    legend: {display: false},
                    tooltip: {
                        rtl: rtl,
                        textDirection: rtl ? 'rtl' : 'ltr',
                        callbacks: {
                            label: function(item) {
                                return ' ' + format(item.parsed[horizontal ? 'x' : 'y']);
                            }
                        }
                    }
                }
            },
            plugins: [valueLabels(format, rtl, horizontal)]
        };
    };

    const draw = function(canvas, spec) {
        if (typeof Chart === 'undefined' || !spec || !spec.labels || !spec.labels.length) {
            return null;
        }

        const rtl = isRtl();
        const format = formatter(spec.currency || {});
        const font = {family: getComputedStyle(document.body).fontFamily, size: 12};
        const config = spec.kind === 'pie' ? pieConfig(spec, format, rtl, font) : cartesianConfig(spec, format, rtl, font);

        config.options = $.extend(true, {
            responsive: true,
            maintainAspectRatio: false,
            font: font,
            animation: {duration: 250}
        }, config.options);

        if (spec.kind === 'hbar') {
            canvas.parentElement.style.setProperty('--chart-rows', spec.labels.length);
        }

        return new Chart(canvas, config);
    };

    const toNumber = function(entry) {
        return parseFloat(entry !== null && typeof entry === 'object' ? entry.value : entry) || 0;
    };

    const init = function() {
        $('canvas[data-chart]').each(function() {
            const spec = JSON.parse(this.getAttribute('data-chart'));
            spec.values = (spec.values || []).map(toNumber);
            draw(this, spec);
        });
    };

    $(init);

    $.extend(app_charts, {
        draw: draw
    });

})(window.app_charts = window.app_charts || {}, jQuery);
