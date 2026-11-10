@extends('fillmein.layouts.app')

@section('title', 'Mapowanie ACF: ' . $project->name)

@section('content')
  @php
    $schema = $resolved['schema'];
    $warnings = $resolved['warnings'];
    $selectClass = 'h-10 w-full appearance-none rounded-lg border border-ink-200 bg-white pl-3 pr-9 text-base text-ink-900 shadow-xs transition duration-150 hover:border-ink-300 focus:ring-focus disabled:cursor-not-allowed disabled:bg-ink-100 disabled:text-ink-500';
    $chevron = '<svg class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>';
    $warningGroups = [
      'unmapped' => 'Pola z treścią klienta bez pary',
      'required' => 'Wymagane pola ACF bez źródła',
      'stale' => 'Dopasowania nieaktualne po zmianie schematu',
    ];
    $restoreButton = fn (string $target, string $label) => '<button type="submit" form="site-restore" name="restore" value="' . e($target) . '" class="text-sm font-medium text-ink-600 underline-offset-2 hover:text-ink-950 hover:underline focus:ring-focus rounded" data-site-restore>Przywróć automat<span class="sr-only"> — ' . e($label) . '</span></button>';
  @endphp

  <a href="{{ route('fmi.projects.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $project->name }}
  </a>

  <x-ui.page-header eyebrow="Treści" title="Mapowanie ACF" description="Sekcje i pola dopasowane do bloków ACF zakodowanego motywu. Eksport używa tych nazw, więc wtyczka importuje je bez dodatkowego mapowania." />

  @include('fillmein.projects.partials.content-tabs', ['project' => $project, 'active' => 'mapping'])

  @if ($errors->any() && ! $errors->has('schema'))
    <x-ui.alert variant="danger" class="mt-6">{{ $errors->first() }}</x-ui.alert>
  @endif

  @include('fillmein.projects.partials.site-schema-upload', ['project' => $project, 'schema' => $schema, 'class' => 'mt-6'])

  <div class="mt-4 flex flex-wrap items-center gap-3">
    <form method="POST" action="{{ route('fmi.projects.site-mapping.auto', $project) }}" data-loading-form>
      @csrf
      @if ($page)
        <input type="hidden" name="strona" value="{{ $page->id }}">
      @endif
      <x-ui.button type="submit" variant="secondary" size="sm" data-site-auto>Dopasuj automatycznie</x-ui.button>
    </form>
    <p class="text-sm text-ink-500">Automat nigdy nie zmienia dopasowań ustawionych ręcznie.</p>
  </div>

  @if ($warnings['unmapped'] || $warnings['required'] || $warnings['stale'])
    <x-ui.card class="mt-6" data-site-warnings>
      <x-slot:header>
        <h2 class="text-md font-semibold">Do sprawdzenia przed eksportem</h2>
      </x-slot:header>
      <div class="flex flex-col gap-5">
        @foreach ($warningGroups as $key => $title)
          @if ($warnings[$key])
            <div data-site-warning="{{ $key }}">
              <h3 class="flex items-center gap-2 text-sm font-medium text-ink-900">
                {{ $title }}
                <x-ui.badge :variant="$key === 'required' ? 'warning' : 'danger'">{{ count($warnings[$key]) }}</x-ui.badge>
              </h3>
              <ul class="mt-2 flex flex-col gap-1 text-sm" role="list">
                @foreach (array_slice($warnings[$key], 0, 15) as $item)
                  <li>
                    <a href="{{ route('fmi.projects.mapping', [$project, 'strona' => $item['page']->id]) }}" class="font-medium text-ink-950 underline-offset-2 hover:underline">{{ $item['page']->name }}</a>
                    <span class="text-ink-600">· {{ isset($item['what']) ? $item['what'] . ': ' : '' }}{{ $item['label'] }}</span>
                  </li>
                @endforeach
              </ul>
              @if (count($warnings[$key]) > 15)
                <p class="mt-1 text-sm text-ink-500">i {{ count($warnings[$key]) - 15 }} więcej</p>
              @endif
            </div>
          @endif
        @endforeach
      </div>
    </x-ui.card>
  @endif

  @if (! $page)
    <x-ui.empty-state class="mt-8" title="Brak podstron" description="Dodaj podstrony, aby dopasować je do schematu." />
  @else
    <div class="mt-6 grid gap-6 lg:grid-cols-4">
      <nav aria-label="Podstrony" class="lg:col-span-1">
        <ul class="flex flex-col gap-1" role="list" data-site-pages>
          @foreach ($pages as ['page' => $item, 'counts' => $counts])
            <li>
              <a
                href="{{ route('fmi.projects.mapping', [$project, 'strona' => $item->id]) }}"
                @if ($item->id === $page->id) aria-current="page" @endif
                @class([
                  'flex flex-col gap-1 rounded-xl px-3 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fresh-700',
                  'bg-ink-100 text-ink-950' => $item->id === $page->id,
                  'text-ink-700 hover:bg-ink-50' => $item->id !== $page->id,
                ])
              >
                <span class="font-medium">{{ $item->name }}</span>
                <span class="flex flex-wrap gap-1" aria-label="Pewne: {{ $counts['green'] }}, do sprawdzenia: {{ $counts['yellow'] }}, bez pary: {{ $counts['red'] }}">
                  <x-ui.badge variant="success" aria-hidden="true">{{ $counts['green'] }}</x-ui.badge>
                  <x-ui.badge variant="warning" aria-hidden="true">{{ $counts['yellow'] }}</x-ui.badge>
                  <x-ui.badge variant="danger" aria-hidden="true">{{ $counts['red'] }}</x-ui.badge>
                </span>
              </a>
            </li>
          @endforeach
        </ul>
      </nav>

      <div class="flex min-w-0 flex-col gap-6 lg:col-span-3">
        <form id="site-restore" method="POST" action="{{ route('fmi.projects.site-mapping.restore', [$project, $page]) }}" data-loading-form class="hidden">
          @csrf
        </form>

        <form method="POST" action="{{ route('fmi.projects.site-mapping.update', [$project, $page]) }}" class="flex flex-col gap-6" data-loading-form data-site-form>
          @csrf

          @php

            $pageEntry = $form['page'];

          @endphp
          <x-ui.card>
            <x-slot:header>
              <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-md font-semibold">{{ $page->name }} → strona w WordPressie</h2>
                <span class="flex items-center gap-3">
                  @include('fillmein.projects.partials.site-mapping-badge', ['entry' => $pageEntry])
                  @if ($pageEntry['source'] === 'manual')
                    {!! $restoreButton('page:' . $page->id, $page->name) !!}
                  @endif
                </span>
              </div>
            </x-slot:header>

            @php

              $pageValue = $pageEntry['target'] ?? '';

            @endphp
            <input type="hidden" name="original_page" value="{{ $pageValue }}">
            <label for="site-page" class="text-sm font-medium text-ink-800">Strona ze schematu</label>
            <div class="relative mt-1.5 sm:max-w-md">
              <select id="site-page" name="page" class="{{ $selectClass }}" data-site-page>
                <option value="">— nowa strona (wtyczka ją utworzy) —</option>
                @foreach ($form['page_options'] as $value => $label)
                  <option value="{{ $value }}" @selected((string) $pageValue === (string) $value)>{{ $label }}</option>
                @endforeach
              </select>
              {!! $chevron !!}
            </div>
            <p class="mt-2 text-sm text-ink-500">
              Typ wpisu: {{ $pageEntry['mapping']['post_type'] }} · slug: {{ $pageEntry['mapping']['slug'] }}
              @if ($pageEntry['mapping']['template']) · szablon: {{ $pageEntry['mapping']['template'] }} @endif
            </p>
          </x-ui.card>

          @foreach ($form['sections'] as $item)
            @php
              $section = $item['section'];
              $entry = $item['entry'];
            @endphp
            <x-ui.card data-site-section="{{ $section->id }}">
              <x-slot:header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <h2 class="min-w-0 truncate text-md font-semibold">{{ $section->name }}</h2>
                  <span class="flex items-center gap-3">
                    @include('fillmein.projects.partials.site-mapping-badge', ['entry' => $entry])
                    @if ($entry['source'] === 'manual')
                      {!! $restoreButton('section:' . $section->id, $section->name) !!}
                    @endif
                  </span>
                </div>
              </x-slot:header>

              <input type="hidden" name="original_sections[{{ $section->id }}]" value="{{ $item['value'] }}">
              <label for="site-section-{{ $section->id }}" class="text-sm font-medium text-ink-800">Blok ACF: {{ $section->name }}</label>
              <div class="relative mt-1.5 sm:max-w-md">
                <select id="site-section-{{ $section->id }}" name="sections[{{ $section->id }}]" class="{{ $selectClass }}" data-site-block>
                  <option value="">— nie importuj —</option>
                  @if ($entry['stale'])
                    <option value="{{ $item['value'] }}" selected>Nieaktualne: {{ $entry['target'] }}</option>
                  @endif
                  @foreach ($form['block_options'] as $group => $options)
                    @if ($options)
                      <optgroup label="{{ $group }}">
                        @foreach ($options as $value => $label)
                          <option value="{{ $value }}" @selected($item['value'] === $value)>{{ $label }}</option>
                        @endforeach
                      </optgroup>
                    @endif
                  @endforeach
                </select>
                {!! $chevron !!}
              </div>
              @if ($entry['hidden'])
                <p class="mt-2 text-sm text-ink-500">Sekcja jest ukryta — wtyczka ją pomija.</p>
              @elseif ($entry['block'] && $entry['position'] === null)
                <p class="mt-2 text-sm text-ink-500">Bloku nie ma w tym miejscu strony — wtyczka doda nowy blok.</p>
              @endif

              @if ($item['fields'])
                <ul class="mt-4 divide-y divide-ink-100 border-t border-ink-100" role="list">
                  @foreach ($item['fields'] as $row)
                    @php
                      $field = $row['field'];
                    @endphp
                    <li class="grid gap-2 py-3 sm:grid-cols-12 sm:items-center sm:gap-4" data-site-field="{{ $field->id }}">
                      <div @class(['min-w-0 sm:col-span-5', ['', 'pl-4', 'pl-8', 'pl-12'][min($row['depth'], 3)]])>
                        <p class="font-medium text-ink-950">{{ $field->label }}</p>
                        <p class="text-sm text-ink-500">
                          {{ $field->type->label() }}
                          @if ($row['sample'] !== null)
                            · <span class="text-ink-700">{{ $row['sample'] }}</span>
                          @endif
                        </p>
                      </div>
                      <div class="sm:col-span-5">
                        <input type="hidden" name="original_fields[{{ $field->id }}]" value="{{ $row['value'] }}">
                        <label for="site-field-{{ $field->id }}" class="sr-only">Pole ACF dla „{{ $field->label }}”</label>
                        <div class="relative">
                          <select id="site-field-{{ $field->id }}" name="fields[{{ $field->id }}]" class="{{ $selectClass }}" @disabled(! $row['options'] && ! $row['stale'] && $row['value'] === '') data-site-acf>
                            <option value="">{{ $field->type === \App\Enums\FieldType::Group ? '— bez grupy w ACF —' : '— nie importuj —' }}</option>
                            @if ($row['stale'])
                              <option value="{{ $row['value'] }}" selected>Nieaktualne: {{ $row['target'] }}</option>
                            @endif
                            @foreach ($row['options'] as $value => $label)
                              <option value="{{ $value }}" @selected($row['value'] === $value)>{{ $label }}</option>
                            @endforeach
                          </select>
                          {!! $chevron !!}
                        </div>
                        @if ($row['scope'] === '__none__')
                          <p class="mt-1 text-sm text-ink-500">Najpierw dopasuj repeater nadrzędny.</p>
                        @elseif (! $entry['block'] && ! $entry['hidden'])
                          <p class="mt-1 text-sm text-ink-500">Wybierz blok sekcji.</p>
                        @endif
                      </div>
                      <div class="flex flex-wrap items-center gap-3 sm:col-span-2 sm:flex-col sm:items-end sm:gap-1">
                        @include('fillmein.projects.partials.site-mapping-badge', ['entry' => $row])
                        @if ($row['source'] === 'manual')
                          {!! $restoreButton('field:' . $field->id, $field->label) !!}
                        @endif
                      </div>
                    </li>
                  @endforeach
                </ul>
              @endif
            </x-ui.card>
          @endforeach

          <input type="hidden" name="complete" value="1">
          <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="submit">Zapisz dopasowanie</x-ui.button>
            <p class="text-sm text-ink-500">Zmienione listy stają się dopasowaniem ręcznym. Po zmianie bloku pola sekcji dopasuje automat.</p>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
