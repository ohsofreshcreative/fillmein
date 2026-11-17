/**
 * Samouczek edytora klienta: dymki krok po kroku („Wstecz” / „Dalej” / „Pomiń”).
 *
 * - Pokazuje się przy każdej wizycie (nowa karta / ponowne otwarcie linku), ale tylko raz na kartę przeglądarki,
 *   żeby nie wracał przy przechodzeniu między podstronami.
 * - Klient może go wyłączyć („Nie pokazuj więcej”) — zapis w localStorage tego urządzenia.
 * - Kroki, których element nie istnieje lub jest niewidoczny na danej podstronie, są pomijane.
 */
const OFF_KEY = 'fmi:tour-off';
const SEEN_KEY = 'fmi:tour-seen';

const STEPS = [
  {
    title: 'Witaj!',
    text: 'Tu uzupełnisz treści do swojej strony. Pokażemy Ci w kilku krokach, jak to działa — zajmie to chwilę.',
  },
  {
    target: 'nav[aria-label="Podstrony"]',
    title: 'Podstrony',
    text: 'Lista podstron do uzupełnienia. Możesz przechodzić między nimi w dowolnej kolejności.',
  },
  {
    target: 'nav[aria-label="Sekcje tej podstrony"]',
    title: 'Sekcje podstrony',
    text: 'Każda podstrona składa się z sekcji. Kliknij numer, aby przejść od razu do wybranej.',
  },
  {
    target: 'section figure',
    title: 'Projekt sekcji',
    text: 'Tak wygląda ta część strony w projekcie. Kliknij obrazek, aby go powiększyć.',
  },
  {
    target: '[data-field-id]',
    title: 'Pola do uzupełnienia',
    text: 'Wpisz tu swoją treść. Szary tekst w polu to podpowiedź z projektu — możesz go zastąpić własnym.',
  },
  {
    target: '[data-progress-key="page"]',
    title: 'Postęp',
    text: 'Wszystko zapisuje się samo, bez przycisku „Zapisz”. Tu widzisz, ile zostało do uzupełnienia.',
  },
  {
    target: '[data-section-note-add]',
    title: 'Coś więcej?',
    text: 'Masz treść, której nie ma w projekcie, albo uwagę do sekcji? Dodaj ją tutaj.',
  },
  {
    target: '[data-client-page-actions]',
    title: 'Kolejne podstrony',
    text: 'Tu możesz dodać kolejną podstronę tego samego typu, np. dla następnego produktu.',
  },
  {
    title: 'Gotowe!',
    text: 'Do edytora możesz wrócić w każdej chwili przez ten sam link. Samouczek uruchomisz ponownie przyciskiem „Samouczek” u góry strony.',
  },
];

const PAD = 8;

function visible(el) {
  return el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
}

function storage(kind, action, key, value) {
  try {
    const store = kind === 'local' ? localStorage : sessionStorage;

    if (action === 'get') return store.getItem(key);
    if (action === 'set') store.setItem(key, value);
    if (action === 'remove') store.removeItem(key);
  } catch {
    // bez dostępu do storage samouczek po prostu pokaże się ponownie
  }

  return null;
}

export function clientTour() {
  return {
    open: false,
    steps: [],
    index: 0,
    off: false,
    hole: null,
    bubble: { top: 0, left: 0, placement: 'center' },

    init() {
      this.off = storage('local', 'get', OFF_KEY) === '1';
      const reposition = () => this.open && this.place();

      window.addEventListener('resize', reposition);
      window.addEventListener('scroll', reposition, { passive: true });

      if (!this.off && storage('session', 'get', SEEN_KEY) !== '1') {
        // Chwila na wczytanie obrazków i Alpine, żeby pozycje elementów były już ostateczne.
        setTimeout(() => this.start(), 600);
      }
    },

    get step() {
      return this.steps[this.index] ?? {};
    },

    get isLast() {
      return this.index >= this.steps.length - 1;
    },

    start() {
      this.steps = STEPS.filter((step) => !step.target || visible(document.querySelector(step.target)));
      this.index = 0;
      this.open = true;
      storage('session', 'set', SEEN_KEY, '1');
      this.show();
    },

    next() {
      if (this.isLast) {
        this.close();

        return;
      }

      this.index++;
      this.show();
    },

    back() {
      if (this.index > 0) {
        this.index--;
        this.show();
      }
    },

    close() {
      this.open = false;
      this.hole = null;
      storage('local', this.off ? 'set' : 'remove', OFF_KEY, '1');
    },

    toggleOff() {
      storage('local', this.off ? 'set' : 'remove', OFF_KEY, '1');
    },

    show() {
      const el = this.step.target ? document.querySelector(this.step.target) : null;

      if (el) {
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        // Po przewinięciu (smooth) przeliczamy jeszcze raz.
        setTimeout(() => this.place(), 350);
      }

      this.place();
      this.$nextTick(() => this.$refs.next?.focus({ preventScroll: true }));
    },

    place() {
      const el = this.step.target ? document.querySelector(this.step.target) : null;

      if (!el) {
        this.hole = null;
        this.bubble = { top: 0, left: 0, placement: 'center' };

        return;
      }

      const rect = el.getBoundingClientRect();
      const width = Math.min(352, window.innerWidth - 32);
      const height = this.$refs.bubble?.offsetHeight ?? 200;

      this.hole = {
        top: rect.top - PAD,
        left: rect.left - PAD,
        width: rect.width + PAD * 2,
        height: Math.min(rect.height, window.innerHeight * 0.7) + PAD * 2,
      };

      const below = rect.bottom + PAD + 12;
      const top = below + height < window.innerHeight
        ? below
        : Math.max(16, rect.top - PAD - 12 - height);
      const left = Math.min(Math.max(16, rect.left), window.innerWidth - width - 16);

      this.bubble = { top, left, placement: 'anchored' };
    },

    keydown(event) {
      if (!this.open) return;
      if (event.key === 'Escape') this.close();
      if (event.key === 'ArrowRight') this.next();
      if (event.key === 'ArrowLeft') this.back();
    },
  };
}
