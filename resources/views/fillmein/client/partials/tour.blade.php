{{-- Samouczek edytora klienta (resources/js/fillmein/client-tour.js). Start: automatycznie albo zdarzenie `fmi-tour`. --}}
<div x-data="clientTour" x-on:fmi-tour.window="start()" x-on:keydown.window="keydown($event)">
  <template x-if="open">
    <div class="fixed inset-0 z-50" role="dialog" aria-modal="true" :aria-label="'Samouczek: ' + step.title">
      {{-- Przyciemnienie z „okienkiem” na pokazywanym elemencie (duży cień wokół ramki). --}}
      <div
        x-show="hole"
        class="pointer-events-none fixed rounded-xl ring-2 ring-fresh-400 shadow-[0_0_0_9999px_color-mix(in_oklab,var(--color-ink-950)_55%,transparent)] transition-all duration-300"
        :style="hole && `top:${hole.top}px;left:${hole.left}px;width:${hole.width}px;height:${hole.height}px`"
      ></div>
      <div x-show="! hole" class="fixed inset-0 bg-ink-950/55"></div>
      <div class="fixed inset-0" x-on:click="close()" aria-hidden="true"></div>

      <div
        x-ref="bubble"
        class="fixed w-[min(22rem,calc(100vw-2rem))] rounded-2xl bg-white p-5 text-ink-900 shadow-lg transition-all duration-300"
        :class="bubble.placement === 'center' && 'left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2'"
        :style="bubble.placement !== 'center' && `top:${bubble.top}px;left:${bubble.left}px`"
      >
        <p class="text-xs font-medium text-ink-500" x-text="`Krok ${index + 1} z ${steps.length}`"></p>
        <h2 class="mt-1 text-md font-semibold" x-text="step.title"></h2>
        <p class="mt-1.5 text-sm text-ink-600" x-text="step.text"></p>

        <div class="mt-4 flex items-center justify-between gap-2">
          <button type="button" class="text-sm text-ink-500 underline-offset-2 hover:text-ink-900 hover:underline" x-on:click="close()" x-show="! isLast">Pomiń</button>
          <span x-show="isLast"></span>
          <div class="flex gap-2">
            <x-ui.button type="button" variant="secondary" size="sm" x-show="index > 0" x-on:click="back()">Wstecz</x-ui.button>
            <x-ui.button type="button" size="sm" x-ref="next" x-on:click="next()"><span x-text="isLast ? 'Zaczynam' : 'Dalej'"></span></x-ui.button>
          </div>
        </div>

        <label class="mt-4 flex items-center gap-2 border-t border-ink-100 pt-3 text-xs text-ink-500">
          <input type="checkbox" class="size-4 rounded border-ink-300" x-model="off" x-on:change="toggleOff()">
          Nie pokazuj samouczka przy kolejnych wizytach
        </label>
      </div>
    </div>
  </template>
</div>
