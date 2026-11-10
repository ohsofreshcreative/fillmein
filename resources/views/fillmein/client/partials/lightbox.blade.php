{{-- Okno powiększenia. Rodzic: Alpine `clientLightbox`. --}}
<dialog
  x-ref="lightbox"
  x-on:click.self="close()"
  aria-label="Powiększony projekt sekcji"
  class="m-auto max-h-[92vh] max-w-[92vw] overflow-auto rounded-2xl bg-white p-0 shadow-xs backdrop:bg-ink-950/80"
>
  <div class="sticky top-0 z-10 flex justify-end p-2">
    <x-ui.button variant="secondary" size="sm" x-on:click="close()">Zamknij</x-ui.button>
  </div>
  <img x-bind:src="src" x-bind:alt="alt" class="block h-auto max-w-full">
</dialog>
