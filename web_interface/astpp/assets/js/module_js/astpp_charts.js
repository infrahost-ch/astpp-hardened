/*
 * ASTPP fork: dashboard charts on Chart.js (MIT). Replaces Highcharts, which needs a paid
 * license for commercial use. Only what the dashboards use:
 *
 *   astppComboChart(selector, {categories, y1Title, tooltip, series: [{name, type: 'column'|'line',
 *                   color, yAxis: 0|1, data, dashed}]})
 *   astppPieChart(selector, [[name, value], ...], tooltip)
 *
 * "tooltip" is an optional Highcharts style formatter: called with this = {series: {name},
 * point: {name}, x, y}, returns text with <b> and <br/> (the existing formatters are reused).
 */
(function (window, $) {
	'use strict';
	var charts = {};
	// default Highcharts colour palette, keeps the dashboards looking familiar
	var palette = ['#7cb5ec', '#434348', '#90ed7d', '#f7a35c', '#8085e9', '#f15c80', '#e4d354', '#2b908f', '#f45b5b', '#91e8e1'];

	function canvasIn(selector, height) {
		var $el = $(selector), id = $el.attr('id') || selector;
		if (charts[id]) {
			charts[id].destroy();
			delete charts[id];
		}
		$el.empty();
		var $box = $('<div class="astpp-chart-box"></div>').css({ position: 'relative', height: height + 'px', width: '100%' }).appendTo($el);
		return { id: id, canvas: $('<canvas></canvas>').appendTo($box)[0] };
	}
	// formatter output (HTML with <b>/<br/>) -> tooltip lines (plain text, never rendered as HTML)
	function lines(html) {
		return String(html).split(/<br\s*\/?>/i).map(function (l) {
			return $('<div>').html(l).text();
		});
	}
	// Highcharts data points are numbers or [category, value] pairs
	function number(v) {
		if (Array.isArray(v)) {
			v = v[1];
		}
		var n = parseFloat(v);
		return isNaN(n) ? 0 : n;
	}

	window.astppComboChart = function (selector, cfg) {
		var c = canvasIn(selector, 400);
		var datasets = $.map(cfg.series, function (s, i) {
			var line = s.type !== 'column';
			return [{
				type: line ? 'line' : 'bar',
				label: s.name,
				data: $.map(s.data || [], function (v) { return [number(v)]; }),
				backgroundColor: String(s.color || palette[i % palette.length]).trim(),
				borderColor: String(s.color || palette[i % palette.length]).trim(),
				yAxisID: s.yAxis ? 'y1' : 'y',
				borderDash: line && s.dashed ? [2, 4] : [],
				borderWidth: line ? 2 : 0,
				tension: 0.4,
				pointRadius: line ? 3 : 0,
				order: line ? 1 : 2
			}];
		});
		charts[c.id] = new window.Chart(c.canvas, {
			data: { labels: cfg.categories || [], datasets: datasets },
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'nearest', intersect: true },
				scales: {
					y: { beginAtZero: true, position: 'left' },
					y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false },
						title: { display: !!cfg.y1Title, text: cfg.y1Title || '' } }
				},
				plugins: {
					legend: { position: 'top' },
					tooltip: {
						callbacks: {
							title: function () { return ''; },
							label: function (ctx) {
								if (!cfg.tooltip) {
									return ctx.dataset.label + ': ' + ctx.formattedValue;
								}
								return lines(cfg.tooltip.call({ series: { name: ctx.dataset.label }, x: ctx.label, y: ctx.parsed.y }));
							}
						}
					}
				}
			}
		});
		return charts[c.id];
	};

	window.astppPieChart = function (selector, data, tooltip) {
		var c = canvasIn(selector, 400);
		var rows = $.grep(data || [], function (d) { return Array.isArray(d) && d.length >= 2; });
		charts[c.id] = new window.Chart(c.canvas, {
			type: 'pie',
			data: {
				labels: $.map(rows, function (d) { return [String(d[0])]; }),
				datasets: [{ data: $.map(rows, function (d) { return [number(d[1])]; }), backgroundColor: palette, borderWidth: 1 }]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { position: 'bottom' },
					tooltip: {
						callbacks: {
							label: function (ctx) {
								if (!tooltip) {
									return ctx.label + ': ' + ctx.formattedValue;
								}
								return lines(tooltip.call({ point: { name: ctx.label }, x: ctx.label, y: ctx.parsed }));
							}
						}
					}
				}
			}
		});
		return charts[c.id];
	};
})(window, jQuery);
