/*--- FILL ME IN: PUNKT WEJŚCIA APLIKACJI ---*/
// Osobny od resources/js/app.js (strona). Alpine.js obsługuje drobne interakcje interfejsu.

import Alpine from 'alpinejs';
import { clientField, clientHistory, clientLightbox, clientMedia, clientRepeater, clientSectionNote, clientWysiwyg, saveStatus } from './fillmein/client-editor.js';
import { exportScope } from './fillmein/export-scope.js';
import { figmaEmbed } from './fillmein/figma-embed.js';
import { figmaQueue } from './fillmein/figma-queue.js';
import { projectSelection } from './fillmein/project-selection.js';
import { reviewComposer } from './fillmein/review-composer.js';
import sortable from './fillmein/sortable.js';

window.Alpine = Alpine;

Alpine.data('sortable', sortable);
Alpine.data('projectSelection', projectSelection);
Alpine.data('figmaEmbed', figmaEmbed);
Alpine.data('figmaQueue', figmaQueue);
Alpine.data('exportScope', exportScope);
Alpine.data('reviewComposer', reviewComposer);
Alpine.data('clientField', clientField);
Alpine.data('clientRepeater', clientRepeater);
Alpine.data('clientWysiwyg', clientWysiwyg);
Alpine.data('clientHistory', clientHistory);
Alpine.data('clientLightbox', clientLightbox);
Alpine.data('clientMedia', clientMedia);
Alpine.data('clientSectionNote', clientSectionNote);
Alpine.store('saveStatus', saveStatus);

/**
 * Formularze z atrybutem data-loading-form blokują przycisk wysyłki i pokazują stan ładowania,
 * żeby uniknąć podwójnego wysłania.
 */
document.addEventListener('submit', (event) => {
  const form = event.target;

  if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-loading-form')) {
    return;
  }

  form.querySelectorAll('button[type="submit"]').forEach((button) => {
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.dataset.loading = 'true';
  });
});

/**
 * Formularze z data-keep-scroll (np. „Pomiń” w imporcie z Figmy) po przeładowaniu wracają w to samo miejsce
 * strony zamiast na górę. Pozycja żyje tylko do najbliższego wczytania tej samej ścieżki.
 */
const SCROLL_KEY = 'fmi:keep-scroll';

document.addEventListener('submit', (event) => {
  // Formularze z ekranu importu (np. „Usuń” w oknie potwierdzenia) mają w adresie return=import.
  if (event.target instanceof HTMLFormElement && (event.target.hasAttribute('data-keep-scroll') || event.target.action.includes('return=import'))) {
    try {
      sessionStorage.setItem(SCROLL_KEY, JSON.stringify({ path: location.pathname, y: window.scrollY }));
    } catch {
      // Bez sessionStorage strona po prostu wczyta się od góry.
    }
  }
});

try {
  const saved = JSON.parse(sessionStorage.getItem(SCROLL_KEY) ?? 'null');
  sessionStorage.removeItem(SCROLL_KEY);

  if (saved && saved.path === location.pathname) {
    window.addEventListener('load', () => window.scrollTo({ top: saved.y, behavior: 'instant' }));
  }
} catch {
  // j.w.
}

/**
 * Rozwinięte/zwinięte <details data-remember-open="klucz"> (sekcje ekranu importu) pamiętamy w karcie przeglądarki,
 * więc zapis pola czy „Pomiń” nie zwija ich po przeładowaniu. Sekcja z celem kotwicy (#pole-…) zawsze się rozwija.
 */
const OPEN_KEY = `fmi:open:${location.pathname}`;

document.addEventListener('DOMContentLoaded', () => {
  let state = {};

  try {
    state = JSON.parse(sessionStorage.getItem(OPEN_KEY) ?? '{}') ?? {};
  } catch {
    state = {};
  }

  document.querySelectorAll('details[data-remember-open]').forEach((details) => {
    const key = details.dataset.rememberOpen;

    if (key in state) {
      details.open = state[key];
    }

    details.addEventListener('toggle', () => {
      state[key] = details.open;

      try {
        sessionStorage.setItem(OPEN_KEY, JSON.stringify(state));
      } catch {
        // bez sessionStorage stan nie przetrwa przeładowania
      }
    });
  });

  const target = location.hash ? document.getElementById(location.hash.slice(1)) : null;
  target?.closest('details[data-remember-open]')?.setAttribute('open', '');
  target?.scrollIntoView({ block: 'center' });
});

Alpine.start();
