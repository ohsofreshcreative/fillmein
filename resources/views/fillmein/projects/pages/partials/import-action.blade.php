{{-- Etykieta operacji w podglądzie importu z Figmy. „Bez zmian” nie ma odznaki — wyróżniamy tylko to, co się zmienia. --}}
@php
  [$variant, $text] = match ($action) {
    'create' => ['fresh', 'Nowe'],
    'update' => ['warning', 'Zmienione'],
    'restore' => ['success', 'Przywrócone z archiwum'],
    'archive' => ['danger', 'Do archiwum'],
    'keep' => ['neutral', 'Zachowane'],
    'skipped' => ['muted', 'Pominięte (element ręczny)'],
    default => [null, null],
  };
@endphp
@if ($text)
  <x-ui.badge :variant="$variant">{{ $text }}</x-ui.badge>
@endif
