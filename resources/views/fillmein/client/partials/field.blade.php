{{-- Pole klienta. Dane: $field, $row (wiersz repeatera lub null), $structure, $section. --}}
@php
  $label = $structure->label($field);
  $example = \App\Support\ClientLabels::example($field);
  $value = $structure->value($field, $row);
  $uid = 'p-' . $field->id . ($row ? '-' . $row->uuid : '');
  $limit = (int) ($field->settings['max_length'] ?? 0) ?: null;
  $link = $field->type === \App\Enums\FieldType::Link ? (json_decode((string) $value?->value, true) ?: []) : [];
@endphp

@if (\App\Support\ClientLabels::isPlainGroup($field))
  <div data-field-id="{{ $field->id }}" class="flex flex-col gap-5">
    @foreach ($structure->children($section, $field) as $child)
      @include('fillmein.client.partials.field', ['field' => $child])
    @endforeach
  </div>
@elseif ($field->type === \App\Enums\FieldType::Group)
  <fieldset data-field-id="{{ $field->id }}" class="flex min-w-0 flex-col gap-5 rounded-xl border border-ink-150 p-4">
    <legend class="px-1 text-sm font-semibold text-ink-900">{{ $label }}</legend>
    @if ($field->instructions)
      <p class="text-sm text-ink-500">{{ $field->instructions }}</p>
    @endif
    @foreach ($structure->children($section, $field) as $child)
      @include('fillmein.client.partials.field', ['field' => $child])
    @endforeach
  </fieldset>
@elseif ($field->type === \App\Enums\FieldType::Repeater)
  @php($rows = $structure->rows($field, $row))
  <div
    data-field-id="{{ $field->id }}"
    x-data="clientRepeater({ field: {{ $field->id }}, row: @js($row?->uuid), version: {{ (int) $value?->version }}, max: {{ (int) ($field->settings['max_items'] ?? 0) }}, count: {{ $rows->count() }} })"
    class="flex flex-col gap-3"
  >
    <div>
      <h3 class="text-base font-semibold text-ink-900">{{ $label }}</h3>
      @if ($field->instructions)
        <p class="text-sm text-ink-500">{{ $field->instructions }}</p>
      @endif
    </div>

    <ul data-sortable-list class="flex flex-col gap-3">
      @foreach ($rows as $repeaterRow)
        @include('fillmein.client.partials.row', [
          'repeater' => $field,
          'row' => $repeaterRow,
          'number' => $loop->iteration,
          'first' => $loop->first,
          'last' => $loop->last,
          'collapsed' => $rows->count() > 1,
        ])
      @endforeach
    </ul>

    <p class="rounded-xl border border-dashed border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-600" x-show="count === 0" @if ($rows->isNotEmpty()) x-cloak @endif>Lista jest pusta. Dodaj pierwszy element.</p>
    <p class="text-sm text-ink-700" aria-live="polite" x-text="status"></p>

    <div>
      <x-ui.button
        variant="fresh"
        data-repeater-add
        :aria-label="'Dodaj element: ' . $label"
        x-on:click="add()"
        x-bind:disabled="busy || (max > 0 && count >= max)"
      ><span aria-hidden="true">+</span> Dodaj element</x-ui.button>
    </div>
  </div>
@elseif ($field->type->isMedia())
  @include('fillmein.client.partials.media')
@else
  <form
    data-field-id="{{ $field->id }}"
    x-data="clientField({ field: {{ $field->id }}, row: @js($row?->uuid), version: {{ (int) $value?->version }}, filled: @js(\App\Services\ContentService::hasContent($value?->value)) })"
    x-on:submit.prevent="flush()"
    x-on:input="changed()"
    x-on:focusout="left($event)"
    novalidate
    class="flex flex-col gap-2"
  >
    @switch($field->type)
      @case(\App\Enums\FieldType::Text)
        <x-ui.input
          name="value"
          :id="$uid"
          :label="$label"
          :value="$value?->value"
          :hint="$field->instructions"
          :required="$field->is_required"
          :maxlength="$limit"
          :placeholder="$field->settings['placeholder'] ?? $example"
          :aria-errormessage="$uid . '-status'"
          x-bind:aria-invalid="error ? 'true' : null"
        />
        @break

      @case(\App\Enums\FieldType::Textarea)
        <x-ui.textarea
          name="value"
          :id="$uid"
          :label="$label"
          :value="$value?->value"
          :hint="$field->instructions"
          :required="$field->is_required"
          :maxlength="$limit"
          :placeholder="$field->settings['placeholder'] ?? $example"
          :aria-errormessage="$uid . '-status'"
          x-bind:aria-invalid="error ? 'true' : null"
        />
        @break

      @case(\App\Enums\FieldType::Wysiwyg)
        <div x-data="clientWysiwyg()" class="flex flex-col gap-1.5">
          <span id="{{ $uid }}-label" class="text-sm font-medium text-ink-800">
            {{ $label }}
            @if ($field->is_required) <span class="text-danger-600" aria-hidden="true">*</span> @endif
          </span>
          <div class="overflow-hidden rounded-lg border border-ink-200 bg-white shadow-xs">
            <div role="toolbar" aria-label="Formatowanie: {{ $label }}" class="flex gap-1 border-b border-ink-150 bg-ink-50 p-1">
              <x-ui.button variant="ghost" size="sm" aria-label="Pogrubienie" x-on:mousedown.prevent x-on:click="format('bold')"><strong>B</strong></x-ui.button>
              <x-ui.button variant="ghost" size="sm" aria-label="Kursywa" x-on:mousedown.prevent x-on:click="format('italic')"><em>I</em></x-ui.button>
              <x-ui.button variant="ghost" size="sm" aria-label="Lista punktowana" x-on:mousedown.prevent x-on:click="format('insertUnorderedList')">Lista</x-ui.button>
            </div>
            <div
              x-ref="editor"
              contenteditable="true"
              role="textbox"
              aria-multiline="true"
              aria-labelledby="{{ $uid }}-label"
              @if ($field->instructions) aria-describedby="{{ $uid }}-hint" @endif
              x-on:input="sync()"
              class="client-wysiwyg min-h-32 px-3 py-2.5 text-base text-ink-900 focus:outline-2 focus:outline-offset-2 focus:outline-ink-900"
            >{!! \App\Services\ContentService::cleanHtml($value?->value) !!}</div>
          </div>
          <input type="hidden" name="value" x-ref="input" value="{{ $value?->value }}">
          @if ($field->instructions)
            <p id="{{ $uid }}-hint" class="text-sm text-ink-500">{{ $field->instructions }}</p>
          @endif
        </div>
        @break

      @case(\App\Enums\FieldType::Link)
        <fieldset class="flex flex-col gap-3">
          <legend class="text-sm font-medium text-ink-800">
            {{ $label }}
            @if ($field->is_required) <span class="text-danger-600" aria-hidden="true">*</span> @endif
          </legend>
          @if ($field->instructions)
            <p class="text-sm text-ink-500">{{ $field->instructions }}</p>
          @endif
          <x-ui.input name="label" :id="$uid . '-label'" label="Tekst linku" :value="$link['label'] ?? ''" maxlength="500" :placeholder="$example" />
          <x-ui.input
            name="url"
            :id="$uid . '-url'"
            label="Adres"
            type="url"
            :value="$link['url'] ?? ''"
            maxlength="2000"
            placeholder="https://"
            :aria-errormessage="$uid . '-status'"
            x-bind:aria-invalid="error ? 'true' : null"
          />
        </fieldset>
        @break
    @endswitch

    <div x-show="conflict" x-cloak role="group" aria-label="Konflikt zapisu: {{ $label }}" class="flex flex-col gap-3 rounded-xl border border-warning-200 bg-warning-50 p-3">
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="min-w-0">
          <p class="text-sm font-medium text-ink-800">Twoja wersja</p>
          <p class="mt-1 text-sm break-words whitespace-pre-line text-ink-700" x-text="conflict?.mine"></p>
        </div>
        <div class="min-w-0">
          <p class="text-sm font-medium text-ink-800">Wersja zapisana <span x-text="conflict?.updated_at ? 'o ' + conflict.updated_at : ''"></span></p>
          <p class="mt-1 text-sm break-words whitespace-pre-line text-ink-700" x-text="conflict?.display"></p>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <x-ui.button variant="secondary" size="sm" x-on:click="keepMine()">Zachowaj moją</x-ui.button>
        <x-ui.button variant="secondary" size="sm" x-on:click="takeTheirs()">Przyjmij zapisaną</x-ui.button>
        <x-ui.button variant="ghost" size="sm" x-on:click="mergeManually()">Scal ręcznie</x-ui.button>
      </div>
    </div>

    <div x-show="theirs" x-cloak class="rounded-xl bg-ink-50 p-3">
      <p class="text-sm font-medium text-ink-800">Wersja zapisana, do scalenia</p>
      <p class="mt-1 text-sm break-words whitespace-pre-line text-ink-700" x-text="theirs"></p>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-3">
      <span
        data-field-filled
        x-bind:data-filled="filled ? 'true' : 'false'"
        class="mr-auto inline-flex items-center gap-1.5 text-sm"
        x-bind:class="filled ? 'font-medium text-success-800' : 'text-ink-500'"
      ><span aria-hidden="true" class="size-2 rounded-full" x-bind:class="filled ? 'bg-success-600' : 'bg-ink-300'"></span><span x-text="filled ? 'Uzupełnione' : 'Do uzupełnienia'"></span></span>
      <p
        id="{{ $uid }}-status"
        aria-live="polite"
        class="min-w-0 text-sm"
        x-bind:class="error ? 'text-danger-700' : 'text-success-800'"
        x-text="message"
      ></p>
      @if ($historyUrl)
        <a href="{{ $historyUrl($field, $row) }}" aria-label="Historia: {{ $label }}" class="text-sm font-medium text-ink-700 underline decoration-ink-300 underline-offset-2 hover:text-ink-950 hover:decoration-ink-900">Historia</a>
      @endif
    </div>
  </form>
@endif
