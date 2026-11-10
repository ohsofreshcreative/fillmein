/*--- FILL ME IN: OSADZENIE PROTOTYPU FIGMY (Embed Kit 2.0) ---*/
// Zdarzenia Embed API przyjmujemy tylko od https://www.figma.com, tylko z tej ramki i tylko o udokumentowanych typach.
// Brak zdarzeń = brak synchronizacji: nie pokazujemy wtedy „aktualnego ekranu” ani sterowania (developers.figma.com/docs/embeds/embed-api).

const EVENTS = ['INITIAL_LOAD', 'PRESENTED_NODE_CHANGED', 'MOUSE_PRESS_OR_RELEASE', 'NEW_STATE', 'REQUEST_CLOSE', 'LOGIN_SCREEN_SHOWN', 'PASSWORD_SCREEN_SHOWN'];
const CONTROLS = ['NAVIGATE_BACKWARD', 'NAVIGATE_FORWARD', 'RESTART'];
const NODE_ID = /^[A-Za-z0-9:;_-]{1,255}$/;

export function figmaEmbed({ origin, events, loadTimeout = 20000 }) {
  return {
    // loading → loaded (ramka wczytana, bez zdarzeń) | ready (INITIAL_LOAD) | login | password | blocked
    state: 'loading',
    events,
    connected: false,
    presentedNodeId: null,
    received: {},
    timers: [],

    init() {
      this.onMessage = (event) => this.receive(event);
      window.addEventListener('message', this.onMessage);
      this.timers.push(setTimeout(() => {
        if (this.state === 'loading') this.state = 'blocked';
      }, loadTimeout));
    },

    destroy() {
      window.removeEventListener('message', this.onMessage);
      this.timers.forEach(clearTimeout);
    },

    loaded() {
      if (this.state === 'loading') this.state = 'loaded';
    },

    receive(event) {
      const frame = this.$refs.frame;

      if (!frame || event.origin !== origin || event.source !== frame.contentWindow) return;

      const message = event.data;

      if (!message || typeof message !== 'object' || typeof message.type !== 'string' || !EVENTS.includes(message.type)) return;

      const data = message.data && typeof message.data === 'object' ? message.data : {};
      this.received[message.type] = (this.received[message.type] ?? 0) + 1;
      this.connected = true;

      if (message.type === 'LOGIN_SCREEN_SHOWN') this.state = 'login';
      else if (message.type === 'PASSWORD_SCREEN_SHOWN') this.state = 'password';
      else if (this.state !== 'login' && this.state !== 'password') this.state = 'ready';

      if (message.type === 'PRESENTED_NODE_CHANGED' && typeof data.presentedNodeId === 'string' && NODE_ID.test(data.presentedNodeId)) {
        this.presentedNodeId = data.presentedNodeId;
      }

      this.$dispatch('figma-embed', { type: message.type, presentedNodeId: this.presentedNodeId });
    },

    control(type) {
      if (!this.connected || !CONTROLS.includes(type)) return;

      this.$refs.frame?.contentWindow?.postMessage({ type }, origin);
    },

    get eventSummary() {
      return Object.entries(this.received).map(([type, count]) => `${type} ×${count}`).join(', ');
    },
  };
}
