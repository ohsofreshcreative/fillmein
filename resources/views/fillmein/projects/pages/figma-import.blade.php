@extends('fillmein.layouts.app')

@section('title', 'Import z Figmy: ' . $page->name)

@section('content')
  <a href="{{ route('fmi.projects.pages.edit', [$project, $page]) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $page->name }}
  </a>

  <x-ui.page-header
    eyebrow="Import z Figmy"
    :title="$page->name"
    description="Analiza warstw frame'u: proponowane sekcje i pola. Nic nie zostanie zapisane, dopóki nie zatwierdzisz importu."
  />

  @if ($errors->any())
    <x-ui.alert variant="danger" class="mt-6">{{ $errors->first() }}</x-ui.alert>
  @endif

  @if (! $plan)
    <x-ui.empty-state class="mt-8" title="Brak danych do analizy" :description="$planError">
      <x-slot:action>
        <x-ui.button :href="route('fmi.projects.pages.edit', [$project, $page])" variant="secondary" size="sm">Wróć do podstrony</x-ui.button>
      </x-slot:action>
    </x-ui.empty-state>
  @else
    @php
      $stats = $plan['analysis']['stats'];
      $counts = $plan['counts'];
      $excluded = $plan['analysis']['excluded'];
      $restored = $plan['analysis']['restored'];
      $changeLabels = ['name' => 'nazwa warstwy', 'label' => 'nowa nazwa', 'order' => 'nowa kolejność', 'meta' => 'uzupełnienie danych warstwy'];
    @endphp

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <x-ui.alert variant="info" title="Co zmienia import">
          Podgląd porównuje frame z ostatnim importem. Wybierz zmiany, które chcesz zastosować. Pola dodane ręcznie i treść klienta zostają bez zmian.
          Etykiety i typy ustawione przez zespół nie są nadpisywane. Elementy, których nie ma już w Figmie, trafiają do archiwum razem z treścią.
        </x-ui.alert>

        @if ($plan['truncated'])
          <x-ui.alert variant="warning" title="Frame pobrano tylko częściowo">
            Frame przekracza limit głębokości lub liczby warstw, więc część warstw nie została przeanalizowana.
            Dlatego ta synchronizacja niczego nie archiwizuje: wszystkie istniejące elementy zostają.
          </x-ui.alert>
        @endif

        @forelse ($plan['sections'] as $entry)
          @php
            $proposal = $entry['proposal'];
            $model = $entry['model'];
          @endphp
          <x-ui.card>
            <x-slot:header>
              <div class="flex min-w-0 flex-wrap items-center gap-2">
                <h2 class="truncate text-md font-semibold">{{ $model?->name ?? $proposal['name'] }}</h2>
                @include('fillmein.projects.pages.partials.import-action', ['action' => $entry['action']])
              </div>
              <div class="flex flex-wrap items-center gap-3">
                @include('fillmein.projects.pages.partials.import-select', ['value' => $entry['key'], 'action' => $entry['action'], 'name' => $model?->name ?? $proposal['name']])
                <form method="POST" action="{{ route('fmi.projects.pages.figma.rules', [$project, $page]) }}" class="shrink-0" data-keep-scroll>
                  @csrf
                  <input type="hidden" name="node" value="{{ $proposal['node_id'] }}">
                  <input type="hidden" name="action" value="exclude">
                  <x-ui.button type="submit" variant="ghost" size="sm">
                    Pomiń sekcję<span class="sr-only"> {{ $model?->name ?? $proposal['name'] }}</span>
                  </x-ui.button>
                </form>
              </div>
            </x-slot:header>

            {{-- Podgląd obok pól, w połowie szerokości karty i przyklejony przy przewijaniu długiej listy pól.
                 Kliknięcie otwiera pełny rozmiar w nowej karcie. --}}
            <div @class(['grid gap-6', 'md:grid-cols-2' => $previews->has($proposal['node_id'])])>
              @if ($previews->has($proposal['node_id']))
                @php
                  $previewSrc = route('fmi.projects.pages.figma.preview', [$project, $page, \App\Integrations\Figma\FigmaUrl::encodeNodeId($proposal['node_id'])]);
                @endphp
                <div class="md:sticky md:top-6 md:self-start">
                  <a href="{{ $previewSrc }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-ink-150 bg-ink-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900">
                    <img
                      src="{{ $previewSrc }}"
                      alt="Podgląd sekcji {{ $proposal['name'] }}"
                      loading="lazy"
                      class="max-h-[80vh] w-full object-contain object-top"
                    >
                  </a>
                  <p class="mt-2 text-sm text-ink-500">Kliknij, aby otworzyć w pełnym rozmiarze.</p>
                </div>
              @endif

              <div class="min-w-0">
                <p class="text-sm text-ink-500">
                  Warstwa „{{ $proposal['meta']['node_name'] ?? $proposal['name'] }}”
                  @if ($entry['changes'])
                    · {{ implode(', ', array_map(fn ($change) => $changeLabels[$change] ?? $change, $entry['changes'])) }}
                  @endif
                  @if ($entry['label_kept'])
                    · nazwa ustawiona przez zespół zostaje
                  @endif
                </p>

                @if ($entry['fields'])
                  <ul class="mt-2 divide-y divide-ink-100" role="list">
                    @foreach ($entry['fields'] as $field)
                      @include('fillmein.projects.pages.partials.import-field', ['entry' => $field])
                    @endforeach
                  </ul>
                @else
                  <p class="mt-2 text-base text-ink-600">Brak warstw tekstowych — sekcja bez pól (zdjęcia i inne pola można dodać ręcznie).</p>
                @endif
              </div>
            </div>
          </x-ui.card>
        @empty
          <x-ui.empty-state title="Nie znaleziono sekcji" description="Frame nie ma widocznych warstw do importu. Sprawdź wykluczenia obok." />
        @endforelse

        @if ($plan['archive'])
          <x-ui.card>
            <x-slot:header>
              <h2 class="text-md font-semibold">Nie ma ich już we frame'ie</h2>
            </x-slot:header>

            <ul class="divide-y divide-ink-100" role="list">
              @foreach ($plan['archive'] as $item)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                  <span class="min-w-0">
                    <span class="font-medium">{{ $item['kind'] === 'section' ? $item['model']->name : $item['model']->label }}</span>
                    <span class="text-sm text-ink-500">· {{ $item['kind'] === 'section' ? 'sekcja' : $item['model']->type->label() }}</span>
                    @if ($item['manual'] > 0)
                      <span class="block text-sm text-ink-500">Zawiera pola dodane ręcznie ({{ $item['manual'] }}) — zostaje.</span>
                    @endif
                    @if ($item['unverified'])
                      <span class="block text-sm text-ink-500">Warstwa poza pobraną częścią frame'u — zostaje.</span>
                    @endif
                  </span>
                  <span class="flex flex-wrap items-center gap-3">
                    @include('fillmein.projects.pages.partials.import-action', ['action' => $item['action']])
                    @if ($item['action'] === 'archive')
                      @include('fillmein.projects.pages.partials.import-select', ['value' => $item['key'], 'action' => $item['action'], 'name' => $item['kind'] === 'section' ? $item['model']->name : $item['model']->label])
                    @endif
                  </span>
                </li>
              @endforeach
            </ul>
          </x-ui.card>
        @endif
      </div>

      <div class="flex flex-col gap-6">
        <x-ui.card>
          <x-slot:header>
            <h2 class="text-md font-semibold">Podsumowanie</h2>
          </x-slot:header>

          <dl class="flex flex-col gap-3 text-base">
            <div class="flex justify-between gap-3"><dt class="text-sm text-ink-500">Frame</dt><dd class="truncate font-medium">{{ $plan['frame']->node_name ?? $plan['frame']->node_id }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-sm text-ink-500">Sekcje</dt><dd class="font-semibold">{{ $stats['sections'] }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-sm text-ink-500">Pola</dt><dd class="font-semibold">{{ $stats['fields'] }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-sm text-ink-500">Grupy</dt><dd class="font-semibold">{{ $stats['groups'] }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-sm text-ink-500">Repeatery</dt><dd class="font-semibold">{{ $stats['repeaters'] }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-sm text-ink-500">Wykluczone warstwy</dt><dd class="font-semibold">{{ $stats['excluded'] }}</dd></div>
          </dl>

          <div class="mt-5 flex flex-wrap gap-2 border-t border-ink-150 pt-4">
            <x-ui.badge variant="fresh">Nowe: {{ $counts['create'] }}</x-ui.badge>
            <x-ui.badge variant="warning">Zmienione: {{ $counts['update'] - $counts['moved'] }}</x-ui.badge>
            <x-ui.badge variant="info">Przeniesione: {{ $counts['moved'] }}</x-ui.badge>
            <x-ui.badge variant="success">Przywrócone: {{ $counts['restore'] }}</x-ui.badge>
            <x-ui.badge variant="danger">Do archiwum: {{ $counts['archive'] }}</x-ui.badge>
            <x-ui.badge variant="muted">Bez zmian: {{ $counts['unchanged'] }}</x-ui.badge>
          </div>

          @if ($plan['manual']['fields'] > 0 || $plan['manual']['sections']->isNotEmpty())
            <p class="mt-4 text-sm text-ink-600">
              Elementy ręczne poza importem: sekcje {{ $plan['manual']['sections']->count() }}, pola {{ $plan['manual']['fields'] }}.
            </p>
          @endif

          <form method="POST" action="{{ route('fmi.projects.pages.figma.fetch', [$project, $page]) }}" class="mt-4">
            @csrf
            <input type="hidden" name="force" value="1">
            <x-ui.button type="submit" variant="secondary" size="sm">Pobierz aktualny frame z Figmy</x-ui.button>
          </form>

          <x-slot:footer>
            {{-- Odcisk stoi za polami wyboru: przy obciętym POST (max_input_vars) brak odcisku odrzuci żądanie, zamiast zastosować część zmian. --}}
            <form id="import-form" method="POST" action="{{ route('fmi.projects.pages.figma.import.apply', [$project, $page]) }}" class="flex flex-col gap-2" data-loading-form>
              @csrf
              <input type="hidden" name="selection" value="1">
              <input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}">
              <x-ui.button type="submit" :disabled="! $plan['has_changes']">Zastosuj wybrane zmiany</x-ui.button>
              @unless ($plan['has_changes'])
                <p class="text-sm text-ink-500">Brak zmian — struktura podstrony jest zgodna z frame'em.</p>
              @endunless
            </form>
          </x-slot:footer>
        </x-ui.card>

        <x-ui.card>
          <x-slot:header>
            <h2 class="text-md font-semibold">Wykluczone warstwy ({{ count($excluded) }})</h2>
          </x-slot:header>

          @if ($excluded)
            <ul class="flex flex-col divide-y divide-ink-100" role="list">
              @foreach ($excluded as $node)
                <li class="flex items-start justify-between gap-3 py-2.5">
                  <div class="min-w-0">
                    <p class="truncate font-medium">{{ $node['name'] !== '' ? $node['name'] : $node['node_id'] }}</p>
                    <p class="text-sm text-ink-500">
                      {{ $node['reason_label'] }}
                      @if ($node['texts'] > 0)
                        · teksty: {{ $node['texts'] }}
                      @endif
                    </p>
                    @if ($node['path'] !== '')
                      <p class="truncate text-xs text-ink-500">{{ $node['path'] }}</p>
                    @endif
                  </div>
                  <form method="POST" action="{{ route('fmi.projects.pages.figma.rules', [$project, $page]) }}" class="shrink-0" data-keep-scroll>
                    @csrf
                    <input type="hidden" name="node" value="{{ $node['node_id'] }}">
                    <input type="hidden" name="action" value="{{ $node['auto_reason'] ? 'include' : 'reset' }}">
                    <x-ui.button type="submit" variant="secondary" size="sm">
                      Przywróć<span class="sr-only"> warstwę {{ $node['name'] }}</span>
                    </x-ui.button>
                  </form>
                </li>
              @endforeach
            </ul>
          @else
            <p class="text-base text-ink-600">Żadna warstwa nie została wykluczona.</p>
          @endif
        </x-ui.card>

        @if ($restored)
          <x-ui.card>
            <x-slot:header>
              <h2 class="text-md font-semibold">Przywrócone ręcznie ({{ count($restored) }})</h2>
            </x-slot:header>

            <ul class="flex flex-col divide-y divide-ink-100" role="list">
              @foreach ($restored as $node)
                <li class="flex items-start justify-between gap-3 py-2.5">
                  <div class="min-w-0">
                    <p class="truncate font-medium">{{ $node['name'] !== '' ? $node['name'] : $node['node_id'] }}</p>
                    <p class="text-sm text-ink-500">Automatycznie: {{ $node['reason_label'] }}</p>
                  </div>
                  <form method="POST" action="{{ route('fmi.projects.pages.figma.rules', [$project, $page]) }}" class="shrink-0" data-keep-scroll>
                    @csrf
                    <input type="hidden" name="node" value="{{ $node['node_id'] }}">
                    <input type="hidden" name="action" value="reset">
                    <x-ui.button type="submit" variant="ghost" size="sm">
                      Wyklucz ponownie<span class="sr-only"> warstwę {{ $node['name'] }}</span>
                    </x-ui.button>
                  </form>
                </li>
              @endforeach
            </ul>
          </x-ui.card>
        @endif
      </div>
    </div>
  @endif
@endsection
