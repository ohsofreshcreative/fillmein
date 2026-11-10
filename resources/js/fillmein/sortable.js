/*--- FILL ME IN: SORTOWANIE LIST ---*/
// Przeciąganie myszą i przyciski „wyżej / niżej” (klawiatura, ekrany dotykowe) na tej samej liście.
// Zapis idzie kolejką, żeby szybkie przesunięcia dotarły w kolejności; przy błędzie lista wraca do stanu sprzed zmiany.

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// `url` może być funkcją, `extra` dokłada pola żądania tuż przed wysyłką, a `onSaved` dostaje odpowiedź JSON.
export default ({ url = '', parent = null, extra = null, onSaved = null } = {}) => {
  let list = null;
  let dragged = null;
  let before = [];
  let queue = Promise.resolve();

  const items = () => [...list.children].filter((el) => el.matches('[data-sortable-item]'));
  const order = () => items().map((el) => el.dataset.sortableItem);

  // Listy bywają zagnieżdżone: szukamy elementu należącego bezpośrednio do tej listy.
  const ownItem = (node) => {
    let el = node;

    while (el && el.parentElement !== list) {
      el = el.parentElement;
    }

    return el?.matches?.('[data-sortable-item]') ? el : null;
  };

  const refreshControls = () => {
    items().forEach((el, index, all) => {
      const setEdge = (name, atEdge) => {
        const control = el.querySelector(`[data-sortable-control="${name}"]`);

        if (atEdge) {
          control?.setAttribute('aria-disabled', 'true');
        } else {
          control?.removeAttribute('aria-disabled');
        }
      };

      setEdge('up', index === 0);
      setEdge('down', index === all.length - 1);
    });
  };

  const applyOrder = (ids) => {
    ids.forEach((id) => {
      const el = items().find((item) => item.dataset.sortableItem === id);

      if (el) {
        list.append(el);
      }
    });
    refreshControls();
  };

  const send = async (ids) => {
    const body = new URLSearchParams();

    ids.forEach((id) => body.append('order[]', id));

    if (parent) {
      body.append('parent_id', parent);
    }

    Object.entries(extra?.() ?? {}).forEach(([name, value]) => body.append(name, value));

    const response = await fetch(typeof url === 'function' ? url() : url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
      body,
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    onSaved?.(await response.json().catch(() => ({})));
  };

  return {
    status: '',

    init() {
      list = this.$el.querySelector('[data-sortable-list]');
    },

    refresh() {
      refreshControls();
    },

    save(previous, message) {
      refreshControls();
      this.$root?.dispatchEvent(new CustomEvent('sortable:moved'));

      if (!url) {
        return;
      }

      const ids = order();

      queue = queue
        .then(() => send(ids))
        .then(() => {
          this.status = message;
        })
        .catch(() => {
          applyOrder(previous);
          this.$root?.dispatchEvent(new CustomEvent('sortable:moved'));
          this.status = 'Nie udało się zapisać kolejności. Odśwież stronę i spróbuj ponownie.';
        });
    },

    move(control, step) {
      const item = ownItem(control);

      if (!item || control.getAttribute('aria-disabled') === 'true') {
        return;
      }

      const all = items();
      const target = all[all.indexOf(item) + step];

      if (!target) {
        return;
      }

      const previous = order();

      list.insertBefore(item, step < 0 ? target : target.nextElementSibling);
      control.focus();
      this.save(previous, `Przeniesiono: pozycja ${items().indexOf(item) + 1} z ${all.length}.`);
    },

    dragStart(event) {
      dragged = ownItem(event.target);

      if (!dragged) {
        return;
      }

      before = order();
      event.stopPropagation();
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', dragged.dataset.sortableItem);
      event.dataTransfer.setDragImage(dragged, 16, 16);
      dragged.style.opacity = '0.5';
    },

    dragOver(event) {
      if (!dragged) {
        return;
      }

      event.preventDefault();

      const target = ownItem(event.target);

      if (!target || target === dragged) {
        return;
      }

      const box = target.getBoundingClientRect();

      list.insertBefore(dragged, event.clientY > box.top + box.height / 2 ? target.nextElementSibling : target);
    },

    dragEnd() {
      if (!dragged) {
        return;
      }

      dragged.style.opacity = '';
      dragged = null;

      if (order().join() !== before.join()) {
        this.save(before, 'Kolejność została zapisana.');
      }
    },
  };
};
