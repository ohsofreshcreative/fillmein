{{-- Dodatkowe uwagi pod sekcją. Dane: $section, $note (SectionNote lub null). Zwinięte do przycisku „+”, dopóki puste. --}}
@php
  $noteBody = (string) $note?->body;
  $noteId = 'notatka-' . $section->id;
@endphp
<div
  class="flex flex-col gap-2 border-t border-dashed border-ink-200 pt-4"
  data-section-note
  x-data="clientSectionNote({ section: {{ $section->id }}, version: {{ (int) $note?->version }}, open: @js($noteBody !== '') })"
>
  <button
    type="button"
    x-show="! open"
    @if ($noteBody !== '') x-cloak @endif
    x-on:click="show()"
    data-section-note-add
    class="inline-flex items-center gap-2 self-start rounded-lg px-2 py-1.5 text-sm font-medium text-ink-600 transition hover:bg-ink-100 hover:text-ink-950 focus:ring-focus"
  ><span aria-hidden="true" class="flex size-6 items-center justify-center rounded-full border border-ink-300 text-base leading-none">+</span> Dodaj uwagi lub dodatkowe treści</button>

  <div x-show="open" @if ($noteBody === '') x-cloak @endif class="flex flex-col gap-1.5">
    <label for="{{ $noteId }}" class="text-sm font-medium text-ink-800">Dodatkowe uwagi lub treści do tej sekcji</label>
    <textarea
      id="{{ $noteId }}"
      x-ref="body"
      rows="4"
      maxlength="10000"
      placeholder="Np. czego brakuje w projekcie, co zmienić albo dodatkowy tekst, który chcesz tu umieścić."
      x-on:input="changed()"
      x-on:blur="flush()"
      class="w-full rounded-lg border border-ink-200 bg-white px-3 py-2.5 text-base text-ink-900 shadow-xs transition duration-150 placeholder:text-ink-400 hover:border-ink-300 focus:ring-focus"
    >{{ $noteBody }}</textarea>
    <p class="text-sm" aria-live="polite" x-bind:class="error ? 'text-danger-700' : 'text-success-800'" x-text="message"></p>
  </div>
</div>
