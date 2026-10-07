/* Données déjà échappées par WordPress ; aucun HTML externe n'est injecté. */
(() => {
  'use strict';
  function init(root) {
    if (root.dataset.ready) return;
    root.dataset.ready = '1';
    const config = JSON.parse(root.querySelector('.rpb-config').textContent);
    const cards = Array.from(root.querySelectorAll('.rpb-card'));
    const results = root.querySelector('.rpb-results');
    const filters = Array.from(root.querySelectorAll('[data-filter]'));
    const fold = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    let view = 'theme';
    const dateLabel = day => new Intl.DateTimeFormat('fr-FR', {dateStyle: 'full', timeZone: 'Europe/Paris'}).format(new Date(day + 'T12:00:00Z'));
    const selected = name => root.querySelector(`[data-filter="${name}"]`).value;
    const section = (label, tag = 'h3', cls = 'rpb-section') => {
      const node = document.createElement('section'); node.className = cls;
      const heading = document.createElement(tag); heading.textContent = label; node.appendChild(heading);
      const body = document.createElement('div'); body.className = 'rpb-cards'; node.appendChild(body);
      return [node, body];
    };
    function render() {
      const query = fold(selected('search').trim());
      const visible = cards.filter(card => {
        return (!selected('theme') || card.dataset.theme === selected('theme')) &&
          (!selected('source') || card.dataset.source === selected('source')) &&
          (!selected('place') || JSON.parse(card.dataset.places).includes(selected('place'))) &&
          (!query || fold(card.textContent).includes(query));
      }).sort((a, b) => Date.parse(b.dataset.published) - Date.parse(a.dataset.published));
      results.replaceChildren();
      const keys = view === 'theme' ? Object.keys(config.themes) : [...new Set(visible.map(c => c.dataset.day))].sort().reverse();
      const group = root.querySelector('[data-group]').checked && view === 'theme';
      for (const key of keys) {
        const subset = visible.filter(c => (view === 'theme' ? c.dataset.theme : c.dataset.day) === key);
        if (!subset.length) continue;
        const [node, body] = section(view === 'theme' ? config.themes[key] : dateLabel(key));
        const topics = new Map();
        for (const card of subset) {
          const topic = card.dataset.topic;
          const siblings = topic ? subset.filter(c => c.dataset.topic === topic) : [];
          if (group && topic && siblings.length > 1) {
            if (topics.has(topic)) continue;
            const [block, inner] = section(config.topics[topic] || 'Articles sur le même sujet', 'h4', 'rpb-topic');
            for (const sibling of siblings) inner.appendChild(sibling);
            body.appendChild(block); topics.set(topic, block);
          } else body.appendChild(card);
        }
        results.appendChild(node);
      }
      root.querySelector('.rpb-count').textContent = `${visible.length} article${visible.length > 1 ? 's' : ''}`;
      root.querySelector('.rpb-empty').hidden = visible.length !== 0;
      root.querySelector('.rpb-group-toggle').hidden = view !== 'theme';
    }
    filters.forEach(el => el.addEventListener(el.type === 'search' ? 'input' : 'change', render));
    root.querySelector('[data-group]').addEventListener('change', render);
    root.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => {
      view = button.dataset.view;
      root.querySelectorAll('[data-view]').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
      render();
    }));
    render();
  }
  const start = () => document.querySelectorAll('.rpb').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
