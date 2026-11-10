{{-- Wiersz repeatera jako panel akordeonu. Dane: $repeater, $row, $number (null: nowy wiersz, numer ustawia JS), $structure, $section, $collapsed. --}}
{{-- Nagłówek pokazuje numer, pierwszą wpisaną treść i postęp; otwarty jest jeden element listy naraz (stan w clientRepeater). --}}
{{-- Lista w liście ma inne tło i akcent, bo element zagnieżdżony zlewałby się z elementem nadrzędnym. --}}
@php
  $nested = $row->level() % 2 === 1;
  $bodyId = 'row-' . $row->uuid;
@endphp
<li data-sortable-item="{{ $row->uuid }}" data-row @class([
  'flex flex-col rounded-xl border border-l-4',
  'border-ink-200 border-l-fresh-700 bg-white shadow-xs' => $nested,
  'border-ink-150 border-l-fresh-400 bg-ink-50' => ! $nested,
])>
  <div class="flex items-center justify-between gap-2 p-3 sm:px-4">
    <div class="flex min-w-0 flex-1 items-center gap-2">
      <x-ui.sort-controls :label="'element listy ' . $structure->label($repeater)" :first="$first ?? false" :last="$last ?? false" />
      <button
        type="button"
        data-row-toggle
        class="flex min-w-0 flex-1 items-center gap-2 rounded-lg py-1 text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fresh-700"
        aria-controls="{{ $bodyId }}"
        x-bind:aria-expanded="openRow === @js($row->uuid) ? 'true' : 'false'"
        x-on:click="toggle(@js($row->uuid))"
      >
        <span class="min-w-0 truncate text-sm text-ink-900">
          <span data-row-number class="font-semibold">Element {{ $number }}</span><span data-row-title class="text-ink-600"></span>
        </span>
        <span data-row-progress class="ml-auto shrink-0 rounded-full bg-white px-2 py-0.5 text-xs font-medium text-ink-600 ring-1 ring-ink-150"><span data-row-done></span><span class="hidden sm:inline"> uzupełnione</span></span>
        <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-ink-700 transition-transform duration-150" x-bind:class="openRow === @js($row->uuid) && 'rotate-180'">
          @include('fillmein.partials.icon', ['name' => 'chevron-down', 'class' => 'size-6'])
        </span>
      </button>
    </div>
  </div>

  <div
    id="{{ $bodyId }}"
    data-row-body
    class="flex flex-col gap-4 border-t border-ink-150 p-3 sm:p-4"
    x-show="openRow === @js($row->uuid)"
    @if ($collapsed ?? false) x-cloak @endif
  >
    @foreach ($structure->children($section, $repeater) as $child)
      @include('fillmein.client.partials.field', ['field' => $child, 'row' => $row])
    @endforeach

    {{-- Usuwanie na dole rozwiniętego panelu (nie w miejscu strzałki), jako dyskretny link z koszem — nie myli się z zapisem. --}}
    <div class="flex justify-end border-t border-ink-150 pt-3">
      <button
        type="button"
        data-row-remove
        class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm text-ink-500 transition hover:bg-danger-50 hover:text-danger-700 focus:ring-focus"
        aria-label="{{ 'Usuń element listy ' . $structure->label($repeater) }}"
        x-on:click="remove($el)"
      >
        @include('fillmein.partials.icon', ['name' => 'trash', 'class' => 'size-4'])
        Usuń element
      </button>
    </div>
  </div>
</li>
