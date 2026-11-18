{{-- Samouczek edytora klienta (resources/js/fillmein/client-tour.js). Start: automatycznie albo zdarzenie `fmi-tour`. --}}
<div x-data="clientTour({ tour: @js($tour ?? 'editor') })" x-on:fmi-tour.window="start()" x-on:keydown.window="keydown($event)">
  <template x-if="open">
    <div class="fixed inset-0 z-50" role="dialog" aria-modal="true" :aria-label="'Samouczek: ' + step.title">
      {{-- Przyciemnienie z „okienkiem” na pokazywanym elemencie (duży cień wokół ramki). --}}
      <div
        x-show="hole"
        class="pointer-events-none fixed rounded-xl ring-2 ring-fresh-400 shadow-[0_0_0_9999px_color-mix(in_oklab,var(--color-ink-950)_55%,transparent)] transition-all duration-300"
        :style="hole && `top:${hole.top}px;left:${hole.left}px;width:${hole.width}px;height:${hole.height}px`"
      ></div>
      <div x-show="! hole" class="fixed inset-0 bg-ink-950/55"></div>
      {{-- Blokuje klikanie w stronę pod spodem; samouczek zamyka się tylko przyciskami. --}}
      <div class="fixed inset-0" aria-hidden="true"></div>

      <div
        x-ref="bubble"
        class="fixed w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-2xl bg-white text-ink-900 shadow-lg ring-1 ring-ink-950/5 transition-all duration-300"
        :class="bubble.placement === 'center' && 'left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2'"
        :style="bubble.placement !== 'center' && `top:${bubble.top}px;left:${bubble.left}px`"
      >
        <div class="flex items-start justify-between gap-3 bg-fresh-400 px-5 pt-4 pb-3 text-ink-950">
          <div class="min-w-0">
            <p class="text-xs font-semibold tracking-wide uppercase opacity-70" x-text="`Krok ${index + 1} z ${steps.length}`"></p>
            <h2 class="mt-0.5 text-lg font-semibold" x-text="step.title"></h2>
          </div>
          <button type="button" x-on:click="close()" class="-mr-1 inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-white/60 text-ink-950 transition hover:bg-white" aria-label="Zamknij samouczek">
            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" /></svg>
          </button>
        </div>

        <div class="px-5 pt-4 pb-5">
          <p class="text-base text-ink-700" x-text="step.text"></p>

          {{-- Postęp: kropki kroków. --}}
          <div class="mt-4 flex gap-1.5" aria-hidden="true">
            <template x-for="(item, i) in steps" :key="i">
              <span class="h-1.5 rounded-full transition-all" :class="i === index ? 'w-6 bg-fresh-500' : (i < index ? 'w-1.5 bg-fresh-300' : 'w-1.5 bg-ink-200')"></span>
            </template>
          </div>

          <div class="mt-5 flex items-center justify-between gap-2">
            <x-ui.button type="button" variant="ghost" size="sm" x-show="! isLast" x-on:click="close()">Pomiń samouczek</x-ui.button>
            <span x-show="isLast"></span>
            <div class="flex gap-2">
              <x-ui.button type="button" variant="secondary" x-show="index > 0" x-on:click="back()">Wstecz</x-ui.button>
              <x-ui.button type="button" variant="fresh" x-ref="next" x-on:click="next()"><span x-text="isLast ? 'Zaczynam' : 'Dalej →'"></span></x-ui.button>
            </div>
          </div>
        </div>

        <label class="flex items-center gap-2 border-t border-ink-100 bg-ink-50 px-5 py-3 text-sm text-ink-600">
          <input type="checkbox" class="size-4 rounded border-ink-300 accent-fresh-500" x-model="off" x-on:change="toggleOff()">
          Nie pokazuj samouczka przy kolejnych wizytach
        </label>
      </div>
    </div>
  </template>
</div>
