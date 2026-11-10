{{-- Wgrywanie schematu strony WordPress (wtyczka Fill Me In Sync). Dane: $project, $schema (SiteSchema|null), $compact (bool). --}}
@php
  $document = $schema?->document ?? [];
@endphp
<x-ui.card :class="$class ?? ''" data-site-schema>
  <x-slot:header>
    <div class="min-w-0">
      <h2 class="text-md font-semibold">Schemat strony (WordPress)</h2>
      @if ($schema)
        <p class="mt-0.5 text-sm text-ink-500 break-all">
          {{ $schema->site_name ? $schema->site_name . ' · ' : '' }}{{ $schema->site_url }}
          · wgrano {{ $schema->uploaded_at?->format('d.m.Y H:i') }}
          · bloki: {{ count($document['blocks'] ?? []) }} · strony: {{ count($document['pages'] ?? []) }}
        </p>
      @else
        <p class="mt-0.5 text-sm text-ink-500">Bez schematu eksport używa nazw z Figmy. Ze schematem sekcje i pola są dopasowane do bloków ACF zakodowanego motywu.</p>
      @endif
    </div>
  </x-slot:header>

  <form method="POST" action="{{ route('fmi.projects.site-schema.upload', $project) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3" data-loading-form>
    @csrf
    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
      <label for="site-schema-file" class="text-sm font-medium text-ink-800">{{ $schema ? 'Wgraj nową wersję schematu' : 'Wgraj schemat strony (WordPress)' }}</label>
      <input
        id="site-schema-file"
        type="file"
        name="schema"
        accept=".json,application/json"
        required
        aria-describedby="site-schema-hint"
        @if ($errors->has('schema')) aria-invalid="true" @endif
        class="block w-full text-sm text-ink-700 file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-ink-900 hover:file:bg-ink-150"
      >
      @if ($errors->has('schema'))
        <p class="text-sm text-danger-700">{{ $errors->first('schema') }}</p>
      @else
        <p id="site-schema-hint" class="text-sm text-ink-500">Plik JSON z polecenia <code>wp fmi-sync schema</code>. Ponowne wgranie zachowuje ręczne poprawki dopasowania.</p>
      @endif
    </div>
    <x-ui.button type="submit" :variant="$schema ? 'secondary' : 'primary'" size="sm">{{ $schema ? 'Wgraj nową wersję' : 'Wgraj schemat' }}</x-ui.button>
  </form>
</x-ui.card>
