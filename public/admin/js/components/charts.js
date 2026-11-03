import { chartColors, destroyChart } from '../utils.js';

const defaults = () => {
  const c = chartColors();
  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 400 },
    plugins: {
      legend: {
        labels: { color: c.text, boxWidth: 12, font: { size: 11 } },
      },
      tooltip: {
        backgroundColor: '#171c26',
        borderColor: '#2a3345',
        borderWidth: 1,
        titleColor: '#e8edf7',
        bodyColor: '#9aa6bc',
      },
    },
    scales: {
      x: {
        ticks: { color: c.text, font: { size: 10 } },
        grid: { color: c.grid },
      },
      y: {
        ticks: { color: c.text, font: { size: 10 } },
        grid: { color: c.grid },
        beginAtZero: true,
      },
    },
  };
};

export function lineChart(canvas, labels, datasets) {
  destroyChart(canvas._chart);
  const c = chartColors();
  const palette = [c.accent, c.blue, c.warn, c.danger];
  canvas._chart = new Chart(canvas, {
    type: 'line',
    data: {
      labels,
      datasets: datasets.map((d, i) => ({
        tension: 0.35,
        borderWidth: 2,
        pointRadius: 0,
        fill: false,
        borderColor: d.color || palette[i % palette.length],
        backgroundColor: (d.color || palette[i % palette.length]) + '33',
        ...d,
      })),
    },
    options: defaults(),
  });
  return canvas._chart;
}

export function barChart(canvas, labels, data, color) {
  destroyChart(canvas._chart);
  const c = chartColors();
  canvas._chart = new Chart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        data,
        backgroundColor: color || c.blue,
        borderRadius: 4,
        barThickness: 18,
      }],
    },
    options: {
      ...defaults(),
      plugins: { ...defaults().plugins, legend: { display: false } },
    },
  });
  return canvas._chart;
}

export function doughnutChart(canvas, labels, data) {
  destroyChart(canvas._chart);
  const c = chartColors();
  const colors = [c.accent, c.blue, c.warn, c.danger, c.mute, '#a78bfa', '#22d3ee'];
  canvas._chart = new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data,
        backgroundColor: labels.map((_, i) => colors[i % colors.length]),
        borderWidth: 0,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '68%',
      plugins: {
        legend: {
          position: 'right',
          labels: { color: c.text, boxWidth: 10, font: { size: 11 } },
        },
      },
    },
  });
  return canvas._chart;
}

/** Rolling series buffer for live charts. */
export function createSeries(max = 24) {
  const labels = [];
  const series = {};
  return {
    push(label, values) {
      labels.push(label);
      Object.entries(values).forEach(([k, v]) => {
        if (!series[k]) series[k] = [];
        series[k].push(Number(v) || 0);
      });
      while (labels.length > max) {
        labels.shift();
        Object.values(series).forEach((arr) => arr.shift());
      }
    },
    labels: () => labels.slice(),
    get(key) {
      return (series[key] || []).slice();
    },
    keys() {
      return Object.keys(series);
    },
  };
}
