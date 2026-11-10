{{-- Projekt sekcji z powiększeniem. Rodzic: Alpine `clientLightbox`. Dane: $url, $preview, $sectionName, opcjonalnie $highlight (nakładka pola; stan hlRect w sekcji). --}}
<figure class="min-w-0 xl:sticky xl:top-44">
  <button
    type="button"
    x-on:click="show(@js($url), @js('Projekt sekcji: ' . $sectionName))"
    class="flex w-full cursor-zoom-in justify-center overflow-hidden rounded-xl border border-ink-150 bg-ink-50 transition hover:border-fresh-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900"
  >
    {{-- Opakowanie ma dokładnie wymiary obrazu, więc prostokąt pola (0–1) trafia w tę samą warstwę. --}}
    <span class="relative block max-w-full">
      <img
        src="{{ $url }}"
        alt="Projekt sekcji: {{ $sectionName }}. Powiększ."
        width="{{ $preview->width }}"
        height="{{ $preview->height }}"
        loading="lazy"
        class="block h-auto max-h-80 w-auto max-w-full xl:max-h-[calc(100vh-14rem)]"
      >
      @if ($highlight ?? false)
        <span
          x-show="hlRect"
          x-cloak
          x-transition.opacity
          data-field-highlight
          aria-hidden="true"
          class="pointer-events-none absolute rounded-md border-2 border-fresh-500 bg-fresh-400/20 shadow-lg ring-2 ring-white transition-all duration-200"
          x-bind:style="hlRect ? `left: ${hlRect.x * 100}%; top: ${hlRect.y * 100}%; width: ${hlRect.w * 100}%; height: ${hlRect.h * 100}%` : ''"
        ></span>
      @endif
    </span>
  </button>
  <figcaption class="mt-2 text-sm text-ink-500">Tak wygląda ta sekcja w projekcie. Kliknij, aby powiększyć.</figcaption>
</figure>
