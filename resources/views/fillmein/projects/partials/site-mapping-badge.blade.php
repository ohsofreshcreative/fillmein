{{-- Odznaka dopasowania do schematu strony. Dane: $entry (status, confidence). --}}
@php
  $percent = (int) round(($entry['confidence'] ?? 0) * 100) . '%';
  [$variant, $text] = match ($entry['status']) {
    'green' => ['success', 'Pewne ' . $percent],
    'manual' => ['fresh', 'Ręcznie'],
    'yellow' => ['warning', 'Do sprawdzenia ' . $percent],
    'stale' => ['danger', 'Nieaktualne'],
    'skipped' => ['muted', 'Nie importuj'],
    'transparent' => ['muted', 'Bez grupy w ACF'],
    'hidden' => ['muted', 'Ukryta — pomijana'],
    'new' => ['neutral', 'Nowa strona'],
    default => ['danger', ($entry['key'] ?? $entry['block'] ?? $entry['target'] ?? null) ? 'Niepewne ' . $percent : 'Brak pary'],
  };
@endphp
<x-ui.badge :variant="$variant" data-site-status="{{ $entry['status'] }}">{{ $text }}</x-ui.badge>
