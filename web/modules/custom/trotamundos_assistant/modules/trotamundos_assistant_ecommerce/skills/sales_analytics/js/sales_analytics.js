/**
 * @file
 * Sales analytics dashboard behaviour.
 *
 * Charts are drawn with ECharts. Brand colours are read from the theme CSS
 * custom properties so each subsite keeps its own palette; only the category
 * breakdown uses a fixed categorical scale, because eight slices need eight
 * distinguishable hues that a single brand colour cannot provide.
 */

((Drupal, once) => {
  'use strict';

  /**
   * Fixed categorical hues, assigned in order and never cycled.
   *
   * Validated for colour-vision deficiency separation on a light surface.
   * Three of them fall below 3:1 contrast, so the donut always ships visible
   * labels rather than relying on colour alone.
   */
  const CATEGORICAL = [
    '#2a78d6', '#eb6834', '#1baf7a', '#eda100',
    '#e87ba4', '#008300', '#4a3aa7', '#e34948',
  ];

  /**
   * Reads a CSS custom property, falling back when the theme does not set it.
   */
  const token = (styles, name, fallback) => {
    const value = styles.getPropertyValue(name).trim();
    return value !== '' ? value : fallback;
  };

  /**
   * Collects the palette of the active subsite.
   */
  const readTheme = () => {
    const styles = window.getComputedStyle(document.documentElement);
    return {
      primary: token(styles, '--primary', '#480e2a'),
      ink: token(styles, '--foreground', '#1a0f14'),
      muted: token(styles, '--muted-foreground', '#767676'),
      border: token(styles, '--border', '#e0e0e0'),
      surface: token(styles, '--card', '#ffffff'),
    };
  };

  /**
   * Shared axis, grid and tooltip styling: recessive lines, ink-coloured text.
   */
  const baseOptions = (theme) => ({
    backgroundColor: 'transparent',
    textStyle: { fontFamily: 'inherit', color: theme.ink },
    grid: { left: 8, right: 16, top: 28, bottom: 8, containLabel: true },
    tooltip: {
      trigger: 'item',
      backgroundColor: theme.surface,
      borderColor: theme.border,
      textStyle: { color: theme.ink },
      extraCssText: 'box-shadow: 0 2px 12px rgba(0,0,0,.12);',
    },
  });

  /**
   * Formats an amount for axis ticks, shortening thousands.
   */
  const shortMoney = (value) => (Math.abs(value) >= 1000
    ? `${Math.round(value / 1000)}k`
    : String(Math.round(value)));

  /**
   * Monthly revenue against the same months of the previous year.
   *
   * Both series measure money, so they share one axis.
   */
  const monthlyOption = (report, theme) => ({
    ...baseOptions(theme),
    tooltip: { ...baseOptions(theme).tooltip, trigger: 'axis' },
    legend: {
      data: [Drupal.t('Periodo actual'), Drupal.t('Año anterior')],
      top: 0,
      icon: 'roundRect',
      itemWidth: 10,
      itemHeight: 10,
      textStyle: { color: theme.muted },
    },
    xAxis: {
      type: 'category',
      data: report.monthly.labels,
      axisLine: { lineStyle: { color: theme.border } },
      axisTick: { show: false },
      axisLabel: { color: theme.muted },
    },
    yAxis: {
      type: 'value',
      splitLine: { lineStyle: { color: theme.border, type: 'dashed' } },
      axisLabel: { color: theme.muted, formatter: shortMoney },
    },
    series: [
      {
        name: Drupal.t('Periodo actual'),
        type: 'bar',
        data: report.monthly.current,
        itemStyle: { color: theme.primary, borderRadius: [4, 4, 0, 0] },
        barMaxWidth: 28,
        barGap: '10%',
      },
      {
        name: Drupal.t('Año anterior'),
        type: 'bar',
        data: report.monthly.previous,
        itemStyle: { color: theme.muted, opacity: 0.45, borderRadius: [4, 4, 0, 0] },
        barMaxWidth: 28,
      },
    ],
  });

  /**
   * Revenue split by product category: identity, so a categorical scale.
   */
  const donutOption = (report, theme) => ({
    ...baseOptions(theme),
    tooltip: {
      ...baseOptions(theme).tooltip,
      formatter: (params) => {
        const entry = report.categories.find((item) => item.name === params.name);
        return `${params.marker} ${params.name}<br><strong>${entry ? entry.formatted : params.value}</strong> (${params.percent} %)`;
      },
    },
    legend: {
      type: 'scroll',
      orient: 'vertical',
      right: 0,
      top: 'middle',
      icon: 'circle',
      itemWidth: 9,
      itemHeight: 9,
      textStyle: { color: theme.muted },
      formatter: (name) => {
        const entry = report.categories.find((item) => item.name === name);
        return entry ? `${name}  ${entry.formatted}` : name;
      },
    },
    series: [{
      type: 'pie',
      radius: ['45%', '72%'],
      center: ['30%', '50%'],
      data: report.categories.map((entry, index) => ({
        name: entry.name,
        value: entry.value,
        itemStyle: { color: CATEGORICAL[index % CATEGORICAL.length] },
      })),
      // A 2px surface ring keeps adjacent slices legible.
      itemStyle: { borderColor: theme.surface, borderWidth: 2 },
      // Identity lives in the legend, which carries the amount as well: slice
      // labels collide once the small categories bunch together.
      label: { show: false },
      labelLine: { show: false },
    }],
  });

  /**
   * Ranking bars: magnitude, so one hue rather than a colour per row.
   */
  const rankingOption = (rows, theme) => ({
    ...baseOptions(theme),
    tooltip: {
      ...baseOptions(theme).tooltip,
      formatter: (params) => {
        const entry = rows.find((row) => row.name === params.name);
        return `${params.name}<br><strong>${entry ? entry.formatted : params.value}</strong>`;
      },
    },
    grid: { left: 8, right: 56, top: 12, bottom: 8, containLabel: true },
    xAxis: {
      type: 'value',
      splitLine: { lineStyle: { color: theme.border, type: 'dashed' } },
      axisLabel: { color: theme.muted, formatter: shortMoney },
    },
    yAxis: {
      type: 'category',
      data: rows.map((row) => row.name).reverse(),
      axisLine: { lineStyle: { color: theme.border } },
      axisTick: { show: false },
      axisLabel: { color: theme.ink, width: 190, overflow: 'truncate' },
    },
    series: [{
      type: 'bar',
      data: rows.map((row) => row.value).reverse(),
      itemStyle: { color: theme.primary, borderRadius: [0, 4, 4, 0] },
      barMaxWidth: 18,
    }],
  });

  /**
   * Draws every chart of one dashboard, disposing previous instances first.
   */
  const render = (root, report) => {
    const theme = readTheme();
    const specs = {
      monthly: () => monthlyOption(report, theme),
      categories: () => donutOption(report, theme),
      products: () => rankingOption(report.products, theme),
      stores: () => rankingOption(report.stores, theme),
    };

    root.querySelectorAll('[data-wa-chart]').forEach((element) => {
      const spec = specs[element.dataset.waChart];
      if (!spec) {
        return;
      }

      const existing = window.echarts.getInstanceByDom(element);
      if (existing) {
        existing.dispose();
      }

      const chart = window.echarts.init(element, null, { renderer: 'svg' });
      chart.setOption(spec());
      root.chartInstances = root.chartInstances || [];
      root.chartInstances.push(chart);
    });
  };

  /**
   * Repaints the KPI tiles and the order table after a filter change.
   */
  const refreshSummary = (root, report) => {
    const kpis = root.querySelectorAll('.wa-sales__kpi');
    report.kpis.forEach((kpi, index) => {
      const tile = kpis[index];
      if (!tile) {
        return;
      }
      tile.querySelector('.wa-sales__kpi-value').textContent = kpi.value;
      const delta = tile.querySelector('.wa-sales__kpi-delta');
      if (delta) {
        delta.textContent = kpi.delta ? kpi.delta.formatted : '';
        delta.className = `wa-sales__kpi-delta wa-sales__kpi-delta--${kpi.delta ? kpi.delta.direction : 'flat'}`;
      }
    });

    const period = root.querySelector('[data-wa-sales-period]');
    if (period) {
      period.textContent = report.period_label;
    }

    const body = root.querySelector('[data-wa-sales-rows]');
    if (!body) {
      return;
    }
    body.textContent = '';
    report.orders.forEach((row) => {
      const tr = document.createElement('tr');
      ['number', 'date', 'customer', 'store', 'total'].forEach((key) => {
        const td = document.createElement('td');
        td.textContent = row[key];
        if (key === 'total') {
          td.className = 'is-numeric';
        }
        tr.appendChild(td);
      });

      // The URL is only present when the server confirmed print access for
      // this order, so an absent one simply leaves the cell empty.
      const invoice = document.createElement('td');
      if (row.pdf_url) {
        const link = document.createElement('a');
        link.className = 'wa-sales__invoice';
        link.href = row.pdf_url;
        link.textContent = Drupal.t('Descargar');
        invoice.appendChild(link);
      }
      tr.appendChild(invoice);

      body.appendChild(tr);
    });
  };

  /**
   * Asks the skill endpoint for another period. No model call involved.
   */
  const loadRange = (root, range) => {
    root.classList.add('is-loading');
    const notice = root.querySelector('[data-wa-sales-error]');
    if (notice) {
      notice.hidden = true;
    }

    fetch(Drupal.url(`trotamundos-assistant/skills/sales_analytics/report?range=${encodeURIComponent(range)}`), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
      .then((payload) => {
        if (!payload.report) {
          return;
        }
        refreshSummary(root, payload.report);
        render(root, payload.report);
      })
      .catch(() => {
        const notice = root.querySelector('[data-wa-sales-error]');
        if (notice) {
          notice.textContent = Drupal.t('No se ha podido actualizar el informe. Inténtalo de nuevo.');
          notice.hidden = false;
        }
      })
      .finally(() => {
        root.classList.remove('is-loading');
      });
  };

  Drupal.behaviors.trotamundosAssistantSalesAnalytics = {
    attach(context) {
      once('wa-sales', '[data-wa-sales]', context).forEach((root) => {
        let report;
        try {
          report = JSON.parse(root.dataset.report);
        }
        catch (error) {
          return;
        }

        if (!window.echarts) {
          return;
        }

        render(root, report);

        root.querySelectorAll('[data-wa-sales-range]').forEach((button) => {
          button.addEventListener('click', () => {
            root.querySelectorAll('[data-wa-sales-range]').forEach((other) => {
              other.classList.toggle('is-active', other === button);
            });
            loadRange(root, button.dataset.waSalesRange);
          });
        });

        window.addEventListener('resize', () => {
          (root.chartInstances || []).forEach((chart) => chart.resize());
        });
      });
    },
  };
})(Drupal, once);
