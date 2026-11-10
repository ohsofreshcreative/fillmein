{{-- Zdjęcie w polu. Dane: $field, $row, $asset, $assetUrl, $first, $last. --}}
@php
  $altId = 'alt-' . $field->id . '-' . $asset->uuid . ($row ? '-' . $row->uuid : '');
  $requireAlt = ! empty($field->settings['require_alt']);
@endphp

<li data-sortable-item="{{ $asset->uuid }}" class="flex flex-col gap-3 rounded-xl border border-ink-150 bg-ink-50 p-3 sm:flex-row">
  <img src="{{ $assetUrl($asset, 'thumb') }}" alt="{{ $asset->alt ?? '' }}" width="120" height="120" class="size-30 shrink-0 rounded-lg bg-ink-100 object-cover">

  <div class="flex min-w-0 grow flex-col gap-2">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="min-w-0 truncate text-sm font-medium text-ink-800">{{ $asset->original_name }}</p>
      <div class="flex items-center gap-1">
        @if ($field->type === \App\Enums\FieldType::Gallery)
          <x-ui.sort-controls :label="'zdjęcie ' . $asset->original_name" :first="$first" :last="$last" />
        @endif
        <x-ui.button variant="ghost" size="sm" :aria-label="'Odepnij zdjęcie ' . $asset->original_name" x-on:click="detach($el)">Odepnij</x-ui.button>
      </div>
    </div>

    <x-ui.input
      name="alt"
      :id="$altId"
      label="Tekst alternatywny"
      :value="$asset->alt"
      :required="$requireAlt"
      maxlength="500"
      hint="Krótki opis zdjęcia dla osób, które go nie widzą."
      x-on:change="saveAlt($el)"
    />
  </div>
</li>
