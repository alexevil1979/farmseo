/** Inline SVG diagrams for the docs section (dark-theme, no external assets). */

const NS = 'http://www.w3.org/2000/svg';

function svg(attrs, children = []) {
  const node = document.createElementNS(NS, 'svg');
  for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, String(v));
  for (const c of children) if (c) node.append(c);
  return node;
}

function el(tag, attrs = {}, children = []) {
  const node = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'text') node.textContent = v;
    else node.setAttribute(k, String(v));
  }
  for (const c of [].concat(children)) if (c) node.append(c);
  return node;
}

function box(x, y, w, h, label, opts = {}) {
  const fill = opts.fill || '#1e2533';
  const stroke = opts.stroke || '#3ecf8e';
  const g = el('g', {});
  g.append(
    el('rect', {
      x, y, width: w, height: h, rx: 8, ry: 8,
      fill, stroke, 'stroke-width': 1.5,
    }),
    el('text', {
      x: x + w / 2, y: y + h / 2 + 4,
      'text-anchor': 'middle', fill: '#e8edf7',
      'font-size': '12', 'font-family': 'IBM Plex Sans, Segoe UI, sans-serif',
      text: label,
    }),
  );
  return g;
}

function arrow(x1, y1, x2, y2) {
  const g = el('g', {});
  g.append(
    el('defs', {}, [
      el('marker', {
        id: 'arr', viewBox: '0 0 10 10', refX: 8, refY: 5,
        markerWidth: 6, markerHeight: 6, orient: 'auto-start-reverse',
      }, [
        el('path', { d: 'M 0 0 L 10 5 L 0 10 z', fill: '#5b8def' }),
      ]),
    ]),
    el('line', {
      x1, y1, x2, y2,
      stroke: '#5b8def', 'stroke-width': 2,
      'marker-end': 'url(#arr)',
    }),
  );
  return g;
}

function caption(text) {
  return el('text', {
    x: 20, y: 22,
    fill: '#9aa6bc', 'font-size': '11',
    'font-family': 'IBM Plex Sans, Segoe UI, sans-serif',
    text,
  });
}

export const ILLUSTRATIONS = {
  pipeline() {
    const root = svg({ viewBox: '0 0 720 180', class: 'docs-svg', role: 'img', 'aria-label': 'Пайплайн Farm' });
    root.append(
      caption('Поток работы: от ресурса до Chrome'),
      box(24, 60, 100, 56, 'Прокси', { stroke: '#5b8def' }),
      box(152, 60, 100, 56, 'Аккаунты'),
      box(280, 60, 100, 56, 'Сценарий', { stroke: '#e8a838' }),
      box(408, 60, 100, 56, 'Задача'),
      box(536, 60, 100, 56, 'Worker', { stroke: '#3ecf8e' }),
      arrow(124, 88, 152, 88),
      arrow(252, 88, 280, 88),
      arrow(380, 88, 408, 88),
      arrow(508, 88, 536, 88),
      el('text', {
        x: 586, y: 148, 'text-anchor': 'middle', fill: '#6b778f', 'font-size': '11',
        text: 'Chrome + профиль',
      }),
    );
    return root;
  },

  firstRun() {
    const root = svg({ viewBox: '0 0 720 200', class: 'docs-svg', role: 'img', 'aria-label': 'Первый запуск' });
    const steps = [
      [40, '1. Прокси'],
      [170, '2. Аккаунт'],
      [300, '3. FP'],
      [430, '4. Сценарий'],
      [560, '5. Задача'],
    ];
    root.append(caption('Минимальный путь к первой успешной задаче'));
    steps.forEach(([x, label], i) => {
      root.append(box(x, 70, 110, 50, label, { stroke: i === 4 ? '#3ecf8e' : '#5b8def' }));
      if (i < steps.length - 1) root.append(arrow(x + 110, 95, steps[i + 1][0], 95));
    });
    root.append(el('text', {
      x: 360, y: 170, 'text-anchor': 'middle', fill: '#9aa6bc', 'font-size': '12',
      text: 'Планировщик подхватит расписание → Redis → Worker',
    }));
    return root;
  },

  accounts() {
    const root = svg({ viewBox: '0 0 720 200', class: 'docs-svg', role: 'img', 'aria-label': 'Аккаунт' });
    root.append(
      caption('Связки аккаунта'),
      box(60, 70, 140, 70, 'Аккаунт', { stroke: '#3ecf8e' }),
      box(280, 40, 130, 50, 'Прокси'),
      box(280, 110, 130, 50, 'Fingerprint'),
      box(500, 70, 150, 70, 'Chrome profile', { stroke: '#5b8def' }),
      arrow(200, 90, 280, 65),
      arrow(200, 115, 280, 135),
      arrow(410, 90, 500, 105),
    );
    return root;
  },

  queue() {
    const root = svg({ viewBox: '0 0 720 180', class: 'docs-svg', role: 'img', 'aria-label': 'Очередь' });
    root.append(
      caption('Очередь задач'),
      box(40, 60, 120, 56, 'Scheduler'),
      box(220, 60, 120, 56, 'MySQL tasks'),
      box(400, 60, 120, 56, 'Redis Q'),
      box(580, 60, 110, 56, 'Worker', { stroke: '#3ecf8e' }),
      arrow(160, 88, 220, 88),
      arrow(340, 88, 400, 88),
      arrow(520, 88, 580, 88),
    );
    return root;
  },

  liveview() {
    const root = svg({ viewBox: '0 0 720 180', class: 'docs-svg', role: 'img', 'aria-label': 'Live view' });
    root.append(
      caption('Live-view сессии'),
      box(50, 55, 140, 70, 'Админка', { stroke: '#5b8def' }),
      box(280, 55, 160, 70, 'Worker :3000'),
      box(530, 55, 140, 70, 'Xvfb + Chrome', { stroke: '#3ecf8e' }),
      arrow(190, 90, 280, 90),
      arrow(440, 90, 530, 90),
      el('text', {
        x: 360, y: 155, 'text-anchor': 'middle', fill: '#9aa6bc', 'font-size': '11',
        text: '/live/{accountId}/ → VNC/noVNC кадр',
      }),
    );
    return root;
  },

  campaign() {
    const root = svg({ viewBox: '0 0 720 200', class: 'docs-svg', role: 'img', 'aria-label': 'Кампания' });
    root.append(
      caption('Кампания ПФ'),
      box(280, 40, 160, 48, 'Кампания', { stroke: '#3ecf8e' }),
      box(40, 120, 130, 48, 'Сценарий'),
      box(210, 120, 130, 48, 'Ключи'),
      box(380, 120, 130, 48, 'Цели'),
      box(550, 120, 130, 48, 'Задачи'),
      arrow(320, 88, 105, 120),
      arrow(360, 88, 275, 120),
      arrow(400, 88, 445, 120),
      arrow(420, 88, 615, 120),
    );
    return root;
  },

  monitoring() {
    const root = svg({ viewBox: '0 0 720 180', class: 'docs-svg', role: 'img', 'aria-label': 'Мониторинг' });
    root.append(
      caption('Мониторинг и алерты'),
      box(40, 60, 130, 56, 'Workers'),
      box(200, 60, 130, 56, 'Proxies'),
      box(360, 60, 130, 56, 'Monitor'),
      box(520, 60, 150, 56, 'Alerts / TG', { stroke: '#e85d5d' }),
      arrow(170, 88, 200, 88),
      arrow(330, 88, 360, 88),
      arrow(490, 88, 520, 88),
    );
    return root;
  },
};

export function renderIllustration(id) {
  const fn = ILLUSTRATIONS[id];
  if (typeof fn !== 'function') return null;
  const wrap = document.createElement('div');
  wrap.className = 'docs-illust';
  wrap.append(fn());
  return wrap;
}
