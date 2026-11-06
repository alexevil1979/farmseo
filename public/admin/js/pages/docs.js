import { el, escapeHtml } from '../utils.js';
import { navigate } from '../router.js';
import { DOC_CATEGORIES, DOC_GUIDES, PANEL_LABELS, searchGuides, getGuide } from '../docs/content.js';
import { renderIllustration } from '../docs/illustrations.js';

function highlight(text, query) {
  const raw = String(text ?? '');
  const q = String(query || '').trim();
  if (!q) return escapeHtml(raw);
  const tokens = q.split(/\s+/).filter(Boolean).map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  if (!tokens.length) return escapeHtml(raw);
  const re = new RegExp(`(${tokens.join('|')})`, 'ig');
  return escapeHtml(raw).replace(re, '<mark>$1</mark>');
}

function renderClicks(clicks, query) {
  if (!clicks?.length) return null;
  const list = el('ol', { class: 'docs-clicks' });
  clicks.forEach((c) => {
    list.append(el('li', { html: highlight(c, query) }));
  });
  return el('div', { class: 'docs-clicks-wrap' }, [
    el('div', { class: 'docs-clicks-label', text: 'Куда нажимать' }),
    list,
  ]);
}

function renderArticle(guide, query) {
  const article = el('article', { class: 'docs-article', id: `doc-${guide.id}` });
  const panelName = guide.panel ? (PANEL_LABELS[guide.panel] || guide.panel) : null;

  const head = el('header', { class: 'docs-article-head' }, [
    el('div', { class: 'docs-breadcrumbs' }, [
      el('button', {
        type: 'button',
        class: 'linkish',
        text: 'Документация',
        onClick: () => navigate('docs'),
      }),
      el('span', { text: ' / ' }),
      el('span', { text: guide.title }),
    ]),
    el('h2', { html: highlight(guide.title, query) }),
    el('p', { class: 'docs-summary', html: highlight(guide.summary, query) }),
  ]);

  if (guide.plain) {
    head.append(
      el('div', {
        class: 'docs-plain',
        html: `<strong>Простыми словами.</strong> ${highlight(guide.plain, query)}`,
      }),
    );
  }

  head.append(
    el('div', { class: 'docs-tags' }, (guide.tags || []).map((t) => el('span', { class: 'docs-tag', text: t }))),
  );

  if (guide.panel) {
    head.append(
      el('button', {
        type: 'button',
        class: 'btn btn-sm btn-primary docs-open-panel',
        text: `Открыть раздел «${panelName}»`,
        onClick: () => navigate(guide.panel),
      }),
    );
  }

  article.append(head);

  if (guide.illustration) {
    const ill = renderIllustration(guide.illustration);
    if (ill) article.append(ill);
  }

  const steps = el('ol', { class: 'docs-steps' });
  (guide.steps || []).forEach((step, i) => {
    const bodyKids = [
      el('h3', { html: highlight(step.title, query) }),
      renderClicks(step.clicks, query),
      el('p', { html: highlight(step.body, query) }),
    ];
    if (step.tip) {
      bodyKids.push(el('div', {
        class: 'docs-tip',
        html: `<strong>Подсказка.</strong> ${highlight(step.tip, query)}`,
      }));
    }
    if (step.warn) {
      bodyKids.push(el('div', {
        class: 'docs-warn',
        html: `<strong>Важно.</strong> ${highlight(step.warn, query)}`,
      }));
    }
    steps.append(el('li', { class: 'docs-step' }, [
      el('div', { class: 'docs-step-num', text: String(i + 1) }),
      el('div', { class: 'docs-step-body' }, bodyKids),
    ]));
  });
  article.append(steps);

  if (guide.checklist?.length) {
    article.append(
      el('div', { class: 'docs-checklist panel' }, [
        el('h3', { text: 'Галочка — сделал' }),
        el('ul', {}, guide.checklist.map((c) => el('li', { html: `☐ ${highlight(c, query)}` }))),
      ]),
    );
  }

  const related = DOC_GUIDES.filter((g) => g.id !== guide.id && (
    g.category === guide.category || (guide.id === 'quickstart' && g.id === 'troubleshooting')
  )).slice(0, 4);
  if (related.length) {
    article.append(
      el('div', { class: 'docs-related' }, [
        el('h3', { text: 'Дальше почитать' }),
        el('div', { class: 'docs-related-list' }, related.map((g) => el('button', {
          type: 'button',
          class: 'docs-card mini',
          onClick: () => navigate(`docs/${g.id}`),
          html: `<strong>${escapeHtml(g.title)}</strong><span>${escapeHtml(g.summary)}</span>`,
        }))),
      ]),
    );
  }

  return article;
}

function renderCard(guide, query) {
  return el('button', {
    type: 'button',
    class: 'docs-card',
    onClick: () => navigate(`docs/${guide.id}`),
  }, [
    el('div', { class: 'docs-card-top' }, [
      el('span', {
        class: 'docs-cat-pill',
        text: DOC_CATEGORIES.find((c) => c.id === guide.category)?.label || guide.category,
      }),
      guide.panel
        ? el('span', { class: 'muted', text: PANEL_LABELS[guide.panel] || guide.panel })
        : null,
    ]),
    el('strong', { html: highlight(guide.title, query) }),
    el('p', { html: highlight(guide.plain || guide.summary, query) }),
    el('div', { class: 'docs-tags' }, (guide.tags || []).slice(0, 5).map((t) => el('span', { class: 'docs-tag', text: t }))),
  ]);
}

export function renderDocs(root, state = {}) {
  root.innerHTML = '';
  const initialId = state.id || null;
  let query = String(state.params?.q || '');
  let activeCat = 'all';

  const shell = el('div', { class: 'docs-shell' });
  const sidebar = el('aside', { class: 'docs-side' });
  const main = el('div', { class: 'docs-main' });
  const searchWrap = el('div', { class: 'docs-search' });
  const listHost = el('div', { class: 'docs-list' });
  const articleHost = el('div', { class: 'docs-article-host' });

  const searchInput = el('input', {
    type: 'search',
    class: 'input docs-search-input',
    placeholder: 'Напиши как есть: pending, прокси, аккаунт, не работает…',
    value: query,
    autocomplete: 'off',
  });
  const searchMeta = el('div', { class: 'docs-search-meta muted', text: '' });
  searchWrap.append(
    el('label', { class: 'docs-search-label', text: 'Поиск — обычными словами' }),
    searchInput,
    searchMeta,
  );

  const catBar = el('div', { class: 'docs-cats' });
  const toc = el('nav', { class: 'docs-toc' });

  function paintCats() {
    catBar.innerHTML = '';
    catBar.append(el('button', {
      type: 'button',
      class: `docs-cat-btn${activeCat === 'all' ? ' active' : ''}`,
      text: 'Все',
      onClick: () => { activeCat = 'all'; paint(); },
    }));
    DOC_CATEGORIES.forEach((c) => {
      catBar.append(el('button', {
        type: 'button',
        class: `docs-cat-btn${activeCat === c.id ? ' active' : ''}`,
        text: c.label,
        onClick: () => { activeCat = c.id; paint(); },
      }));
    });
  }

  function paintToc(guides) {
    toc.innerHTML = '';
    toc.append(el('div', { class: 'docs-toc-title', text: 'Содержание' }));
    DOC_CATEGORIES.forEach((cat) => {
      const items = guides.filter((g) => g.category === cat.id);
      if (!items.length) return;
      const block = el('div', { class: 'docs-toc-group' });
      block.append(el('div', { class: 'docs-toc-group-title', text: cat.label }));
      items.forEach((g) => {
        block.append(el('button', {
          type: 'button',
          class: `docs-toc-link${initialId === g.id ? ' active' : ''}`,
          text: g.title,
          onClick: () => navigate(`docs/${g.id}`),
        }));
      });
      toc.append(block);
    });
  }

  sidebar.append(
    el('div', { class: 'docs-side-head' }, [
      el('h2', { text: 'Как пользоваться' }),
      el('p', { class: 'muted', text: 'Жми по порядку. Сначала «Первый запуск».' }),
    ]),
    toc,
  );

  main.append(searchWrap, catBar, listHost, articleHost);
  shell.append(sidebar, main);
  root.append(shell);

  function paint() {
    paintCats();
    const found = searchGuides(query).filter((g) => activeCat === 'all' || g.category === activeCat);
    paintToc(found);
    searchMeta.textContent = found.length
      ? `Показано: ${found.length}`
      : 'Ничего нет — попробуй: прокси, задача, pending, live';

    const guide = initialId ? getGuide(initialId) : null;
    if (guide) {
      listHost.hidden = true;
      articleHost.hidden = false;
      articleHost.innerHTML = '';
      articleHost.append(renderArticle(guide, query));
      return;
    }

    articleHost.hidden = true;
    listHost.hidden = false;
    listHost.innerHTML = '';

    if (!found.length) {
      listHost.append(el('div', {
        class: 'panel docs-empty',
        text: 'Пусто. Сотри поиск или открой «Первый запуск» из меню слева.',
      }));
      return;
    }

    if (!query && activeCat === 'all') {
      const qs = getGuide('quickstart');
      const what = getGuide('what-is-this');
      listHost.append(
        el('div', { class: 'docs-hero panel' }, [
          el('div', {}, [
            el('div', { class: 'docs-hero-kicker', text: 'Начни отсюда' }),
            el('h2', { text: 'Не знаешь с чего начать?' }),
            el('p', {
              text: 'Сначала прочитай «Что это за программа», потом сделай «Первый запуск» до статуса done. Кампании — потом.',
            }),
            el('div', { class: 'docs-hero-actions' }, [
              el('button', {
                type: 'button',
                class: 'btn btn-primary',
                text: '1. Что это такое',
                onClick: () => navigate('docs/what-is-this'),
              }),
              el('button', {
                type: 'button',
                class: 'btn',
                text: '2. Первый запуск',
                onClick: () => navigate('docs/quickstart'),
              }),
              el('button', {
                type: 'button',
                class: 'btn',
                text: 'Что-то сломалось',
                onClick: () => navigate('docs/troubleshooting'),
              }),
            ]),
          ]),
          renderIllustration('firstRun') || el('div'),
        ]),
      );
      if (what) {
        // keep cards below; quickstart also in grid except we skip both featured? keep all in grid for searchability
      }
    }

    const grid = el('div', { class: 'docs-grid' });
    found.forEach((g) => grid.append(renderCard(g, query)));
    listHost.append(el('h3', { class: 'docs-all-title', text: 'Все инструкции' }), grid);
  }

  let timer = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      query = searchInput.value;
      paint();
    }, 140);
  });
  searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      searchInput.value = '';
      query = '';
      paint();
    }
  });

  paint();
  if (!initialId) setTimeout(() => searchInput.focus(), 40);
  return () => clearTimeout(timer);
}
