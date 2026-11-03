import { renderResourcePage } from './resource.js';

export function renderCampaigns(root) {
  return renderResourcePage(root, {
    resource: 'campaigns',
    title: 'Кампания',
    filters: [{ key: 'status', label: 'Статус', options: ['draft', 'active', 'paused', 'done'] }],
    fields: [
      { key: 'name', label: 'Name', required: true },
      { key: 'status', label: 'Status', type: 'select', options: ['draft', 'active', 'paused', 'done'], default: 'draft' },
      { key: 'scenario_id', label: 'scenario_id', type: 'number' },
      { key: 'budget_tasks', label: 'budget_tasks', type: 'number' },
      { key: 'starts_at', label: 'starts_at' },
      { key: 'ends_at', label: 'ends_at' },
      { key: 'meta', label: 'meta JSON', type: 'json' },
    ],
  });
}

export function renderKeywords(root) {
  return renderResourcePage(root, {
    resource: 'keywords',
    title: 'Ключ',
    fields: [
      { key: 'phrase', label: 'Phrase', required: true },
      { key: 'campaign_id', label: 'campaign_id', type: 'number' },
      { key: 'lang', label: 'lang' },
      { key: 'geo', label: 'geo' },
      { key: 'priority', label: 'priority', type: 'number', default: '0' },
      { key: 'is_active', label: 'is_active', type: 'select', options: ['1', '0'], default: '1', asInt: true },
      { key: 'meta', label: 'meta JSON', type: 'json' },
    ],
  });
}

export function renderTargets(root) {
  return renderResourcePage(root, {
    resource: 'targets',
    title: 'Цель',
    fields: [
      { key: 'url', label: 'URL', required: true },
      { key: 'domain', label: 'domain' },
      { key: 'title', label: 'title' },
      { key: 'campaign_id', label: 'campaign_id', type: 'number' },
      { key: 'is_active', label: 'is_active', type: 'select', options: ['1', '0'], default: '1', asInt: true },
      { key: 'meta', label: 'meta JSON', type: 'json' },
    ],
  });
}
