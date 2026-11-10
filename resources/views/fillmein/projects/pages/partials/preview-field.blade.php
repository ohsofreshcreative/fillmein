{{-- Pole w podglądzie podstrony, do odczytu. Dane: $field, $row, $structure, $section, $assetUrl. Rodzic: Alpine `onlyMissing`. --}}
@php
  $label = $structure->label($field);
  $value = $structure->value($field, $row);
  $assets = $field->type->isMedia() ? $structure->assets($field, $row) : collect();
  $filled = $field->type->isMedia() ? $assets->isNotEmpty() : \App\Services\ContentService::hasContent($value?->value);
@endphp

@if (\App\Support\ClientLabels::isPlainGroup($field))
  @foreach ($structure->children($section, $field) as $child)
    @include('fillmein.projects.pages.partials.preview-field', ['field' => $child])
  @endforeach
@elseif ($field->type === \App\Enums\FieldType::Group)
  <div class="flex flex-col gap-4 rounded-xl border border-ink-150 p-4">
    <p class="text-sm font-semibold text-ink-900">{{ $label }}</p>
    @foreach ($structure->children($section, $field) as $child)
      @include('fillmein.projects.pages.partials.preview-field', ['field' => $child])
    @endforeach
  </div>
@elseif ($field->type === \App\Enums\FieldType::Repeater)
  @php($rows = $structure->rows($field, $row))
  <div class="flex flex-col gap-3">
    <p class="text-sm font-semibold text-ink-900">{{ $label }}</p>
    @forelse ($rows as $repeaterRow)
      <div @class([
        'flex flex-col gap-4 rounded-xl border border-l-4 p-3 sm:p-4',
        'border-ink-200 border-l-fresh-700 bg-white shadow-xs' => $repeaterRow->level() % 2 === 1,
        'border-ink-150 border-l-fresh-400 bg-ink-50' => $repeaterRow->level() % 2 === 0,
      ])>
        <p class="text-sm font-semibold text-ink-900">Element {{ $loop->iteration }}</p>
        @foreach ($structure->children($section, $field) as $child)
          @include('fillmein.projects.pages.partials.preview-field', ['field' => $child, 'row' => $repeaterRow])
        @endforeach
      </div>
    @empty
      <p class="rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-ink-800">Lista jest pusta.</p>
    @endforelse
  </div>
@else
  <div @if ($filled) x-show="! onlyMissing" @endif class="flex flex-col gap-1">
    <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink-600">
      {{ $label }}
      @if ($value?->review_requested_at)
        <x-ui.badge variant="warning">Do poprawy</x-ui.badge>
      @endif
    </p>
    <div class="min-w-0">
      @if (! $filled)
        <p class="rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-ink-800">
          Brak treści
          @if ($example = \App\Support\ClientLabels::example($field))
            <span class="block text-ink-500">W projekcie: {{ \Illuminate\Support\Str::after($example, 'np. ') }}</span>
          @endif
        </p>
      @else
        <div class="rounded-lg border border-success-200 bg-success-50 px-3 py-2">
          @if ($field->type->isMedia())
            <ul class="flex flex-wrap gap-2">
              @foreach ($assets as $asset)
                <li>
                  <img src="{{ $assetUrl($asset, 'thumb') }}" alt="{{ $asset->alt ?: $asset->original_name }}" loading="lazy" class="size-24 rounded-lg border border-ink-150 object-cover">
                </li>
              @endforeach
            </ul>
          @elseif ($field->type === \App\Enums\FieldType::Wysiwyg)
            <div class="client-wysiwyg text-base font-medium text-ink-950">{!! \App\Services\ContentService::cleanHtml($value->value) !!}</div>
          @else
            <p class="text-base font-medium break-words whitespace-pre-line text-ink-950">{{ \App\Services\ContentService::display($field, $value->value) }}</p>
          @endif

          @if ($value)
            <p class="mt-1 text-xs text-ink-600">
              {{ app(\App\Services\ContentReviewService::class)->author($value) }} · {{ $value->updated_at?->format('d.m.Y H:i') }}
            </p>
          @endif
        </div>
      @endif
    </div>
  </div>
@endif
