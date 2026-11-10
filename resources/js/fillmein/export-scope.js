/**
 * Zakładka Eksport: zakres (cały projekt / wybrane podstrony) i podsumowanie liczone z liczb każdej podstrony.
 * Liczby przychodzą z serwera (ContentExportBuilder::summaries), więc podgląd zgadza się z tym, co trafi do paczki.
 */
const KEYS = ['sections', 'fields', 'filled', 'empty', 'cleared', 'media', 'media_links', 'missing'];

export function exportScope({ summaries = {}, selected = [] } = {}) {
  return {
    scope: 'project',
    selected: [...selected],

    get chosen() {
      return this.scope === 'project' ? Object.keys(summaries) : this.selected;
    },

    get total() {
      const sum = Object.fromEntries(KEYS.map((key) => [key, 0]));

      for (const id of this.chosen) {
        for (const key of KEYS) {
          sum[key] += summaries[id]?.[key] ?? 0;
        }
      }

      return { pages: this.chosen.length, ...sum };
    },

    get canSubmit() {
      return this.chosen.length > 0;
    },

    selectAll() {
      this.selected = Object.keys(summaries);
    },

    selectNone() {
      this.selected = [];
    },
  };
}
