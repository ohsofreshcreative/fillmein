/*--- FILL ME IN: EDYTOR KLIENTA ---*/
// Adres zapisu powstaje tutaj: w edytorze klienta z `location.pathname` (widoki celowo nie zawierają tokenu),
// w panelu z `data-editor-base`. Wpisany tekst leży w szkicu localStorage, dopóki serwer nie potwierdzi zapisu.

import sortable from './sortable.js';

const editorRoot = () => document.querySelector('[data-editor-scope]');
const editorUrl = (path) => `${(editorRoot()?.dataset.editorBase || window.location.pathname).replace(/\/+$/, '')}/${path}`;
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const RETRY_MS = 15000;
const DEBOUNCE_MS = 800;

const post = async (path, body) => {
  const response = await fetch(editorUrl(path), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    body,
  });
  const json = await response.json().catch(() => ({}));

  return { ok: response.ok, status: response.status, json };
};

const attempt = (path, body) => post(path, body).catch(() => ({ ok: false, status: 0, json: {} }));

const failure = ({ status, json }) => {
  if (status === 422 && json.errors) {
    return Object.values(json.errors).flat()[0];
  }

  if (status === 409) {
    return json.message;
  }

  return status === 419
    ? 'Sesja wygasła. Odśwież stronę, a wpisana treść wróci do pola.'
    : 'Nie udało się zapisać. Spróbujemy ponownie.';
};

const applyProgress = (progress) => {
  Object.entries(progress ?? {}).forEach(([key, stats]) => {
    document.querySelectorAll(`[data-progress-key="${key}"]`).forEach((element) => {
      const fill = element.querySelector('[data-progress-fill]');

      element.querySelector('[role="progressbar"]')?.setAttribute('aria-valuenow', stats.percent);
      fill?.style.setProperty('width', `${stats.percent}%`);
      fill?.classList.toggle('bg-ink-300', stats.percent === 0);
      fill?.classList.toggle('bg-fresh-400', stats.percent > 0 && stats.percent < 100);
      fill?.classList.toggle('bg-success-600', stats.percent === 100);

      const hint = element.querySelector('[data-progress-hint]');

      if (hint) {
        hint.textContent = stats.hint;
      }
    });
  });
};

// Stan globalny: najważniejszy stan pól na stronie. Stany inne niż `saved` oznaczają niezapisane zmiany.
export const saveStatus = {
  fields: {},
  savedAt: '',

  init() {
    window.addEventListener('beforeunload', (event) => {
      if (Object.values(this.fields).some((state) => state !== 'saved')) {
        event.preventDefault();
      }
    });
  },

  report(id, state) {
    this.fields = { ...this.fields, [id]: state };

    if (state === 'saved') {
      this.savedAt = new Date().toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
    }
  },

  get state() {
    const states = Object.values(this.fields);

    return ['conflict', 'error', 'offline', 'saving', 'dirty'].find((state) => states.includes(state)) ?? (this.savedAt ? 'saved' : '');
  },

  get message() {
    return {
      conflict: 'Konflikt: wybierz wersję przy zaznaczonym polu.',
      error: 'Błąd zapisu. Sprawdź zaznaczone pole.',
      offline: 'Brak połączenia. Zmiany czekają na zapis.',
      saving: 'Zapisywanie…',
      dirty: 'Zapisywanie…',
      saved: `Zapisano o ${this.savedAt}`,
    }[this.state] ?? '';
  },
};

// Klucz szkicu nie zawiera adresu strony, bo w edytorze klienta adres to token.
const draftKey = (id) => `fmi-draft:${editorRoot()?.dataset.editorScope ?? ''}:${id}`;

const drafts = {
  read(id) {
    try {
      return JSON.parse(window.localStorage.getItem(draftKey(id)));
    } catch {
      return null;
    }
  },

  write(id, data, version) {
    try {
      window.localStorage.setItem(draftKey(id), JSON.stringify({ data, version }));
    } catch {
      // Pełny lub wyłączony localStorage: zostaje kolejka w pamięci strony.
    }
  },

  remove(id) {
    try {
      window.localStorage.removeItem(draftKey(id));
    } catch {
      // Jak wyżej.
    }
  },
};

export const clientField = ({ field, row = null, version = 0, filled = false }) => {
  const id = `${field}:${row ?? ''}`;
  let form = null;
  let lastSaved = '';
  let timer = null;
  let retry = null;
  let inFlight = false;
  let again = false;

  const snapshot = () => new URLSearchParams(new FormData(form)).toString();

  const fill = (params) => {
    params.forEach((value, name) => {
      const input = form.elements.namedItem(name);

      if (input) {
        input.value = value;
      }

      const editor = form.querySelector('[contenteditable]');

      if (editor && name === 'value') {
        editor.innerHTML = value;
      }
    });
  };

  return {
    version,
    saving: false,
    error: false,
    message: '',
    conflict: null,
    theirs: '',
    filled,

    init() {
      form = this.$el;
      lastSaved = snapshot();
      window.addEventListener('online', () => this.flush());

      const draft = drafts.read(id);

      if (!draft) {
        return;
      }

      if (draft.data === lastSaved) {
        drafts.remove(id);
        return;
      }

      // Baza z chwili wpisania: jeśli ktoś zmienił pole w międzyczasie, serwer zgłosi konflikt, a tekst zostanie.
      fill(new URLSearchParams(draft.data));
      this.version = draft.version;
      this.$store.saveStatus.report(id, 'dirty');
      this.$nextTick(() => this.flush());
    },

    refreshFilled() {
      this.filled = [...new FormData(form).values()].some((value) => String(value).replace(/<[^>]*>/g, '').trim() !== '');
    },

    changed() {
      drafts.write(id, snapshot(), this.version);
      this.$store.saveStatus.report(id, this.conflict ? 'conflict' : 'dirty');
      clearTimeout(timer);
      timer = setTimeout(() => this.flush(), DEBOUNCE_MS);
    },

    left(event) {
      if (!form.contains(event.relatedTarget)) {
        this.flush();
      }
    },

    async flush() {
      clearTimeout(timer);
      clearTimeout(retry);

      if (this.conflict) {
        return;
      }

      if (inFlight) {
        again = true;
        return;
      }

      const sent = snapshot();

      if (sent === lastSaved && !this.error) {
        return;
      }

      if (window.navigator.onLine === false) {
        this.waitForNetwork();
        return;
      }

      const body = new URLSearchParams(sent);

      body.set('base_version', this.version);

      if (row) {
        body.set('row', row);
      }

      inFlight = true;
      this.saving = true;
      this.message = 'Zapisywanie…';
      this.$store.saveStatus.report(id, 'saving');

      const result = await attempt(`fields/${field}`, body);

      inFlight = false;
      this.saving = false;
      this.settle(result, sent);

      if (again) {
        again = false;
        this.flush();
      }
    },

    settle(result, sent) {
      if (result.ok) {
        this.version = result.json.version;
        lastSaved = sent;
        this.error = false;
        this.theirs = '';
        this.message = result.json.message;
        this.refreshFilled();
        applyProgress(result.json.progress);

        if (snapshot() === sent) {
          drafts.remove(id);
          this.$store.saveStatus.report(id, 'saved');
        }

        return;
      }

      if (result.status === 409) {
        this.conflict = result.json.current;
        this.conflict.mine = result.json.mine.display;
        this.error = true;
        this.message = result.json.message;
        this.$store.saveStatus.report(id, 'conflict');
        return;
      }

      if (result.status === 0) {
        this.waitForNetwork();
        return;
      }

      this.error = true;
      this.message = failure(result);
      this.$store.saveStatus.report(id, 'error');

      if ([403, 404].includes(result.status)) {
        this.message = 'To pole nie jest już dostępne. Odśwież stronę.';
        drafts.remove(id);
      }

      if (result.status === 429 || result.status >= 500) {
        retry = setTimeout(() => this.flush(), RETRY_MS);
      }
    },

    waitForNetwork() {
      drafts.write(id, snapshot(), this.version);
      this.error = false;
      this.message = 'Brak połączenia. Zapiszemy zmiany po powrocie sieci.';
      this.$store.saveStatus.report(id, 'offline');
      clearTimeout(retry);
      retry = setTimeout(() => this.flush(), RETRY_MS);
    },

    keepMine() {
      this.version = this.conflict.version;
      this.conflict = null;
      this.error = false;
      lastSaved = '';
      this.flush();
    },

    takeTheirs() {
      const { value } = this.conflict;
      const link = form.elements.namedItem('url') ? JSON.parse(value ?? '{}') : null;

      fill(new URLSearchParams(link ? { label: link.label ?? '', url: link.url ?? '' } : { value: value ?? '' }));
      this.version = this.conflict.version;
      this.conflict = null;
      this.error = false;
      lastSaved = snapshot();
      drafts.remove(id);
      this.refreshFilled();
      this.message = 'Przyjęto wersję z serwera.';
      this.$store.saveStatus.report(id, 'saved');
    },

    mergeManually() {
      this.version = this.conflict.version;
      this.theirs = this.conflict.display;
      this.conflict = null;
      this.error = false;
      this.message = 'Połącz treści w polu. Zapiszemy je automatycznie.';
      this.$store.saveStatus.report(id, 'dirty');
      this.$nextTick(() => form.querySelector('input, textarea, [contenteditable]')?.focus());
    },
  };
};

// Powiększony projekt sekcji. `<dialog>` daje blokadę fokusu, zamknięcie klawiszem Esc i powrót fokusu na przycisk.
export const clientLightbox = () => ({
  src: '',
  alt: '',

  show(src, alt) {
    this.src = src;
    this.alt = alt;
    this.$refs.lightbox.showModal();
  },

  close() {
    this.$refs.lightbox.close();
  },
});

export const clientHistory = ({ field, row = null, version = 0 }) => ({
  version,
  busy: false,
  error: false,
  message: '',

  async restore(revision) {
    const body = new URLSearchParams({ base_version: this.version, ...(row ? { row } : {}) });

    this.busy = true;

    const result = await attempt(`fields/${field}/revisions/${revision}/restore`, body);

    this.busy = false;
    this.error = !result.ok;
    this.message = result.ok ? result.json.message : (result.status === 409 ? 'Treść zmieniła się w międzyczasie. Odśwież stronę.' : failure(result));

    if (result.ok) {
      window.location.reload();
    }
  },
});

export const clientRepeater = ({ field, row = null, version = 0, max = 0, count = 0 }) => {
  const state = { version };
  let root = null;
  const context = () => ({ base_version: state.version, ...(row ? { row } : {}) });
  const base = sortable({
    url: () => editorUrl(`fields/${field}/rows/reorder`),
    extra: context,
    onSaved: (json) => {
      state.version = json.version ?? state.version;
    },
  });

  return {
    ...base,
    count,
    max,
    busy: false,
    // Akordeon: otwarty jest jeden element listy naraz (uuid wiersza albo null).
    openRow: null,

    // W metodach wywołanych z przycisku `$el` to ten przycisk, więc korzeń komponentu zapamiętujemy tutaj.
    init() {
      root = this.$el;
      base.init.call(this);
      root.addEventListener('sortable:moved', () => this.renumber());

      const items = this.items();
      this.openRow = items.length === 1 ? items[0].dataset.sortableItem : null;
      this.summarize();

      // Nagłówki odświeżają się po wpisaniu tekstu i po zmianie stanu „Uzupełnione” pól i zdjęć.
      root.addEventListener('input', () => this.summarize());
      new MutationObserver(() => this.summarize()).observe(root, { subtree: true, attributes: true, attributeFilter: ['data-filled'] });
    },

    items() {
      return [...root.querySelector('[data-sortable-list]').children];
    },

    toggle(uuid) {
      this.openRow = this.openRow === uuid ? null : uuid;
    },

    // Pola należące do danego wiersza, bez pól z list zagnieżdżonych (mają własne nagłówki).
    own(item, selector) {
      return [...item.querySelectorAll(selector)].filter((element) => element.closest('[data-row]') === item);
    },

    summarize() {
      this.items().forEach((item) => {
        const text = this.own(item, 'input[type="text"], input[type="url"], input:not([type]), textarea, [contenteditable]')
          .map((element) => (element.isContentEditable ? element.textContent : element.value) || '')
          .map((value) => value.replace(/\s+/g, ' ').trim())
          .find((value) => value !== '');
        const title = item.querySelector('[data-row-title]');

        if (title) {
          title.textContent = text ? ` — ${text.length > 60 ? `${text.slice(0, 60)}…` : text}` : '';
        }

        const markers = this.own(item, '[data-filled]');
        const progress = item.querySelector('[data-row-progress]');

        if (progress) {
          const done = markers.filter((marker) => marker.dataset.filled === 'true').length;
          // „uzupełnione” jest w widoku (ukryte na telefonie), tu tylko liczby.
          progress.querySelector('[data-row-done]').textContent = `${done}/${markers.length}`;
          progress.setAttribute('aria-label', `${done} z ${markers.length} uzupełnione`);
          progress.hidden = markers.length === 0;
        }
      });
    },

    renumber() {
      this.items().forEach((item, index) => {
        item.querySelector('[data-row-number]').textContent = `Element ${index + 1}`;
      });
      this.count = this.items().length;
      this.refresh();
      this.summarize();
    },

    async add() {
      this.busy = true;

      const result = await attempt(`fields/${field}/rows`, new URLSearchParams(context()));

      this.busy = false;

      if (!result.ok) {
        this.status = result.status === 0 ? 'Brak połączenia. Spróbuj ponownie po powrocie sieci.' : failure(result);
        return;
      }

      state.version = result.json.version;
      applyProgress(result.json.progress);
      root.querySelector('[data-sortable-list]').insertAdjacentHTML('beforeend', result.json.html);
      this.status = result.json.message;
      this.openRow = this.items().at(-1)?.dataset.sortableItem ?? null;
      this.$nextTick(() => {
        this.renumber();
        this.items().at(-1)?.querySelector('input, textarea, [contenteditable]')?.focus();
      });
    },

    async remove(control) {
      const item = control.closest('[data-sortable-item]');

      if (!window.confirm('Usunąć ten element listy?')) {
        return;
      }

      const uuid = item.dataset.sortableItem;
      const result = await attempt(`fields/${field}/rows/${uuid}/delete`, new URLSearchParams(context()));

      if (!result.ok) {
        this.status = result.status === 0 ? 'Brak połączenia. Spróbuj ponownie po powrocie sieci.' : failure(result);
        return;
      }

      state.version = result.json.version;
      applyProgress(result.json.progress);
      item.remove();
      this.status = result.json.message;
      this.renumber();
      [...root.querySelectorAll('[data-repeater-add]')].at(-1)?.focus();
    },
  };
};

const upload = (path, body, onProgress) => new Promise((resolve) => {
  const request = new XMLHttpRequest();

  request.open('POST', editorUrl(path));
  request.setRequestHeader('Accept', 'application/json');
  request.setRequestHeader('X-CSRF-TOKEN', csrfToken());
  request.upload.addEventListener('progress', (event) => {
    if (event.lengthComputable) {
      onProgress(Math.round((event.loaded / event.total) * 100));
    }
  });
  request.addEventListener('load', () => {
    let json = {};

    try {
      json = JSON.parse(request.responseText);
    } catch {
      // Odpowiedź bez JSON (np. błąd serwera): komunikat ogólny.
    }

    resolve({ ok: request.status >= 200 && request.status < 300, status: request.status, json });
  });
  request.addEventListener('error', () => resolve({ ok: false, status: 0, json: {} }));
  request.send(body);
});

export const clientMedia = ({ field, row = null, version = 0, max = 0, count = 0, single = false, link = '' }) => {
  const state = { version };
  let root = null;
  const context = () => ({ base_version: state.version, ...(row ? { row } : {}) });
  const base = sortable({
    url: () => editorUrl(`fields/${field}/assets/reorder`),
    extra: context,
    onSaved: (json) => {
      state.version = json.version ?? state.version;
      applyProgress(json.progress);
    },
  });
  const list = () => root.querySelector('[data-sortable-list]');

  const showProgress = (percent) => {
    const bar = root.querySelector('[data-upload-progress]');

    bar.querySelector('[role="progressbar"]').setAttribute('aria-valuenow', percent);
    bar.querySelector('[data-progress-fill]').style.setProperty('width', `${percent}%`);
  };

  return {
    ...base,
    count,
    max,
    uploading: false,
    single,
    // Link do zdjęcia (np. ze stocka) zamiast pliku; zapisany adres i stan pola tekstowego.
    link,
    linkDraft: link,
    linkSaving: false,

    init() {
      root = this.$el;
      base.init.call(this);
    },

    async saveLink() {
      const url = this.linkDraft.trim();

      if (url === this.link || this.linkSaving) {
        return;
      }

      this.linkSaving = true;
      const result = await attempt(`fields/${field}/link`, new URLSearchParams({ ...context(), url }));
      this.linkSaving = false;

      if (!result.ok) {
        this.status = result.status === 0 ? 'Brak połączenia. Spróbuj ponownie po powrocie sieci.' : failure(result);
        return;
      }

      state.version = result.json.version;
      applyProgress(result.json.progress);
      this.link = result.json.link ?? '';
      this.linkDraft = this.link;
      this.status = result.json.message;

      // Zdjęcie: link zastępuje dodany plik.
      if (result.json.replaced) {
        list().innerHTML = '';
        this.count = 0;
      }
    },

    async removeLink() {
      this.linkDraft = '';
      await this.saveLink();
    },

    get full() {
      return !single && this.max > 0 && this.count >= this.max;
    },

    async upload(input) {
      const files = [...input.files];

      this.uploading = true;

      for (const [index, file] of files.entries()) {
        const body = new FormData();

        Object.entries(context()).forEach(([name, value]) => body.append(name, value));
        body.append('file', file);
        showProgress(0);
        this.status = files.length > 1 ? `Przesyłanie ${index + 1} z ${files.length}…` : 'Przesyłanie…';

        const result = await upload(`fields/${field}/assets`, body, showProgress);

        if (!result.ok) {
          const reason = result.status === 0 ? 'Brak połączenia. Spróbuj ponownie po powrocie sieci.' : failure(result);

          this.status = `${file.name}: ${reason}`;
          break;
        }

        state.version = result.json.version;
        applyProgress(result.json.progress);

        if (single) {
          list().innerHTML = '';
          // Plik zastępuje link (serwer usuwa go przy zapisie zdjęcia).
          this.link = '';
          this.linkDraft = '';
        }

        list().insertAdjacentHTML('beforeend', result.json.html);
        this.status = result.json.message;
      }

      this.uploading = false;
      input.value = '';
      this.count = list().children.length;
      this.$nextTick(() => this.refresh());
    },

    async detach(control) {
      const item = control.closest('[data-sortable-item]');
      const result = await attempt(`fields/${field}/assets/${item.dataset.sortableItem}/detach`, new URLSearchParams(context()));

      if (!result.ok) {
        this.status = result.status === 0 ? 'Brak połączenia. Spróbuj ponownie po powrocie sieci.' : failure(result);
        return;
      }

      state.version = result.json.version;
      applyProgress(result.json.progress);
      item.remove();
      this.count = list().children.length;
      this.status = result.json.message;
      this.refresh();
      // Przy pełnej galerii pole pliku odblokowuje się dopiero po aktualizacji Alpine.
      this.$nextTick(() => root.querySelector('input[type="file"]')?.focus());
    },

    async saveAlt(input) {
      const item = input.closest('[data-sortable-item]');
      const body = new URLSearchParams({ ...context(), alt: input.value });
      const result = await attempt(`fields/${field}/assets/${item.dataset.sortableItem}/alt`, body);

      if (!result.ok) {
        this.status = result.status === 0 ? 'Brak połączenia. Spróbuj ponownie po powrocie sieci.' : failure(result);
        return;
      }

      item.querySelector('img').alt = input.value;
      applyProgress(result.json.progress);
      this.status = result.json.message;
    },
  };
};

export const clientWysiwyg = () => ({
  init() {
    document.execCommand('defaultParagraphSeparator', false, 'p');
  },

  sync() {
    this.$refs.input.value = this.$refs.editor.innerHTML;
  },

  format(command) {
    this.$refs.editor.focus();
    document.execCommand(command);
    this.sync();
  },
});

// Dodatkowe uwagi pod sekcją: autozapis jak w polach (opóźnienie, zapis przy opuszczeniu pola), konflikt bez utraty tekstu.
export const clientSectionNote = ({ section, version = 0, open = false }) => {
  const id = `note:${section}`;
  let timer = null;
  let lastSaved = null;

  return {
    version,
    open,
    saving: false,
    error: false,
    message: '',

    init() {
      lastSaved = this.$refs.body.value;
    },

    show() {
      this.open = true;
      this.$nextTick(() => this.$refs.body.focus());
    },

    changed() {
      this.$store.saveStatus.report(id, 'dirty');
      clearTimeout(timer);
      timer = setTimeout(() => this.flush(), DEBOUNCE_MS);
    },

    async flush() {
      clearTimeout(timer);
      const body = this.$refs.body.value;

      if (body === lastSaved || this.saving) {
        return;
      }

      this.saving = true;
      this.$store.saveStatus.report(id, 'saving');
      const result = await attempt(`sections/${section}/note`, new URLSearchParams({ base_version: this.version, value: body }));
      this.saving = false;

      if (result.status === 409) {
        this.version = result.json.version;
        this.error = true;
        this.message = result.json.message;
        this.$store.saveStatus.report(id, 'conflict');
        return;
      }

      if (!result.ok) {
        this.error = true;
        this.message = result.status === 0 ? 'Brak połączenia. Spróbuj ponownie po powrocie sieci.' : failure(result);
        this.$store.saveStatus.report(id, 'error');
        return;
      }

      this.version = result.json.version;
      lastSaved = body;
      this.error = false;
      this.message = result.json.message;
      this.$store.saveStatus.report(id, 'saved');

      // Tekst zmieniony w trakcie zapisu — zapisujemy jeszcze raz.
      if (this.$refs.body.value !== lastSaved) {
        this.changed();
      }
    },
  };
};
