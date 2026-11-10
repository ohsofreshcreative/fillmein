@extends('fillmein.layouts.app')

@section('title', 'Eksport: ' . $project->name)

@section('content')
  @php
    $oldScope = old('scope') === 'pages' ? 'pages' : 'project';
    $oldPages = array_values(array_filter((array) old('pages', []), 'is_string'));
    $counts = fn (array $s) => sprintf('sekcje: %d · pola: %d · zdjęcia: %d · braki: %d', $s['sections'], $s['fields'], $s['media'], $s['missing']);
    $ready = session('export_ready');
  @endphp

  <a href="{{ route('fmi.projects.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $project->name }}
  </a>

  <x-ui.page-header eyebrow="Treści" title="Eksport treści" description="Paczka ZIP z plikiem content.json i katalogiem media/ — niezależna od WordPressa i nazw bloków." />

  @include('fillmein.projects.partials.content-tabs', ['project' => $project, 'active' => 'exports'])

  @if ($errors->any())
    <x-ui.alert variant="danger" class="mt-6">{{ $errors->first() }}</x-ui.alert>
  @endif

  <x-ui.card class="mt-6">
    <x-slot:header>
      <div>
        <h2 class="text-md font-semibold">Nowy eksport</h2>
        <p class="mt-0.5 text-sm text-ink-500">Eksport tylko odczytuje treści — nie zmienia ich ani statusów klienta.</p>
      </div>
    </x-slot:header>

    @if ($pages === [])
      <p class="text-base text-ink-600">Projekt nie ma jeszcze aktywnych podstron.</p>
    @else
      <form
        method="POST"
        action="{{ route('fmi.projects.exports.store', $project) }}"
        data-loading-form
        data-export-form
        x-data="exportScope({ summaries: @js($summaries), selected: @js($oldPages) })"
        x-init="scope = @js($oldScope)"
      >
        @csrf

        <fieldset>
          <legend class="text-sm font-medium text-ink-900">Zakres</legend>
          <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
            <label class="inline-flex cursor-pointer items-center gap-2 text-base text-ink-900">
              <input type="radio" name="scope" value="project" x-model="scope" @checked($oldScope === 'project') class="size-4 accent-ink-900" data-export-scope="project">
              Cały projekt
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2 text-base text-ink-900">
              <input type="radio" name="scope" value="pages" x-model="scope" @checked($oldScope === 'pages') class="size-4 accent-ink-900" data-export-scope="pages">
              Wybrane podstrony
            </label>
          </div>
        </fieldset>

        <fieldset class="mt-5" x-show="scope === 'pages'" @if ($oldScope !== 'pages') x-cloak @endif data-export-pages>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <legend class="text-sm font-medium text-ink-900">Podstrony</legend>
            <div class="flex gap-1">
              <x-ui.button type="button" variant="ghost" size="sm" x-on:click="selectAll()">Zaznacz wszystkie</x-ui.button>
              <x-ui.button type="button" variant="ghost" size="sm" x-on:click="selectNone()">Odznacz</x-ui.button>
            </div>
          </div>

          <ul class="mt-2 divide-y divide-ink-100 rounded-xl border border-ink-150" role="list">
            @foreach ($pages as ['page' => $page, 'depth' => $depth])
              <li @class(['flex items-start gap-3 py-2.5 pr-3', ['pl-3', 'pl-8', 'pl-13', 'pl-18', 'pl-23', 'pl-28', 'pl-33'][min($depth, 6)]])>
                <input
                  id="export-page-{{ $page->uuid }}"
                  type="checkbox"
                  name="pages[]"
                  value="{{ $page->uuid }}"
                  x-model="selected"
                  x-bind:disabled="scope !== 'pages'"
                  @checked(in_array($page->uuid, $oldPages, true))
                  class="mt-1 size-4 shrink-0 cursor-pointer rounded accent-ink-900"
                >
                <label for="export-page-{{ $page->uuid }}" class="min-w-0 cursor-pointer">
                  <span class="block font-medium text-ink-950">{{ $page->name }}</span>
                  <span class="block text-sm text-ink-500">{{ $counts($summaries[$page->uuid]) }}</span>
                </label>
              </li>
            @endforeach
          </ul>
        </fieldset>

        <div class="mt-6 rounded-xl bg-ink-50 px-4 py-4" data-export-summary aria-live="polite">
          <h3 class="text-sm font-medium text-ink-900">Podsumowanie</h3>
          <dl class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-5">
            <div><dt class="text-sm text-ink-500">Podstrony</dt><dd class="mt-1 text-xl font-semibold" x-text="total.pages">{{ count($pages) }}</dd></div>
            <div><dt class="text-sm text-ink-500">Sekcje</dt><dd class="mt-1 text-xl font-semibold" x-text="total.sections" data-export-total="sections">{{ array_sum(array_column($summaries, 'sections')) }}</dd></div>
            <div><dt class="text-sm text-ink-500">Pola</dt><dd class="mt-1 text-xl font-semibold" x-text="total.fields" data-export-total="fields">{{ array_sum(array_column($summaries, 'fields')) }}</dd></div>
            <div><dt class="text-sm text-ink-500">Zdjęcia</dt><dd class="mt-1 text-xl font-semibold" x-text="total.media" data-export-total="media">{{ array_sum(array_column($summaries, 'media')) }}</dd></div>
            <div><dt class="text-sm text-ink-500">Brakujące treści</dt><dd class="mt-1 text-xl font-semibold" x-bind:class="total.missing > 0 && 'text-warning-700'" x-text="total.missing" data-export-total="missing">{{ array_sum(array_column($summaries, 'missing')) }}</dd></div>
          </dl>
          <p class="mt-3 text-sm text-ink-600">
            Uzupełnione: <span x-text="total.filled"></span> ·
            nieuzupełnione: <span x-text="total.empty"></span> ·
            wyczyszczone: <span x-text="total.cleared"></span>
            <template x-if="total.media_links > 0"><span> · linki do zdjęć zamiast plików: <span x-text="total.media_links"></span></span></template>
          </p>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
          <x-ui.button type="submit" x-bind:disabled="!canSubmit" data-export-submit>
            @include('fillmein.partials.icon', ['name' => 'download', 'class' => 'size-4'])
            Eksportuj ZIP
          </x-ui.button>
          <p class="text-sm text-ink-500" x-show="!canSubmit" x-cloak>Zaznacz co najmniej jedną podstronę.</p>
        </div>
      </form>
    @endif
  </x-ui.card>

  @if ($latest)
    @php
      $missing = $latest->summary['missing'] ?? ['required_empty' => [], 'unmapped' => []];
    @endphp
    <x-ui.card class="mt-6">
      <x-slot:header>
        <h2 class="text-md font-semibold">Braki w ostatnim eksporcie</h2>
      </x-slot:header>

      <div class="flex flex-wrap gap-2">
        <x-ui.badge variant="warning">Wymagane puste: {{ $latest->summary['required_empty'] ?? 0 }}</x-ui.badge>
        <x-ui.badge variant="warning">Niezmapowane: {{ $latest->summary['unmapped'] ?? 0 }}</x-ui.badge>
      </div>

      @if ($missing['required_empty'] || $missing['unmapped'])
        <ul class="mt-3 divide-y divide-ink-100" role="list">
          @foreach ([...$missing['required_empty'], ...$missing['unmapped']] as $item)
            <li class="py-2 text-sm">
              <span class="font-medium text-ink-950">{{ $item['field'] }}</span>
              <span class="text-ink-500">· {{ $item['page'] }} · {{ $item['section'] }}</span>
              @if (isset($item['message']))
                <span class="block text-ink-700">{{ $item['message'] }}</span>
              @endif
            </li>
          @endforeach
        </ul>
      @else
        <p class="mt-3 text-base text-ink-600">Eksport nie ma braków.</p>
      @endif
    </x-ui.card>
  @endif

  <x-ui.card class="mt-6">
    <x-slot:header>
      <h2 class="text-md font-semibold">Historia eksportów</h2>
    </x-slot:header>

    @if ($history->isEmpty())
      <p class="text-base text-ink-600">Nie ma jeszcze eksportów.</p>
    @else
      <ul class="divide-y divide-ink-100" role="list" data-export-history>
        @foreach ($history as $export)
          @php
            [$variant, $label] = match ($export->status) {
              \App\Enums\ExportStatus::Completed => ['success', 'Gotowy'],
              \App\Enums\ExportStatus::Failed => ['danger', 'Błąd'],
              default => ['muted', 'W toku'],
            };
            $author = $export->user_id ? get_userdata($export->user_id) : null;
            $summary = $export->summary ?? [];
            $scope = $summary['scope'] ?? ['type' => 'project'];
            $scopeLabel = ($scope['type'] ?? 'project') === 'pages'
              ? 'Podstrony: ' . implode(', ', array_column($scope['pages'] ?? [], 'name'))
              : 'Cały projekt';
            $when = $export->created_at?->format('d.m.Y H:i:s');
            $hasContent = isset($summary['content_schema_version']);
          @endphp
          <li @class(['flex flex-wrap items-center justify-between gap-3 py-3', 'rounded-xl bg-success-50 px-3' => $ready === $export->uuid]) data-export-item="{{ $export->uuid }}">
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2 font-medium text-ink-950">
                <time datetime="{{ $export->created_at?->toIso8601String() }}">{{ $when }}</time>
                <x-ui.badge :variant="$variant">{{ $label }}</x-ui.badge>
              </p>
              <p class="text-sm text-ink-700" data-export-scope-label>{{ $scopeLabel }}</p>
              <p class="text-sm text-ink-500">
                {{ $author ? \App\Support\Staff::fullName($author) : 'Konto usunięte' }}
                @if (isset($summary['schema_version']))
                  · @if ($hasContent) format {{ $summary['content_schema_version'] }} · @endif wersja {{ $summary['schema_version'] }}
                  · {{ number_format(($summary['zip_size'] ?? $summary['size'] ?? 0) / 1024, 1, ',', ' ') }} KB
                @endif
                @if (isset($summary['counts']))
                  · {{ $counts($summary['counts']) }}
                @endif
              </p>
              @if ($export->error_message)
                <p class="text-sm text-ink-700">{{ $export->error_message }}</p>
              @endif
            </div>
            <div class="flex flex-wrap gap-2">
              @if ($export->status === \App\Enums\ExportStatus::Completed)
                <x-ui.button :href="route('fmi.projects.exports.download', [$project, $export->uuid, 'zip'])" :variant="$ready === $export->uuid ? 'primary' : 'secondary'" size="sm">
                  ZIP<span class="sr-only"> z {{ $when }}</span>
                </x-ui.button>
                @if ($hasContent)
                  <x-ui.button :href="route('fmi.projects.exports.download', [$project, $export->uuid, 'content'])" variant="secondary" size="sm">
                    content.json<span class="sr-only"> z {{ $when }}</span>
                  </x-ui.button>
                @endif
                <x-ui.button :href="route('fmi.projects.exports.download', [$project, $export->uuid, 'json'])" variant="ghost" size="sm">
                  JSON ACF<span class="sr-only"> z {{ $when }}</span>
                </x-ui.button>
              @endif
              <form method="POST" action="{{ route('fmi.projects.exports.regenerate', [$project, $export->uuid]) }}" data-loading-form>
                @csrf
                <x-ui.button type="submit" variant="ghost" size="sm" data-export-regenerate>
                  Wygeneruj ponownie<span class="sr-only"> ({{ $scopeLabel }}, {{ $when }})</span>
                </x-ui.button>
              </form>
            </div>
          </li>
        @endforeach
      </ul>
    @endif
  </x-ui.card>
@endsection
