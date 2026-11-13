{{-- Pole w podglądzie importu (rekurencyjnie dla grup i repeaterów). Istniejące pole pokazuje konfigurację zespołu. --}}
@php
  $proposal = $entry['proposal'];
  $model = $entry['model'];
  $proposedType = \App\Enums\FieldType::from($proposal['type']);
  $type = $model?->type ?? $proposedType;
  $changeLabels = [
    'text' => 'nowy tekst z Figmy',
    'label' => 'nowa etykieta',
    'type' => 'nowy typ: ' . $proposedType->label(),
    'move' => 'nowe miejsce w strukturze',
    'order' => 'nowa kolejność',
    'items' => 'zmiana elementów listy w Figmie',
    'pattern' => 'nowy wzorzec listy',
    'meta' => 'uzupełnienie danych warstwy',
  ];
  $items = count($proposal['meta']['items'] ?? []);
  $reason = \App\Integrations\Figma\FrameAnalyzer::TYPE_REASONS[$proposal['reason']] ?? $proposal['reason'];
  // Grupa bez zmian to tylko nagłówek nad wciętymi polami — bez typu i opisu.
  $plainGroup = $type === \App\Enums\FieldType::Group && in_array($entry['action'], ['unchanged', 'keep'], true) && ! $entry['changes'] && ! $entry['type_key'];
@endphp

<li class="flex flex-col gap-2 py-3">
  <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
      @if ($plainGroup)
        <p class="text-sm font-semibold text-ink-700" title="{{ $reason }}">{{ $model?->label ?? $proposal['label'] }}</p>
      @else
        <p class="flex flex-wrap items-center gap-2">
          <span class="font-medium text-ink-950">{{ $model?->label ?? $proposal['label'] }}</span>
          {{-- Dlaczego taki typ — w podpowiedzi po najechaniu, żeby nie zaśmiecać listy. --}}
          <x-ui.badge variant="muted" title="{{ $reason }}">{{ $type->label() }}</x-ui.badge>
          @include('fillmein.projects.pages.partials.import-action', ['action' => $entry['action']])
        </p>
        @if ($items > 0 || $entry['changes'])
          <p class="mt-1 text-sm text-ink-500">
            {{ implode(' · ', array_filter([
              $items > 0 ? 'elementów w Figmie: ' . $items : null,
              $entry['changes'] ? implode(', ', array_map(fn ($change) => $changeLabels[$change] ?? $change, $entry['changes'])) : null,
            ])) }}
          </p>
        @endif
      @endif
      @if ($entry['label_kept'])
        <p class="mt-1 text-sm text-ink-600">Etykieta ustawiona przez zespół zostaje (w Figmie: {{ $proposal['label'] }}).</p>
      @endif
      @if ($entry['move_blocked'])
        <p class="mt-1 text-sm text-ink-600">Kontener zawiera pola dodane ręcznie, więc zostaje w obecnym miejscu.</p>
      @endif
      @if ($entry['type_kept'])
        <p class="mt-1 text-sm text-ink-600">Typ ustawiony przez zespół zostaje (analiza proponuje: {{ $proposedType->label() }}).</p>
      @endif
      @if (filled($proposal['figma_text']) && ! $plainGroup)
        <p class="mt-1 line-clamp-2 text-sm text-ink-600">„{{ \Illuminate\Support\Str::limit($proposal['figma_text'], 160) }}”</p>
      @endif
    </div>

    <div class="flex shrink-0 flex-wrap items-center gap-3">
    @include('fillmein.projects.pages.partials.import-select', ['value' => $entry['key'], 'action' => $entry['action'], 'name' => $model?->label ?? $proposal['label']])
    <form method="POST" action="{{ route('fmi.projects.pages.figma.rules', [$project, $page]) }}" class="shrink-0" data-keep-scroll>
      @csrf
      <input type="hidden" name="node" value="{{ $proposal['node_id'] }}">
      <input type="hidden" name="action" value="exclude">
      <x-ui.button type="submit" variant="ghost" size="sm">
        Pomiń<span class="sr-only"> warstwę {{ $model?->label ?? $proposal['label'] }}</span>
      </x-ui.button>
    </form>
    </div>
  </div>

  @if ($entry['type_key'])
    <div class="rounded-lg border border-warning-200 bg-warning-50 p-3">
      <x-ui.checkbox
        name="selected[]"
        :value="$entry['type_key']"
        :id="'type-' . md5($entry['type_key'])"
        form="import-form"
        :label="'Zmień typ na: ' . $proposedType->label() . ' i wyczyść treść pola'"
        description="Zmiana ryzykowna: pole ma zapisaną treść. Zostanie ona tylko w historii zmian."
      />
    </div>
  @endif

  @if ($entry['children'])
    <ul class="ml-2 divide-y divide-ink-100 border-l-2 border-ink-150 pl-4 sm:ml-4" role="list">
      @foreach ($entry['children'] as $child)
        @include('fillmein.projects.pages.partials.import-field', ['entry' => $child])
      @endforeach
    </ul>
  @endif
</li>
