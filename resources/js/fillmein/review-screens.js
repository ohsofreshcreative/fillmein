/**
 * Dodawanie podstron do Design Review kolejką: każda podstrona to osobne, krótkie żądanie (hosting przerywa
 * długie żądania błędem 503). Wiersze z błędem zostają w formularzu z komunikatem, udane znikają.
 */
export function reviewScreens({ rows = [], url = '' } = {}) {
  return {
    rows: rows.map((row) => ({ ...row, state: '', message: '' })),
    busy: false,
    done: 0,

    add() {
      this.rows.push({ name: '', url: '', state: '', message: '' });
      this.$nextTick(() => [...this.$el.querySelectorAll('[data-subpage-name]')].at(-1)?.focus());
    },

    remove(index) {
      this.rows.splice(index, 1);
      if (!this.rows.length) this.add();
    },

    async send() {
      if (this.busy) return;
      const queue = this.rows.filter((row) => row.url.trim() !== '');
      if (!queue.length) return;

      this.busy = true;
      this.done = 0;
      queue.forEach((row) => { row.state = 'queued'; row.message = 'W kolejce'; });

      for (const row of queue) {
        row.state = 'running';
        row.message = 'Pobieranie z Figmy…';
        const body = new FormData();
        body.append('screens[0][name]', row.name);
        body.append('screens[0][url]', row.url);

        try {
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

          if (response.ok) {
            row.state = 'done';
            row.message = data.message || 'Dodano.';
            this.done++;
          } else {
            row.state = 'error';
            row.message = response.status === 419
              ? 'Sesja wygasła — odśwież stronę.'
              : response.status >= 500
                ? `Serwer nie odpowiedział (${response.status}) — spróbuj dodać tę podstronę ponownie.`
                : (data.errors ? Object.values(data.errors).flat()[0] : null) || data.message || 'Nie udało się dodać podstrony.';
          }
        } catch {
          row.state = 'error';
          row.message = 'Brak połączenia — spróbuj ponownie.';
        }
      }

      this.busy = false;

      if (this.rows.every((row) => row.state === 'done' || row.url.trim() === '')) {
        window.location.reload();
        return;
      }

      // Zostawiamy tylko wiersze z błędem; udane pojawią się na liście po odświeżeniu.
      this.rows = this.rows.filter((row) => row.state === 'error');
      if (this.done > 0) this.rows.unshift({ name: '', url: '', state: 'info', message: `Dodano: ${this.done}. Odśwież stronę, aby zobaczyć je na liście.` });
    },
  };
}
