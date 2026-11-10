/*--- FILL ME IN: RECENZJA NA EKRANACH (Design Review) ---*/
// Jeden widok: pełny obraz ekranu (frame'u) z Figmy, przełączanie ekranów, uwagi jako dymki w miejscu kliknięcia.
// - Pinezka x, y (0–1) względem obrazu = względem granic frame'u (ReviewScreen::PIN_REFERENCE), więc trzyma się
//   elementu przy każdym rozmiarze. Ekran bez potwierdzonej powierzchni przyjmuje tylko uwagi bez pinezki.
// - Bez ekranów: uwaga z opisanym kontekstem (bez pinezki), jak dotąd.
// - Dymek uwagi: wątek, odpowiedź i „Rozwiąż” przez istniejące endpointy JSON; po zapisie przeładowanie z ?uwaga=N.
// Szkic nowej uwagi (treść, kontekst, ekran) trzymamy w sessionStorage tej karty przeglądarki.

export const BODY_MAX = 2000;
export const CONTEXT_MAX = 190;
const POPOVER_HEIGHT = 340;
const POPOVER_WIDTH = 332; // w-80 + odstęp od pinezki

// Pozycja przewinięcia przed przeładowaniem po zapisie — po powrocie strona zostaje w tym samym miejscu.
const SCROLL_KEY = 'fmi-review-scroll';

export function reviewComposer({ draftKey, action = null, csrf = '', screens = [], threads = [], canComment = true, needsName = false }) {
  return {
    action,
    csrf,
    screens,
    threads,
    canComment,
    needsName,
    screenId: '',
    showResolved: false,
    // Nowa uwaga
    open: false,
    pin: null,
    body: '',
    context: '',
    error: '',
    notice: '',
    saving: false,
    // Identyfikator żądania: ten sam przy ponowieniu po błędzie/timeoucie (serwer nie utworzy drugiej uwagi).
    requestId: null,
    // Otwarty dymek wątku
    active: null,
    reply: '',
    replyError: '',
    replySaving: false,
    replyRequestId: null,
    placement: { side: 'right', above: false },
    bodyMax: BODY_MAX,
    contextMax: CONTEXT_MAX,

    init() {
      // Element komponentu zapamiętany raz: $root w odroczonych wywołaniach zależy od elementu, z którego przyszło zdarzenie.
      this.rootEl = this.$el;
      try {
        const draft = JSON.parse(sessionStorage.getItem(draftKey) ?? 'null');
        if (draft && typeof draft === 'object') {
          this.body = typeof draft.body === 'string' ? draft.body.slice(0, BODY_MAX) : '';
          this.context = typeof draft.context === 'string' ? draft.context.slice(0, CONTEXT_MAX) : '';
          if (this.screens.some((screen) => String(screen.id) === String(draft.screenId))) this.screenId = String(draft.screenId);
        }
      } catch {
        // uszkodzony szkic — pomijamy
      }

      const params = new URLSearchParams(window.location.search);
      const focused = this.threads.find((thread) => String(thread.number) === (params.get('uwaga') ?? params.get('nowa')));
      const openFocused = params.has('uwaga');
      const wanted = params.get('ekran');
      if (focused?.screen_id && this.screens.some((screen) => screen.id === focused.screen_id)) this.screenId = String(focused.screen_id);
      else if (wanted && this.screens.some((screen) => String(screen.id) === wanted)) this.screenId = wanted;
      if (!this.screen && this.screens.length) this.screenId = String(this.screens[0].id);

      let restored = null;
      try {
        restored = JSON.parse(sessionStorage.getItem(SCROLL_KEY) ?? 'null');
        sessionStorage.removeItem(SCROLL_KEY);
      } catch {
        // brak dostępu do sessionStorage — zwykłe przewinięcie do dymka
      }
      const keep = restored && restored.path === window.location.pathname && Number.isFinite(restored.y);
      if (keep) {
        // Obraz ekranu ładuje się po starcie, więc przewijamy jeszcze raz po jego wczytaniu.
        const restore = () => window.scrollTo({ top: restored.y, behavior: 'instant' });
        restore();
        this.rootEl.querySelector('[data-screen-image]')?.addEventListener('load', restore, { once: true });
        window.addEventListener('load', restore, { once: true });
      }

      if (openFocused && focused && focused.pin && String(focused.screen_id) === this.screenId) {
        if (focused.status === 'resolved') this.showResolved = true;
        this.$nextTick(() => this.openThread(focused.number, { scroll: !keep }));
      }

      this.$watch('body', () => this.persist());
      this.$watch('context', () => this.persist());
    },

    get screen() {
      return this.screens.find((screen) => String(screen.id) === String(this.screenId)) ?? null;
    },

    get screenIndex() {
      return this.screens.findIndex((screen) => String(screen.id) === String(this.screenId));
    },

    get screenPins() {
      if (!this.screen) return [];
      return this.threads.filter((thread) => thread.screen_id === this.screen.id && thread.pin && (this.showResolved || thread.status !== 'resolved'));
    },

    get resolvedCount() {
      return this.screen ? this.threads.filter((thread) => thread.screen_id === this.screen.id && thread.pin && thread.status === 'resolved').length : 0;
    },

    openCount(screen) {
      return this.threads.filter((thread) => thread.screen_id === screen.id && thread.status !== 'resolved').length;
    },

    get thread() {
      return this.threads.find((thread) => thread.number === this.active) ?? null;
    },

    get hasDraft() {
      return this.body.trim() !== '' || this.context.trim() !== '';
    },

    persist() {
      try {
        if (this.hasDraft) sessionStorage.setItem(draftKey, JSON.stringify({ body: this.body, context: this.context, screenId: this.screenId }));
        else sessionStorage.removeItem(draftKey);
      } catch {
        // brak dostępu do pamięci sesji — szkic tylko w pamięci strony
      }
    },

    selectScreen(id) {
      if (String(id) === String(this.screenId)) return;
      if (this.pin) this.notice = 'Zmieniono ekran — pinezka usunięta, szkic treści został.';
      this.screenId = String(id);
      this.pin = null;
      this.active = null;
      if (this.open && !this.hasDraft) this.open = false;
      this.persist();
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('ekran', String(id));
        url.searchParams.delete('uwaga');
        window.history.replaceState(null, '', url);
      } catch {
        // bez zmiany adresu
      }
      this.$nextTick(() => this.$refs.stage?.scrollIntoView({ block: 'nearest' }));
    },

    step(delta) {
      if (!this.screens.length) return;
      const next = (this.screenIndex + delta + this.screens.length) % this.screens.length;
      this.selectScreen(this.screens[next].id);
      this.$nextTick(() => this.rootEl.querySelector(`[data-screen-tab="${this.screenId}"]`)?.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
    },

    onKey(event) {
      const tag = event.target?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || event.target?.isContentEditable) return;
      if (event.altKey || event.ctrlKey || event.metaKey) return;
      if (event.key === 'ArrowRight') { event.preventDefault(); this.step(1); }
      if (event.key === 'ArrowLeft') { event.preventDefault(); this.step(-1); }
    },

    // Położenie dymka względem punktu: na prawo, przy prawej krawędzi na lewo, a gdy nie mieści się z żadnej strony
    // (wąski ekran) — wyśrodkowany pod punktem. Przy dolnej krawędzi nad punktem.
    place(x, y) {
      const rect = this.$refs.surface?.getBoundingClientRect();
      const width = rect?.width ?? 0;
      const height = rect?.height ?? 0;
      const px = x * width;
      let side = 'right';
      if (px + POPOVER_WIDTH > width) side = px - POPOVER_WIDTH >= 0 ? 'left' : 'center';
      this.placement = { side, above: (1 - y) * height < POPOVER_HEIGHT && y * height > POPOVER_HEIGHT };
    },

    popoverStyle(point) {
      if (!point) return '';
      const ty = this.placement.above ? 'calc(-100% - 2.5rem)' : '0.75rem';
      if (this.placement.side === 'center') return `left: 50%; top: ${point.y * 100}%; transform: translate(-50%, ${ty})`;
      const tx = this.placement.side === 'left' ? 'calc(-100% - 0.75rem)' : '0.75rem';
      const tyAside = this.placement.above ? 'calc(-100% + 0.5rem)' : '-0.5rem';
      return `left: ${point.x * 100}%; top: ${point.y * 100}%; transform: translate(${tx}, ${tyAside})`;
    },

    // Kliknięcie obrazu: nowa pinezka (potwierdzona powierzchnia) albo uwaga do ekranu.
    pickPin(event) {
      if (this.active) { this.closeThread(); return; }
      if (!this.canComment && this.needsName) { this.askForName(); return; }
      if (!this.canComment || !this.screen) return;
      if (!this.screen.confirmed) {
        this.notice = 'Ten ekran nie ma potwierdzonej powierzchni — uwaga zostanie dodana do ekranu bez pinezki.';
        this.wholeScreen();
        return;
      }
      const rect = event.currentTarget.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width;
      const y = (event.clientY - rect.top) / rect.height;
      if (x < 0 || x > 1 || y < 0 || y > 1) return;
      this.pin = { x: round6(x), y: round6(y) };
      this.place(this.pin.x, this.pin.y);
      this.notice = '';
      this.startForm();
    },

    removePin() {
      this.pin = null;
    },

    // Gość bez podpisu: przewijamy do pola „Jak Cię podpisać?” i ustawiamy w nim kursor.
    askForName() {
      const form = document.querySelector('[data-guest-name-form]');
      if (!form) return;
      this.notice = 'Najpierw wpisz, jak Cię podpisać — potem kliknij w miejsce na ekranie.';
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
      form.querySelector('input[name="name"]')?.focus({ preventScroll: true });
    },

    wholeScreen() {
      if (!this.canComment && this.needsName) { this.askForName(); return; }
      if (!this.canComment) return;
      this.active = null;
      this.pin = null;
      this.startForm();
    },

    startForm() {
      this.open = true;
      this.error = '';
      this.focusLater(() => this.$refs.body);
    },

    // x-show przełącza widoczność po $nextTick, więc fokus ustawiamy w następnej klatce.
    focusLater(target) {
      this.$nextTick(() => requestAnimationFrame(() => target()?.focus({ preventScroll: true })));
    },

    cancel() {
      this.open = false;
      this.pin = null;
      this.error = '';
    },

    discard() {
      this.body = '';
      this.context = '';
      this.cancel();
    },

    onEscape() {
      if (this.active) this.closeThread();
      else if (this.open) this.cancel();
    },

    openThread(number, { scroll = false } = {}) {
      const thread = this.threads.find((item) => item.number === number);
      if (!thread) return;
      if (this.open && !this.hasDraft) this.cancel();
      this.open = false;
      this.pin = null;
      this.active = number;
      this.reply = '';
      this.replyError = '';
      this.replyRequestId = null;
      this.place(thread.pin.x, thread.pin.y);
      this.$nextTick(() => {
        const pin = this.rootEl.querySelector(`[data-pin="${number}"]`);
        if (scroll) pin?.scrollIntoView({ block: 'center' });
        this.$refs.threadPopover?.focus({ preventScroll: true });
      });
    },

    closeThread() {
      const number = this.active;
      this.active = null;
      this.$nextTick(() => this.rootEl.querySelector(`[data-pin="${number}"]`)?.focus({ preventScroll: true }));
    },

    toggleThread(number) {
      if (this.active === number) this.closeThread();
      else this.openThread(number);
    },

    validate() {
      if (this.body.trim() === '') return 'Wpisz treść uwagi.';
      if (this.body.length > BODY_MAX) return `Uwaga może mieć najwyżej ${BODY_MAX} znaków.`;
      if (!this.screen && this.context.trim() === '') return 'Opisz, którego miejsca dotyczy uwaga (np. „Koszyk”).';
      if (this.context.length > CONTEXT_MAX) return `Opis kontekstu może mieć najwyżej ${CONTEXT_MAX} znaków.`;
      return '';
    },

    payload() {
      const data = { body: this.body, request_id: this.requestId };
      if (this.screen) {
        data.screen_id = this.screen.id;
        if (this.pin && this.screen.confirmed) Object.assign(data, { x: this.pin.x, y: this.pin.y });
      } else {
        data.context = this.context;
      }
      return data;
    },

    async send(url, data) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
        body: JSON.stringify(data),
      });
    },

    async failure(response, what) {
      const json = await response.json().catch(() => null);
      const first = json?.errors ? Object.values(json.errors).flat()[0] : null;
      if (response.status === 422 && typeof first === 'string') return `${first}`;
      if (response.status === 409) return json?.message ?? 'Ktoś inny zmienił tę uwagę — odśwież stronę.';
      if (response.status === 429) return 'Za dużo zapisów w krótkim czasie — odczekaj chwilę.';
      if (response.status === 403 || response.status === 404) return `Nie masz uprawnień, aby ${what}.`;
      if (response.status === 419) return 'Sesja wygasła — odśwież stronę.';
      return 'Nie udało się zapisać — spróbuj ponownie.';
    },

    reload(number, { open = true } = {}) {
      const url = new URL(window.location.href);
      url.hash = '';
      url.searchParams.delete('ekran');
      url.searchParams.delete('uwaga');
      url.searchParams.delete('nowa');
      if (number) url.searchParams.set(open ? 'uwaga' : 'nowa', String(number));
      if (this.screen) url.searchParams.set('ekran', String(this.screen.id));
      try {
        sessionStorage.setItem(SCROLL_KEY, JSON.stringify({ path: window.location.pathname, y: window.scrollY }));
      } catch {
        // bez sessionStorage strona przewinie się do dymka uwagi
      }
      window.location.replace(url.toString());
    },

    async submit() {
      this.error = this.validate();
      if (this.error) return;
      if (!this.action) {
        this.error = 'Zapisywanie uwag nie jest włączone. Szkic jest zachowany w tej karcie przeglądarki.';
        return;
      }

      this.requestId ??= uuid();
      this.saving = true;
      try {
        const response = await this.send(this.action, this.payload());
        if (!response.ok) {
          this.error = `${await this.failure(response, 'dodać uwagę w tym projekcie')} Szkic jest zachowany.`;
          return;
        }
        const json = await response.json().catch(() => ({}));
        this.requestId = null;
        this.body = '';
        this.context = '';
        this.persist();
        this.cancel();
        this.notice = 'Zapisano uwagę.';
        this.$dispatch('review-comment-saved', { number: json.number ?? null, pin: Boolean(json.pin) });
        // Po dodaniu uwagi dymek się zamyka — na ekranie zostaje sama pinezka (?nowa= zamiast ?uwaga=).
        this.reload(json.number ?? null, { open: false });
      } catch {
        this.error = 'Brak połączenia — uwaga nie została zapisana. Szkic jest zachowany.';
      } finally {
        this.saving = false;
      }
    },

    async sendReply() {
      const thread = this.thread;
      if (!thread?.reply_url) return;
      if (this.reply.trim() === '') { this.replyError = 'Wpisz treść odpowiedzi.'; return; }
      if (this.reply.length > BODY_MAX) { this.replyError = `Odpowiedź może mieć najwyżej ${BODY_MAX} znaków.`; return; }
      this.replyRequestId ??= uuid();
      this.replySaving = true;
      this.replyError = '';
      try {
        const response = await this.send(thread.reply_url, { body: this.reply, request_id: this.replyRequestId });
        if (!response.ok) { this.replyError = await this.failure(response, 'odpowiedzieć na tę uwagę'); return; }
        this.reload(thread.number);
      } catch {
        this.replyError = 'Brak połączenia — odpowiedź nie została zapisana.';
      } finally {
        this.replySaving = false;
      }
    },

    async resolve() {
      const thread = this.thread;
      if (!thread?.status_url) return;
      this.replySaving = true;
      this.replyError = '';
      try {
        const response = await this.send(thread.status_url, { status: 'resolved', version: thread.version });
        if (!response.ok) { this.replyError = await this.failure(response, 'zmienić status tej uwagi'); return; }
        this.reload(thread.number);
      } catch {
        this.replyError = 'Brak połączenia — status nie został zmieniony.';
      } finally {
        this.replySaving = false;
      }
    },
  };
}

// crypto.randomUUID wymaga bezpiecznego kontekstu (HTTPS), a lokalnie aplikacja działa po HTTP.
function uuid() {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function round6(value) {
  return Math.round(value * 1e6) / 1e6;
}
