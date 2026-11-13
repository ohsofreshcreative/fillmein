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

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-3" x-data="clientLightbox">
    @include('fillmein.client.partials.lightbox')
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
            // Sekcja wymaga decyzji, gdy ona albo któreś z jej pól się zmienia — tylko taka jest rozwinięta.
            $pending = function (array $item) use (&$pending): bool {
              return ! in_array($item['action'], ['unchanged', 'keep'], true) || ! empty($item['changes']) || ! empty($item['type_key'])
                || collect($item['children'] ?? $item['fields'] ?? [])->contains(fn ($child) => $pending($child));
            };
            $needsDecision = $pending($entry);
            $sectionManual = $model ? $manualFields->where('section_id', $model->id) : collect();
            $fieldCount = collect($entry['fields'])->count() + $sectionManual->whereNull('parent_id')->count();
          @endphp
          <x-ui.card>
            <x-slot:header>
              <div class="flex min-w-0 items-center gap-3">
                <span aria-hidden="true" class="flex size-10 shrink-0 items-center justify-center rounded-full bg-fresh-400 text-base font-semibold text-ink-950">{{ $loop->iteration }}</span>
                <div class="min-w-0">
                  <p class="text-sm text-ink-500">Sekcja {{ $loop->iteration }} z {{ $loop->count }}</p>
                  <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <h2 class="truncate text-md font-semibold">{{ $model?->name ?? $proposal['name'] }}</h2>
                    @include('fillmein.projects.pages.partials.import-action', ['action' => $entry['action']])
                  </div>
                </div>
              </div>
              <div class="flex flex-wrap items-center gap-3">
                @include('fillmein.projects.pages.partials.import-select', ['value' => $entry['key'], 'action' => $entry['action'], 'name' => $model?->name ?? $proposal['name']])
              </div>
            </x-slot:header>

            {{-- Podgląd obok pól, w połowie szerokości karty i przyklejony przy przewijaniu długiej listy pól.
                 Kliknięcie otwiera powiększenie (lightbox jak w edytorze klienta). --}}
            <details class="group" data-remember-open="{{ $proposal['node_id'] }}" @if ($needsDecision) open @endif>
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg bg-ink-50 px-3 py-2 text-sm text-ink-600 transition hover:bg-ink-100 group-open:mb-4 [&::-webkit-details-marker]:hidden">
              <span class="inline-block transition group-open:rotate-90" aria-hidden="true">▸</span>
              <span class="group-open:hidden">{{ $needsDecision ? 'Do przejrzenia' : 'Bez zmian' }} · pól: {{ $fieldCount }} · <span class="font-medium text-ink-900">pokaż pola</span></span>
              <span class="hidden group-open:inline">{{ $needsDecision ? 'Do przejrzenia' : 'Bez zmian' }} · <span class="font-medium text-ink-900">zwiń</span></span>
            </summary>
            <div @class(['grid gap-6', 'md:grid-cols-2' => $previews->has($proposal['node_id'])])>
              @if ($previews->has($proposal['node_id']))
                @php
                  $previewSrc = route('fmi.projects.pages.figma.preview', [$project, $page, \App\Integrations\Figma\FigmaUrl::encodeNodeId($proposal['node_id'])]);
                @endphp
                <div class="md:sticky md:top-6 md:self-start">
                  <button
                    type="button"
                    x-on:click="show(@js($previewSrc), @js('Podgląd sekcji ' . $proposal['name']))"
                    class="block w-full cursor-zoom-in overflow-hidden rounded-lg border border-ink-150 bg-ink-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900"
                    aria-label="Powiększ podgląd sekcji {{ $proposal['name'] }}"
                  >
                    <img
                      src="{{ $previewSrc }}"
                      alt="Podgląd sekcji {{ $proposal['name'] }}"
                      loading="lazy"
                      class="max-h-[80vh] w-full object-contain object-top"
                    >
                  </button>
                  <p class="mt-2 text-sm text-ink-500">Tak wygląda ta sekcja w projekcie. Kliknij, aby powiększyć.</p>
                </div>
              @endif

              <div class="min-w-0">
                <p class="mb-3 text-xs text-ink-400">
                  Warstwa „{{ $proposal['meta']['node_name'] ?? $proposal['name'] }}”
                  @if ($entry['changes'])
                    · {{ implode(', ', array_map(fn ($change) => $changeLabels[$change] ?? $change, $entry['changes'])) }}
                  @endif
                  @if ($entry['label_kept'])
                    · nazwa ustawiona przez zespół zostaje
                  @endif
                </p>

                @if ($entry['fields'])
                  <ul class="divide-y divide-ink-100" role="list">
                    @foreach ($entry['fields'] as $field)
                      @include('fillmein.projects.pages.partials.import-field', ['entry' => $field])
                    @endforeach
                  </ul>
                @elseif ($sectionManual->isEmpty() && collect($excluded)->where('section_node_id', $proposal['node_id'])->isEmpty())
                  <p class="mt-2 text-base text-ink-600">Brak warstw tekstowych — sekcja bez pól z Figmy.</p>
                @endif

                @php
                  $hiddenHere = collect($excluded)->where('section_node_id', $proposal['node_id']);
                @endphp
                @if ($hiddenHere->isNotEmpty())
                  {{-- Ukryte pola zostają w swojej sekcji, mocno wyszarzone, z „Pokaż”. --}}
                  <ul class="mt-2 divide-y divide-ink-100 border-t border-ink-100" role="list" data-import-hidden>
                    @foreach ($hiddenHere as $node)
                      <li class="flex items-center justify-between gap-3 py-3">
                        <div class="min-w-0 opacity-50">
                          <p class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-medium text-ink-700 line-through">{{ $node['name'] !== '' ? $node['name'] : $node['node_id'] }}</span>
                            <span class="text-xs text-ink-500">Ukryte · {{ $node['reason_label'] }}</span>
                          </p>
                          <div class="mt-2 h-10 rounded-lg border border-dashed border-ink-200" aria-hidden="true"></div>
                        </div>
                        <form method="POST" action="{{ route('fmi.projects.pages.figma.rules', [$project, $page]) }}" class="shrink-0" data-keep-scroll>
                          @csrf
                          <input type="hidden" name="node" value="{{ $node['node_id'] }}">
                          <input type="hidden" name="action" value="{{ $node['auto_reason'] ? 'include' : 'reset' }}">
                          <button type="submit" class="inline-flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium text-ink-700 transition hover:bg-ink-100 hover:text-ink-950" aria-label="Pokaż pole: {{ $node['name'] }}">
                            @include('fillmein.partials.icon', ['name' => 'review', 'class' => 'size-4'])
                            Pokaż
                          </button>
                        </form>
                      </li>
                    @endforeach
                  </ul>
                @endif

                @if ($sectionManual->whereNull('parent_id')->isNotEmpty())
                  <ul class="mt-2 divide-y divide-ink-100 border-t border-ink-100" role="list">
                    @foreach ($sectionManual->whereNull('parent_id') as $manual)
                      @include('fillmein.projects.pages.partials.import-manual-field', ['field' => $manual, 'section' => $model])
                    @endforeach
                  </ul>
                @endif

                @if ($model && ! $model->isArchived())
                  @include('fillmein.projects.pages.partials.import-add-field', ['section' => $model, 'parent' => null])
                @else
                  <p class="mt-3 text-sm text-ink-500">Własne pola dodasz tu po zastosowaniu importu tej sekcji.</p>
                @endif
                <form method="POST" action="{{ route('fmi.projects.pages.figma.rules', [$project, $page]) }}" class="mt-4 border-t border-ink-100 pt-3" data-keep-scroll>
                  @csrf
                  <input type="hidden" name="node" value="{{ $proposal['node_id'] }}">
                  <input type="hidden" name="action" value="exclude">
                  <x-ui.button type="submit" variant="secondary" size="sm">
                    Pomiń całą sekcję w imporcie<span class="sr-only"> {{ $model?->name ?? $proposal['name'] }}</span>
                  </x-ui.button>
                </form>
              </div>
            </div>
            </details>
          </x-ui.card>
        @empty
          <x-ui.empty-state title="Nie znaleziono sekcji" description="Frame nie ma widocznych warstw do importu. Sprawdź wykluczenia obok." />
        @endforelse

        {{-- Sekcje dodane ręcznie (poza Figmą): import ich nie zmienia; można w nich dodawać pola. --}}
        @foreach ($plan['manual']['sections'] as $manualSection)
          <x-ui.card id="sekcja-{{ $manualSection->id }}">
            <x-slot:header>
              <div class="flex min-w-0 flex-wrap items-center gap-2">
                <h2 class="truncate text-md font-semibold">{{ $manualSection->name }}</h2>
                <x-ui.badge variant="fresh">Dodana ręcznie</x-ui.badge>
              </div>
              <x-ui.button :href="route('fmi.projects.pages.sections.edit', [$project, $page, $manualSection])" variant="ghost" size="sm">
                Edytuj<span class="sr-only"> sekcję {{ $manualSection->name }}</span>
              </x-ui.button>
            </x-slot:header>

            @php
              $own = $manualFields->where('section_id', $manualSection->id)->whereNull('parent_id');
            @endphp
            @if ($own->isNotEmpty())
              <ul class="divide-y divide-ink-100" role="list">
                @foreach ($own as $manual)
                  @include('fillmein.projects.pages.partials.import-manual-field', ['field' => $manual, 'section' => $manualSection])
                @endforeach
              </ul>
            @else
              <p class="text-sm text-ink-500">Sekcja nie ma jeszcze pól.</p>
            @endif
            @include('fillmein.projects.pages.partials.import-add-field', ['section' => $manualSection, 'parent' => null])
          </x-ui.card>
        @endforeach

        <x-ui.card>
          <details @if ($errors->has('name')) open @endif data-import-add-section>
            <summary class="cursor-pointer text-sm font-medium text-ink-800">+ Dodaj sekcję</summary>
            <p class="mt-2 text-sm text-ink-500">Na treści, których nie ma w makiecie (np. „Dodatkowe informacje”). Import z Figmy jej nie zmieni.</p>
            <form method="POST" action="{{ route('fmi.projects.pages.sections.store', [$project, $page]) }}" class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end" data-keep-scroll data-loading-form>
              @csrf
              <input type="hidden" name="return" value="import">
              <div class="sm:flex-1">
                <x-ui.input name="name" id="import-new-section" label="Nazwa sekcji" required maxlength="190" />
              </div>
              <x-ui.button type="submit" size="sm">Dodaj sekcję</x-ui.button>
            </form>
          </details>
        </x-ui.card>

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
