{{--
  „+ Dodaj pole” na ekranie importu. Dane: $project, $page, $section (istniejąca sekcja), opcjonalnie $parent (grupa
  lub repeater, do którego dodajemy pole środka). Pole jest ręczne — import z Figmy go nie zmienia.
--}}
@php
  $parent ??= null;
  $uid = 'add-' . $section->id . '-' . ($parent?->id ?? 0);
  $types = collect(\App\Enums\FieldType::cases())
    ->when($parent?->depth >= (int) config('fillmein.fields.max_depth', 3) - 1, fn ($all) => $all->reject(fn ($type) => $type->isContainer()))
    ->mapWithKeys(fn ($type) => [$type->value => $type->label()])
    ->all();
@endphp

<details class="mt-3 border-t border-dashed border-ink-200 pt-3" data-import-add-field>
  <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-ink-700 hover:text-ink-950 [&::-webkit-details-marker]:hidden">
    <span class="flex size-6 items-center justify-center rounded-full border border-ink-300 text-base leading-none" aria-hidden="true">+</span>
    {{ $parent ? 'Dodaj pole w „' . $parent->label . '”' : 'Dodaj pole' }}
  </summary>
  <form
    method="POST"
    action="{{ route('fmi.projects.pages.sections.fields.store', [$project, $page, $section]) }}"
    class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end"
    data-keep-scroll
    data-loading-form
  >
    @csrf
    <input type="hidden" name="return" value="import">
    @if ($parent)
      <input type="hidden" name="parent_id" value="{{ $parent->id }}">
    @endif
    <x-ui.input name="label" :id="$uid . '-label'" label="Nazwa pola" required maxlength="190" error="" placeholder="np. Dodatkowy opis" />
    <x-ui.select name="type" :id="$uid . '-type'" label="Typ" :options="$types" value="text" error="" />
    <x-ui.button type="submit" size="sm">Dodaj</x-ui.button>
  </form>
</details>
