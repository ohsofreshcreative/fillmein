{{--
  Rekurencyjna lista pól jednego poziomu. Dane: $fields (aktywne pola tego poziomu), $fieldsByParent,
  $section, $parent (kontener lub null), $project, $page.
--}}
@php
  $addUrl = route('fmi.projects.pages.sections.fields.create', [$project, $page, $section]) . ($parent ? '?parent=' . $parent->id : '');
@endphp

@if ($fields->isEmpty())
  <p class="text-sm text-ink-500">{{ $parent ? 'Ten kontener nie ma jeszcze pól.' : 'Ta sekcja nie ma jeszcze pól.' }}</p>
@else
  <div x-data="sortable({ url: @js(route('fmi.projects.pages.sections.fields.reorder', [$project, $page, $section])), parent: @js($parent?->id) })">
    <p class="sr-only" role="status" x-text="status"></p>
    <ul data-sortable-list x-on:dragover="dragOver($event)" x-on:drop.prevent class="flex flex-col gap-2">
      @foreach ($fields as $field)
        <li data-sortable-item="{{ $field->id }}" id="pole-{{ $field->id }}" class="scroll-mt-6">
          <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink-150 bg-white px-3 py-2">
            <div class="flex min-w-0 items-center gap-2">
              <x-ui.sort-controls :label="$field->label" :first="$loop->first" :last="$loop->last" />
              <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2 font-medium text-ink-950">
                  {{ $field->label }}
                  @if ($field->is_required)
                    <x-ui.badge variant="fresh">Wymagane</x-ui.badge>
                  @endif
                  @if ($field->is_hidden)
                    <x-ui.badge variant="warning">Ukryte</x-ui.badge>
                  @endif
                </p>
                <p class="truncate text-sm text-ink-500">
                  {{ $field->type->label() }} · {{ $field->source->label() }} · <span class="font-mono text-xs">{{ $field->name }}</span>
                </p>
              </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
              <x-ui.button :href="route('fmi.projects.pages.fields.edit', [$project, $page, $field])" variant="ghost" size="sm" :aria-label="'Edytuj pole: ' . $field->label">Edytuj</x-ui.button>
              <x-ui.confirm
                :action="route('fmi.projects.pages.fields.archive', [$project, $page, $field])"
                title="Zarchiwizować pole?"
                confirm="Archiwizuj"
                trigger="Archiwizuj"
                trigger-variant="ghost"
                trigger-size="sm"
                :aria-label="'Archiwizuj pole: ' . $field->label"
              >
                Pole „{{ $field->label }}” @if ($field->type->isContainer()) i jego pola podrzędne znikną @else zniknie @endif z edytora klienta. Treść zostaje — można je przywrócić.
              </x-ui.confirm>
            </div>
          </div>

          @if ($field->type->isContainer())
            <div class="ml-4 mt-2 border-l border-ink-150 pl-4">
              @include('fillmein.projects.pages.structure.partials.fields', [
                'parent' => $field,
                'fields' => ($fieldsByParent[$field->id] ?? collect())->reject->isArchived()->values(),
              ])
            </div>
          @endif
        </li>
      @endforeach
    </ul>
  </div>
@endif

@if (! $parent || $parent->canHaveChildren())
  <div class="mt-3">
    <x-ui.button :href="$addUrl" variant="secondary" size="sm" :aria-label="'Dodaj pole do: ' . ($parent?->label ?? $section->name)">Dodaj pole</x-ui.button>
  </div>
@endif
