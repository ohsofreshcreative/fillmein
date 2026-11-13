{{-- Pole dodane ręcznie, pokazywane w sekcji podglądu importu (rekurencyjnie). Dane: $field, $manualFields, $section. --}}
@php
  $children = $manualFields->where('parent_id', $field->id);
@endphp

<li class="py-3" id="pole-{{ $field->id }}">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <p class="flex flex-wrap items-center gap-2">
      <span class="font-medium text-ink-950">{{ $field->label }}</span>
      <x-ui.badge variant="muted">{{ $field->type->label() }}</x-ui.badge>
      <x-ui.badge variant="fresh">Dodane ręcznie</x-ui.badge>
    </p>
    <x-ui.button :href="route('fmi.projects.pages.fields.edit', [$project, $page, $field])" variant="ghost" size="sm">
      Edytuj<span class="sr-only"> pole {{ $field->label }}</span>
    </x-ui.button>
  </div>

  @if ($field->type->isContainer())
    <div class="ml-2 border-l-2 border-ink-150 pl-4 sm:ml-4">
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
