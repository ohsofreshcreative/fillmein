{{--
  Osadzony prototyp Figmy. Dane: $embed (FigmaEmbed::forLink), $title, opcjonalnie $commentable (warstwa trybu Komentuj;
  wymaga otaczającego komponentu reviewComposer).
  Ramka: tylko embed.figma.com (CSP frame-src strony), bez Referer, uprawnienie tylko do pełnego ekranu.
  Sterowanie i „aktualny ekran” pojawiają się wyłącznie po zdarzeniach Embed API (bez nich brak synchronizacji).
--}}
<div
  x-data="figmaEmbed({ origin: @js(config('fillmein.figma.embed.event_origin')), events: @js($embed['events']) })"
  class="flex flex-col gap-3"
  data-figma-embed
  :data-state="state"
>
  <div class="relative overflow-hidden rounded-xl border border-ink-150 bg-ink-950" @if ($commentable ?? false) data-comment-surface @endif>
    <iframe
      x-ref="frame"
      src="{{ $embed['src'] }}"
      title="{{ $title }}"
      class="block h-[min(75vh,48rem)] min-h-96 w-full"
      allow="fullscreen"
      allowfullscreen
      referrerpolicy="no-referrer"
      x-on:load="loaded()"
      @if ($commentable ?? false) x-bind:inert="mode === 'comment'" x-bind:tabindex="mode === 'comment' ? -1 : null" @endif
      data-figma-frame
    ></iframe>

    @if ($commentable ?? false)
      {{-- Warstwa trybu Komentuj: przechwytuje kliknięcia i dotyk, prototyp (inert) ich nie dostaje. --}}
      <button
        type="button"
        x-show="mode === 'comment'"
        x-cloak
        x-on:click="pick($event)"
        class="absolute inset-0 z-10 block w-full cursor-crosshair bg-ink-950/10 outline-none ring-inset focus-visible:ring-4 focus-visible:ring-fresh-400"
        aria-label="Tryb Komentuj — prototyp zatrzymany. Kliknij, aby wskazać miejsce uwagi; Enter — uwaga do ekranu."
        x-on:keydown.enter.prevent="wholeScreen()"
        x-on:keydown.space.prevent="wholeScreen()"
        data-comment-layer
      >
        <span class="pointer-events-none absolute top-3 left-3 rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-ink-900 shadow-xs">Tryb Komentuj — prototyp zatrzymany</span>
      </button>
      <span
        x-show="point"
        x-cloak
        class="pointer-events-none absolute z-20 size-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-fresh-500 shadow-sm"
        x-bind:style="point ? `left: ${point.x * 100}%; top: ${point.y * 100}%` : ''"
        aria-hidden="true"
        data-comment-marker
      ></span>
    @endif

    <div x-show="state === 'loading'" class="absolute inset-0 flex items-center justify-center bg-ink-50 text-sm text-ink-600" role="status">
      Wczytywanie prototypu z Figmy…
    </div>
  </div>

  <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0 text-sm" aria-live="polite">
      <p x-show="state === 'blocked'" x-cloak class="text-danger-800" data-embed-message="blocked">
        Figma nie odpowiada w osadzeniu (blokada treści osadzonych lub dostępu do pamięci w przeglądarce, brak sieci). Otwórz prototyp w Figmie.
      </p>
      <p x-show="state === 'login'" x-cloak class="text-warning-800" data-embed-message="login">
        Figma prosi o zalogowanie — plik nie jest udostępniony publicznie albo nie masz do niego dostępu.
      </p>
      <p x-show="state === 'password'" x-cloak class="text-warning-800" data-embed-message="password">
        Prototyp jest chroniony hasłem w Figmie.
      </p>
      <p x-show="state === 'ready' && presentedNodeId" x-cloak class="text-ink-600" data-embed-message="screen">
        Aktualny ekran (zdarzenie Figmy): <code class="text-ink-900" x-text="presentedNodeId"></code>
      </p>
      <p x-show="(state === 'loaded' || state === 'ready') && !connected" x-cloak class="text-ink-500" data-embed-message="no-events">
        @if ($embed['events'])
          Brak zdarzeń z Figmy — aplikacja nie zna bieżącego ekranu. Origin tej strony musi być dodany w aplikacji OAuth w Figmie (Embed API).
        @else
          Zdarzenia Figmy są wyłączone, więc aplikacja nie zna bieżącego ekranu. Prototyp działa normalnie.
        @endif
        Jeśli zamiast prototypu widzisz logowanie Figmy, plik nie jest udostępniony.
      </p>
      @unless ($embed['interactive'])
        <p class="mt-1 text-ink-500">To link do projektu, nie do prototypu — osadzenie pokazuje plik bez przejść. Do recenzji przejść użyj „Share prototype”.</p>
      @endunless
    </div>

    <div class="flex shrink-0 flex-wrap items-center gap-2">
      <template x-if="connected && state === 'ready'">
        <div class="flex items-center gap-2" data-embed-controls>
          <x-ui.button type="button" variant="secondary" size="sm" x-on:click="control('NAVIGATE_BACKWARD')">Wstecz</x-ui.button>
          <x-ui.button type="button" variant="secondary" size="sm" x-on:click="control('RESTART')">Od początku</x-ui.button>
          <x-ui.button type="button" variant="secondary" size="sm" x-on:click="control('NAVIGATE_FORWARD')">Dalej</x-ui.button>
        </div>
      </template>
      <x-ui.button variant="ghost" size="sm" :href="$embed['open_url']" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" data-embed-open>Otwórz w Figmie</x-ui.button>
    </div>
  </div>
</div>
