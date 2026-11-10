{{-- Status akceptacji: ostatnia rewizja projektu. Dane: $revision (?ReviewRevision). --}}
@if ($revision)
  <x-ui.badge :variant="$revision->status->badge()">{{ $revision->status->label() }} · {{ $revision->title }}</x-ui.badge>
@else
  <x-ui.badge variant="muted">Brak rewizji</x-ui.badge>
@endif
