{{-- Nieaktywna „makieta” pola tak, jak zobaczy je klient w edycji treści. Dane: $type (FieldType), $hint (tekst z Figmy lub null). --}}
@php
  $hint = filled($hint ?? null) ? 'np. ' . \Illuminate\Support\Str::limit($hint, 140) : null;
  $box = 'mt-2 rounded-lg border border-ink-150 bg-ink-50 px-3 text-sm text-ink-400';
@endphp
@switch($type)
  @case(\App\Enums\FieldType::Textarea)
  @case(\App\Enums\FieldType::Wysiwyg)
    <div class="{{ $box }} min-h-20 py-2.5" aria-hidden="true">{{ $hint }}</div>
    @break
  @case(\App\Enums\FieldType::Image)
  @case(\App\Enums\FieldType::Gallery)
    <div class="{{ $box }} flex h-16 items-center justify-center border-dashed" aria-hidden="true">{{ $type === \App\Enums\FieldType::Gallery ? 'Miejsce na zdjęcia' : 'Miejsce na zdjęcie' }}</div>
    @break
  @case(\App\Enums\FieldType::Link)
    <div class="mt-2 grid gap-2 sm:grid-cols-2" aria-hidden="true">
      <div class="{{ $box }} mt-0 truncate py-2.5">{{ $hint ?? 'Tekst linku' }}</div>
      <div class="{{ $box }} mt-0 py-2.5">https://…</div>
    </div>
    @break
  @default
    <div class="{{ $box }} truncate py-2.5" aria-hidden="true">{{ $hint ?? '' }}&nbsp;</div>
@endswitch
