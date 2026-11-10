{{-- Etykieta operacji w podglądzie importu z Figmy. --}}
@php
  [$variant, $text] = match ($action) {
    'create' => ['fresh', 'Nowe'],
    'update' => ['warning', 'Zmienione'],
    'restore' => ['success', 'Przywrócone z archiwum'],
    'archive' => ['danger', 'Do archiwum'],
    'keep' => ['neutral', 'Zachowane'],
    'skipped' => ['muted', 'Pominięte (element ręczny)'],
    default => ['muted', 'Bez zmian'],
  };
@endphp
<x-ui.badge :variant="$variant">{{ $text }}</x-ui.badge>
