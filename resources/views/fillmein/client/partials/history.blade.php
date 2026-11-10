{{-- Rewizje pola z przywracaniem. Dane: $field, $row, $value, $revisions, $editor, $forStaff. --}}
<div
  x-data="clientHistory({ field: {{ $field->id }}, row: @js($row?->uuid), version: {{ (int) $value?->version }} })"
  class="flex flex-col gap-3"
>
  <p aria-live="polite" class="text-sm" x-bind:class="error ? 'text-danger-700' : 'text-success-800'" x-text="message"></p>

  @if ($revisions->isEmpty())
    <x-ui.empty-state title="Brak historii" description="To pole nie ma jeszcze zapisanych wersji." />
  @else
    <ol class="flex flex-col gap-3">
      @foreach ($revisions as $revision)
        @php($preview = \App\Services\ContentService::display($field, $revision->value))
        <li class="rounded-xl border border-ink-150 bg-white p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-ink-500">
              <span class="font-medium text-ink-800">Wersja {{ $revision->version }}</span>
              · {{ $revision->created_at?->format('d.m.Y H:i') }}
              · {{ $editor->author($revision, $forStaff) }}
            </p>
            @if ($revision->version === (int) $value?->version)
              <x-ui.badge variant="success">Aktualna</x-ui.badge>
            @else
              <x-ui.button variant="secondary" size="sm" :aria-label="'Przywróć wersję ' . $revision->version" x-on:click="restore({{ $revision->id }})" x-bind:disabled="busy">Przywróć</x-ui.button>
            @endif
          </div>
          <p class="mt-2 text-sm break-words whitespace-pre-line text-ink-700">{{ $preview !== '' ? $preview : 'Puste pole' }}</p>
        </li>
      @endforeach
    </ol>
  @endif
</div>
