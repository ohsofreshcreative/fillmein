{{-- Pole dodane ręcznie, pokazywane w sekcji podglądu importu (rekurencyjnie). Dane: $field, $manualFields, $section. --}}
@php
  $children = $manualFields->where('parent_id', $field->id);
@endphp

<li class="py-3" id="pole-{{ $field->id }}">
  <div class="flex items-start justify-between gap-3">
    <p class="flex min-w-0 flex-wrap items-center gap-2">
      <span class="text-sm font-medium text-ink-950">{{ $field->label }}</span>
      <span class="text-xs text-ink-400">{{ $field->type->label() }}</span>
      <x-ui.badge variant="fresh">Dodane ręcznie</x-ui.badge>
    </p>
    <span class="flex shrink-0 items-center gap-1">
      <a href="{{ route('fmi.projects.pages.fields.edit', [$project, $page, $field, 'return' => 'import']) }}" class="inline-flex size-8 items-center justify-center rounded-md text-ink-500 transition hover:bg-ink-100 hover:text-ink-950" title="Edytuj pole" aria-label="Edytuj pole: {{ $field->label }}">
        @include('fillmein.partials.icon', ['name' => 'pencil', 'class' => 'size-4'])
      </a>
      <x-ui.confirm
        :action="route('fmi.projects.pages.fields.archive', [$project, $page, $field, 'return' => 'import'])"
        title="Usunąć pole?"
        confirm="Usuń"
        trigger="Usuń"
        trigger-variant="ghost"
        trigger-size="sm"
        :aria-label="'Usuń pole: ' . $field->label"
      >
        Pole „{{ $field->label }}” @if ($field->type->isContainer()) i jego pola podrzędne znikną @else zniknie @endif z edytora klienta (trafia do archiwum — można je przywrócić w „Sekcje i pola”).
      </x-ui.confirm>
    </span>
  </div>

  @unless ($field->type->isContainer())
    @include('fillmein.projects.pages.partials.import-mock', ['type' => $field->type, 'hint' => $field->settings['placeholder'] ?? null])
  @endunless

  @if ($field->type->isContainer())
    <div class="mt-2 rounded-xl border border-ink-150 p-4">
      @if ($children->isNotEmpty())
        <ul class="divide-y divide-ink-100" role="list">
          @foreach ($children as $child)
            @include('fillmein.projects.pages.partials.import-manual-field', ['field' => $child])
          @endforeach
        </ul>
      @endif
      @include('fillmein.projects.pages.partials.import-add-field', ['parent' => $field])
    </div>
  @endif
</li>
