{{-- Pole zdjęcia lub galerii. Dane: $field, $row, $structure, $assetUrl. --}}
@php
  $assets = $structure->assets($field, $row);
  $value = $structure->value($field, $row);
  $gallery = $field->type === \App\Enums\FieldType::Gallery;
  $inputId = 'p-' . $field->id . ($row ? '-' . $row->uuid : '') . '-file';
  $mediaLink = \App\Services\ContentService::mediaLink($value);
  $requirements = array_filter([
    ($field->settings['min_width'] ?? null) || ($field->settings['min_height'] ?? null)
      ? 'Minimalny rozmiar: ' . (int) ($field->settings['min_width'] ?? 0) . ' × ' . (int) ($field->settings['min_height'] ?? 0) . ' px.'
      : null,
    $gallery && ($field->settings['max_items'] ?? null) ? 'Najwyżej ' . (int) $field->settings['max_items'] . ' zdjęć.' : null,
  ]);
@endphp

<div
  x-data="clientMedia({ field: {{ $field->id }}, row: @js($row?->uuid), version: {{ (int) $value?->version }}, max: {{ (int) ($field->settings['max_items'] ?? 0) }}, count: {{ $assets->count() }}, single: @js(! $gallery), link: @js($mediaLink ?? '') })"
  data-media-field
  data-field-id="{{ $field->id }}"
  x-bind:data-filled="count > 0 || link ? 'true' : 'false'"
  class="flex flex-col gap-3"
>
  <div>
    <label for="{{ $inputId }}" class="text-sm font-medium text-ink-800">
      {{ $label }}
      @if ($field->is_required) <span class="text-danger-600" aria-hidden="true">*</span> @endif
    </label>
    @if ($field->instructions)
      <p class="text-sm text-ink-500">{{ $field->instructions }}</p>
    @endif
  </div>

  <ul data-sortable-list class="flex flex-col gap-3">
    @foreach ($assets as $asset)
      @include('fillmein.client.partials.asset', ['first' => $loop->first, 'last' => $loop->last])
    @endforeach
  </ul>

  {{-- Natywne pole pliku jest ukryte: okno wyboru otwiera tylko „Przeglądaj…”, a kliknięcie w opis wyłącznie
       zaznacza pole (fokus → podświetlenie na podglądzie). --}}
  <input
    id="{{ $inputId }}"
    type="file"
    accept="image/jpeg,image/png,image/webp,image/gif"
    aria-describedby="{{ $inputId }}-hint"
    @if ($gallery) multiple @endif
    x-ref="file"
    x-on:change="upload($el)"
    x-bind:disabled="uploading || full"
    class="sr-only"
    tabindex="-1"
  >
  <div class="flex min-w-0 items-center overflow-hidden rounded-lg border border-ink-200 bg-white shadow-xs">
    <button
      type="button"
      data-media-browse
      class="h-10 shrink-0 border-r border-ink-200 bg-ink-100 px-3 text-sm font-medium text-ink-800 transition hover:bg-ink-150 focus:ring-focus disabled:cursor-not-allowed disabled:opacity-60"
      aria-controls="{{ $inputId }}"
      x-on:click="$refs.file.click()"
      x-bind:disabled="uploading || full"
    >{{ $gallery ? 'Przeglądaj… (wiele plików)' : 'Przeglądaj…' }}</button>
    <span
      tabindex="-1"
      data-media-summary
      class="min-w-0 flex-1 cursor-default truncate px-3 text-sm text-ink-600 outline-none"
      x-on:click="$el.focus()"
      x-text="uploading ? 'Przesyłanie…' : (count > 0 ? (single ? 'Dodano zdjęcie' : `Dodano zdjęć: ${count}`) : (link ? 'Zdjęcie z linku' : 'Nie wybrano pliku'))"
    >{{ $assets->isNotEmpty() ? ($gallery ? 'Dodano zdjęć: ' . $assets->count() : 'Dodano zdjęcie') : ($mediaLink ? 'Zdjęcie z linku' : 'Nie wybrano pliku') }}</span>
  </div>
  <p id="{{ $inputId }}-hint" class="text-sm text-ink-500">JPG, PNG, WebP lub GIF, do {{ (int) (config('fillmein.assets.max_size_kb') / 1024) }} MB. {{ implode(' ', $requirements) }}</p>

  <div class="flex flex-col gap-1.5" data-media-link>
    <label for="{{ $inputId }}-link" class="text-sm font-medium text-ink-800">{{ $gallery ? 'Albo dodaj link do zdjęć' : 'Albo wklej link do zdjęcia' }} <span class="font-normal text-ink-500">(np. ze stocka)</span></label>
    <div class="flex min-w-0 gap-2">
      <input
        id="{{ $inputId }}-link"
        type="url"
        inputmode="url"
        maxlength="2000"
        placeholder="https://"
        x-model="linkDraft"
        x-on:change="saveLink()"
        x-on:keydown.enter.prevent="saveLink()"
        class="h-10 w-full min-w-0 flex-1 rounded-lg border border-ink-200 bg-white px-3 text-base text-ink-900 shadow-xs transition duration-150 placeholder:text-ink-400 hover:border-ink-300 focus:ring-focus"
      >
      <a x-show="link" x-cloak x-bind:href="link" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 shrink-0 items-center rounded-lg px-3 text-sm font-medium text-ink-700 transition hover:bg-ink-100 hover:text-ink-950 focus:ring-focus">Otwórz</a>
      <x-ui.button variant="ghost" size="sm" class="h-10 shrink-0" x-show="link" x-cloak x-on:click="removeLink()" aria-label="Usuń link do zdjęcia">Usuń</x-ui.button>
    </div>
    @unless ($gallery)
      <p class="text-sm text-ink-500">Plik albo link — jedno zastępuje drugie.</p>
    @endunless
  </div>

  <div x-show="uploading" x-cloak data-upload-progress>
    <x-ui.progress size="sm" name="Postęp przesyłania" :value="0" />
  </div>

  <p class="text-sm text-ink-700" aria-live="polite" x-text="status"></p>
</div>
