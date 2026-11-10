{{-- Dziennik aktywności projektu. Dane: $activity (kolekcja App\Models\Activity). --}}
@if ($activity->isEmpty())
  <p class="text-base text-ink-500">Brak zapisanych zdarzeń.</p>
@else
  <ol class="flex flex-col gap-3">
    @foreach ($activity as $entry)
      @php($context = \App\Support\ActivityPresenter::context($entry))
      <li class="flex gap-3 border-b border-ink-150 pb-3 last:border-0 last:pb-0">
        <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-fresh-400" aria-hidden="true"></span>
        <div class="min-w-0">
          <p class="text-base text-ink-900">
            {{ \App\Support\ActivityPresenter::label($entry) }}
            @if ($context)
              <span class="text-ink-500">· {{ $context }}</span>
            @endif
          </p>
          <p class="mt-0.5 text-sm text-ink-500">
            {{ \App\Support\ActivityPresenter::actor($entry) }}
            · <time datetime="{{ $entry->created_at?->toIso8601String() }}">{{ $entry->created_at?->format('d.m.Y H:i') }}</time>
          </p>
        </div>
      </li>
    @endforeach
  </ol>
@endif
