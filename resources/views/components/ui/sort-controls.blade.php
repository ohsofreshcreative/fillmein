{{--
  Sterowanie kolejnością elementu listy `x-data="sortable(…)"`: uchwyt do przeciągania (mysz)
  i przyciski wyżej/niżej (klawiatura, ekrany dotykowe). Element listy: `data-sortable-item="ID"`.
--}}
@props([
  'label',
  'first' => false,
  'last' => false,
])

<div {{ $attributes->class(['flex shrink-0 items-center gap-0.5']) }}>
  <span
    draggable="true"
    x-on:dragstart="dragStart($event)"
    x-on:dragend="dragEnd()"
    class="hidden cursor-grab rounded p-1 text-ink-400 hover:text-ink-700 sm:inline-flex"
    title="Przeciągnij, aby zmienić kolejność"
    aria-hidden="true"
  >
    @include('fillmein.partials.icon', ['name' => 'grip', 'class' => 'size-4'])
  </span>

  <x-ui.button variant="ghost" size="sm" class="!px-1.5" data-sortable-control="up" :aria-disabled="$first ? 'true' : null" :aria-label="'Przenieś wyżej: ' . $label" x-on:click="move($el, -1)">
    @include('fillmein.partials.icon', ['name' => 'arrow-up', 'class' => 'size-4'])
  </x-ui.button>
  <x-ui.button variant="ghost" size="sm" class="!px-1.5" data-sortable-control="down" :aria-disabled="$last ? 'true' : null" :aria-label="'Przenieś niżej: ' . $label" x-on:click="move($el, 1)">
    @include('fillmein.partials.icon', ['name' => 'arrow-down', 'class' => 'size-4'])
  </x-ui.button>
</div>
