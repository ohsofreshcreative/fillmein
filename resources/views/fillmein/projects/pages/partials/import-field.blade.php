{{--
  Pole w podglądzie importu, pokazane tak, jak zobaczy je klient w edycji treści (rekurencyjnie dla grup i repeaterów).
  Obok etykiety ikonki: edycja (pole już istnieje) i ukrycie (reguła „Pomiń” dla warstwy).
--}}
@php
  $proposal = $entry['proposal'];
  $model = $entry['model'];
  $proposedType = \App\Enums\FieldType::from($proposal['type']);
  $type = $model?->type ?? $proposedType;
  $label = $model?->label ?? $proposal['label'];
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
  $container = $type->isContainer();
  $iconButton = 'inline-flex size-8 items-center justify-center rounded-md text-ink-500 transition hover:bg-ink-100 hover:text-ink-950 focus-visible:outline-2 focus-visible:outline-ink-900';
@endphp

<li @class(['py-3', 'first:pt-0'])>
  <div class="flex items-start justify-between gap-3">
    <div class="flex min-w-0 flex-wrap items-center gap-2">
      <span @class(['font-medium text-ink-950', 'text-sm' => ! $container, 'text-base' => $container])>{{ $label }}</span>
      {{-- Typ i powód wyboru typu — w podpowiedzi, żeby nie zaśmiecać widoku. --}}
      <span class="text-xs text-ink-400" title="{{ $reason }}">{{ $type->label() }}@if ($items > 0) · ×{{ $items }}@endif</span>
      @include('fillmein.projects.pages.partials.import-action', ['action' => $entry['action']])
      @include('fillmein.projects.pages.partials.import-select', ['value' => $entry['key'], 'action' => $entry['action'], 'name' => $label])
    </div>

    <div class="flex shrink-0 items-center">
      @if ($model)
        <a href="{{ route('fmi.projects.pages.fields.edit', [$project, $page, $model, 'return' => 'import']) }}" class="{{ $iconButton }}" title="Edytuj pole" aria-label="Edytuj pole: {{ $label }}">
          @include('fillmein.partials.icon', ['name' => 'pencil', 'class' => 'size-4'])
        </a>
      @endif
      <form method="POST" action="{{ route('fmi.projects.pages.figma.rules', [$project, $page]) }}" data-keep-scroll>
        @csrf
        <input type="hidden" name="node" value="{{ $proposal['node_id'] }}">
        <input type="hidden" name="action" value="exclude">
        <button type="submit" class="{{ $iconButton }}" title="Ukryj — klient nie zobaczy tego pola" aria-label="Ukryj pole: {{ $label }}">
          @include('fillmein.partials.icon', ['name' => 'eye-off', 'class' => 'size-4'])
        </button>
      </form>
    </div>
  </div>

  @unless ($container)
    @include('fillmein.projects.pages.partials.import-mock', ['type' => $type, 'hint' => $proposal['figma_text']])
  @endunless

  @if ($entry['changes'])
    <p class="mt-1.5 text-xs text-ink-500">{{ implode(', ', array_map(fn ($change) => $changeLabels[$change] ?? $change, $entry['changes'])) }}</p>
  @endif
  @if ($entry['label_kept'])
    <p class="mt-1.5 text-xs text-ink-500">Etykieta ustawiona przez zespół zostaje (w Figmie: {{ $proposal['label'] }}).</p>
  @endif
  @if ($entry['move_blocked'])
    <p class="mt-1.5 text-xs text-ink-500">Kontener zawiera pola dodane ręcznie, więc zostaje w obecnym miejscu.</p>
  @endif
  @if ($entry['type_kept'])
    <p class="mt-1.5 text-xs text-ink-500">Typ ustawiony przez zespół zostaje (analiza proponuje: {{ $proposedType->label() }}).</p>
  @endif

  @if ($entry['type_key'])
    <div class="mt-2 rounded-lg border border-warning-200 bg-warning-50 p-3">
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
    {{-- Grupa / element repeatera — ramka jak u klienta. --}}
    <div class="mt-2 rounded-xl border border-ink-150 p-4">
      @if ($type === \App\Enums\FieldType::Repeater)
        <p class="mb-3 text-xs font-medium text-ink-500">Element listy{{ $items > 0 ? ' · w Figmie: ' . $items : '' }}</p>
      @endif
      <ul class="divide-y divide-ink-100" role="list">
        @foreach ($entry['children'] as $child)
          @include('fillmein.projects.pages.partials.import-field', ['entry' => $child])
        @endforeach
      </ul>
    </div>
  @endif
</li>
