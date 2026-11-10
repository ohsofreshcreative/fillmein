/**
 * Kolejka Figmy na liście podstron projektu.
 *
 * - „Dodaj podstronę” zapisuje podstronę bez przeładowania i bez czekania na Figmę; frame pobiera się w tle,
 *   a formularz od razu przyjmuje kolejną podstronę.
 * - Podstrony z linkiem, których frame jeszcze nie pobrano (np. po przeładowaniu), trafiają do kolejki same.
 * - Masowa „Analiza i import pól” przetwarza zaznaczone podstrony po kolei (FigmaQueueController).
 *
 * Zadania idą jedno po drugim, żeby nie przekroczyć limitów API Figmy. Każde żądanie jest krótkie: najpierw
 * struktura warstw, potem podglądy w porcjach (status `continue`) — długie żądania hosting przerywa błędem 500.
 */
const RETRY_DELAY = 4000;
const MAX_RETRIES = 40;
const MAX_BATCHES = 20;

export function figmaQueue({ pending = [], urls = {}, storeUrl = '' } = {}) {
  return {
    urls: { ...urls },
    jobs: [],
    status: {},
    selected: [],
    added: [],
    running: false,
    adding: false,
    formErrors: {},
    formMessage: '',

    init() {
      pending.forEach((id) => this.enqueue(id, 'fetch'));

      window.addEventListener('beforeunload', (event) => {
        if (this.jobs.length > 0) {
          event.preventDefault();
          event.returnValue = '';
        }
      });
    },

    get busy() {
      return this.running || this.jobs.length > 0;
    },

    get finished() {
      return Object.values(this.status).filter((item) => ['done', 'warning', 'error'].includes(item.state)).length;
    },

    get total() {
      return Object.keys(this.status).length;
    },

    label(id) {
      const item = this.status[id];

      if (!item) {
        return '';
      }

      const kind = item.kind === 'import' ? 'Analiza i import' : 'Pobieranie z Figmy';

      return {
        queued: `${kind}: w kolejce`,
        running: item.message || `${kind}…`,
        done: item.message,
        warning: item.message,
        error: item.message,
      }[item.state] ?? '';
    },

    variant(id) {
      return { queued: 'muted', running: 'neutral', done: 'success', warning: 'warning', error: 'danger' }[this.status[id]?.state] ?? 'muted';
    },

    enqueue(id, kind) {
      const url = this.urls[id]?.[kind];

      if (!url || this.jobs.some((job) => job.id === id && job.kind === kind)) {
        return;
      }

      this.jobs.push({ id, kind, url, attempts: 0 });
      this.status[id] = { state: 'queued', kind, message: '' };
      this.run();
    },

    importSelected() {
      [...this.selected].forEach((id) => this.enqueue(Number(id), 'import'));
      this.selected = [];
    },

    selectAll() {
      this.selected = Object.keys(this.urls).filter((id) => this.urls[id].import).map(String);
    },

    async run() {
      if (this.running) {
        return;
      }

      this.running = true;

      while (this.jobs.length > 0) {
        const job = this.jobs.shift();
        this.status[job.id] = { state: 'running', kind: job.kind, message: '' };

        try {
          const result = await this.post(job.url);

          if (['running', 'retry'].includes(result.status) && job.attempts < (result.status === 'retry' ? 5 : MAX_RETRIES)) {
            // Podstrona jest właśnie przetwarzana (np. zadanie sprzed przeładowania) albo Figma chwilowo ogranicza
            // zapytania — spróbujemy ponownie za chwilę.
            this.status[job.id] = { state: 'queued', kind: job.kind, message: '' };
            await new Promise((resolve) => setTimeout(resolve, result.status === 'retry' ? RETRY_DELAY * 4 : RETRY_DELAY));
            this.jobs.push({ ...job, attempts: job.attempts + 1 });

            continue;
          }

          const next = this.urls[job.id]?.previews;
          const batch = (job.batch ?? 0) + 1;

          if (result.status === 'continue' && next && batch <= MAX_BATCHES) {
            // Kolejna porcja tej samej podstrony idzie od razu, przed innymi podstronami.
            this.status[job.id] = { state: 'running', kind: job.kind, message: result.message };
            this.jobs.unshift({ id: job.id, kind: job.kind, url: next, attempts: 0, batch });

            continue;
          }

          const state = { running: 'warning', continue: 'warning', retry: 'error' }[result.status] ?? result.status;
          this.status[job.id] = { state, kind: job.kind, message: result.message };
        } catch (error) {
          this.status[job.id] = { state: 'error', kind: job.kind, message: error.message };
        }
      }

      this.running = false;
    },

    async post(url, body = null) {
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body,
      });

      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        const error = new Error(response.status === 419
          ? 'Sesja wygasła — odśwież stronę.'
          : response.status >= 500
            ? `Błąd serwera (${response.status}) — spróbuj ponownie lub użyj „Pobierz z Figmy” w edycji podstrony.`
            : data.message || 'Nie udało się połączyć z serwerem. Spróbuj ponownie.');
        error.errors = data.errors ?? {};
        error.code = response.status;
        throw error;
      }

      return data;
    },

    async addPage(form) {
      if (this.adding) {
        return;
      }

      this.adding = true;
      this.formErrors = {};
      this.formMessage = '';

      try {
        const page = await this.post(storeUrl, new FormData(form));

        this.added.push(page);
        this.formMessage = page.message;
        form.reset();
        form.querySelector('[name="name"]')?.focus();

        if (page.queued) {
          this.urls[page.id] = { fetch: page.fetch_url, previews: page.previews_url };
          this.enqueue(page.id, 'fetch');
        }
      } catch (error) {
        this.formErrors = Object.fromEntries(Object.entries(error.errors ?? {}).map(([key, messages]) => [key, [].concat(messages)[0]]));

        if (Object.keys(this.formErrors).length === 0) {
          this.formErrors = { form: error.message };
        }
      } finally {
        this.adding = false;
      }
    },
  };
}
