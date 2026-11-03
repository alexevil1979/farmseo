import { el, escapeHtml } from '../utils.js';
import { navigate } from '../router.js';
import { DOC_CATEGORIES, DOC_GUIDES, searchGuides, getGuide } from '../docs/content.js';
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

function renderArticle(guide, query) {
  const article = el('article', { class: 'docs-article', id: `doc-${guide.id}` });

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
    el('div', { class: 'docs-tags' }, (guide.tags || []).map((t) => el('span', { class: 'docs-tag', text: t }))),
  ]);

  if (guide.panel) {
    head.append(
      el('button', {
        type: 'button',
        class: 'btn btn-sm docs-open-panel',
        text: `Открыть раздел «${guide.panel}»`,
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
    const li = el('li', { class: 'docs-step' }, [
      el('div', { class: 'docs-step-num', text: String(i + 1) }),
      el('div', { class: 'docs-step-body' }, [
        el('h3', { html: highlight(step.title, query) }),
        el('p', { html: highlight(step.body, query) }),
        step.tip
          ? el('div', { class: 'docs-tip', html: `<strong>Совет.</strong> ${highlight(step.tip, query)}` })
          : null,
      ]),
    ]);
    steps.append(li);
  });
  article.append(steps);

  if (guide.checklist?.length) {
    article.append(
      el('div', { class: 'docs-checklist panel' }, [
        el('h3', { text: 'Чеклист готовности' }),
        el('ul', {}, guide.checklist.map((c) => el('li', { html: highlight(c, query) }))),
      ]),
    );
  }

  const related = DOC_GUIDES.filter((g) => g.id !== guide.id && g.category === guide.category).slice(0, 4);
  if (related.length) {
    article.append(
      el('div', { class: 'docs-related' }, [
        el('h3', { text: 'Смотрите также' }),
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
      guide.panel ? el('span', { class: 'muted', text: `#${guide.panel}` }) : null,
    ]),
    el('strong', { html: highlight(guide.title, query) }),
    el('p', { html: highlight(guide.summary, query) }),
    el('div', { class: 'docs-tags' }, (guide.tags || []).slice(0, 4).map((t) => el('span', { class: 'docs-tag', text: t }))),
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
    placeholder: 'Поиск: прокси, pending, fingerprint, live-view…',
    value: query,
    autocomplete: 'off',
  });
  const searchMeta = el('div', { class: 'docs-search-meta muted', text: '' });
  searchWrap.append(
    el('label', { class: 'docs-search-label', text: 'Поиск по документации' }),
    searchInput,
    searchMeta,
  );

  const catBar = el('div', { class: 'docs-cats' });
  function paintCats() {
    catBar.innerHTML = '';
    const allBtn = el('button', {
      type: 'button',
      class: `docs-cat-btn${activeCat === 'all' ? ' active' : ''}`,
      text: 'Все',
      onClick: () => { activeCat = 'all'; paint(); },
    });
    catBar.append(allBtn);
    DOC_CATEGORIES.forEach((c) => {
      catBar.append(el('button', {
        type: 'button',
        class: `docs-cat-btn${activeCat === c.id ? ' active' : ''}`,
        text: c.label,
        onClick: () => { activeCat = c.id; paint(); },
      }));
    });
  }

  const toc = el('nav', { class: 'docs-toc' });
  function paintToc(guides) {
    toc.innerHTML = '';
    toc.append(el('div', { class: 'docs-toc-title', text: 'Оглавление' }));
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
          onClick: () => navigate(`docs/${g.id}${query ? `?q=${encodeURIComponent(query)}` : ''}`),
        }));
      });
      toc.append(block);
    });
  }

  sidebar.append(
    el('div', { class: 'docs-side-head' }, [
      el('h2', { text: 'Документация' }),
      el('p', { class: 'muted', text: 'Пошаговые инструкции по всем разделам панели' }),
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
      ? `Найдено разделов: ${found.length} из ${DOC_GUIDES.length}`
      : 'Ничего не найдено — попробуйте другие слова';

    const guide = initialId ? getGuide(initialId) : null;
    if (guide) {
      listHost.hidden = true;
      articleHost.hidden = false;
      articleHost.innerHTML = '';
      // If search filters out current guide, still show it but note match
      articleHost.append(renderArticle(guide, query));
      articleHost.querySelector('.docs-article')?.scrollIntoView({ block: 'start' });
      return;
    }

    articleHost.hidden = true;
    listHost.hidden = false;
    listHost.innerHTML = '';
    if (!found.length) {
      listHost.append(el('div', { class: 'panel docs-empty', text: 'Нет совпадений. Очистите поиск или смените категорию.' }));
      return;
    }

    // Featured quickstart on empty query
    if (!query && activeCat === 'all') {
      const qs = getGuide('quickstart');
      if (qs) {
        listHost.append(
          el('div', { class: 'docs-hero panel' }, [
            el('div', {}, [
              el('div', { class: 'docs-hero-kicker', text: 'Начните здесь' }),
              el('h2', { text: qs.title }),
              el('p', { text: qs.summary }),
              el('button', {
                type: 'button',
                class: 'btn btn-primary',
                text: 'Открыть быстрый старт',
                onClick: () => navigate('docs/quickstart'),
              }),
            ]),
            renderIllustration('firstRun') || el('div'),
          ]),
        );
      }
    }

    const grid = el('div', { class: 'docs-grid' });
    found.forEach((g) => {
      if (!query && activeCat === 'all' && g.id === 'quickstart') return;
      grid.append(renderCard(g, query));
    });
    listHost.append(grid);
  }

  let timer = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      query = searchInput.value;
      // stay on index while typing; keep article if deep-linked
      paint();
    }, 160);
  });

  searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      searchInput.value = '';
      query = '';
      paint();
    }
  });

  paint();
  // Focus search on index
  if (!initialId) setTimeout(() => searchInput.focus(), 50);

  return () => clearTimeout(timer);
}
