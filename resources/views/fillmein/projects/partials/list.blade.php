{{--
  Lista projektów (kolekcja Project). Opcjonalnie $progress — tablica
  [project_id => ['percent' => int, ...]] z App\Support\ProjectProgress.
--}}
@php($progress = $progress ?? [])

<ul class="divide-y divide-ink-150 overflow-hidden rounded-2xl border border-ink-150 bg-white shadow-xs">
  @foreach ($projects as $project)
    <li>
      <a href="{{ route('fmi.projects.show', $project) }}" class="flex items-center justify-between gap-4 px-5 py-4 transition duration-150 hover:bg-ink-50 sm:px-6">
        <div class="min-w-0">
          <p class="truncate font-medium text-ink-950">{{ $project->name }}</p>
          <p class="mt-0.5 truncate text-sm text-ink-500">
            {{ $project->client_name ?: 'Bez nazwy klienta' }} · aktualizacja {{ $project->updated_at?->diffForHumans() }}
          </p>
        </div>

        <div class="flex shrink-0 items-center gap-4">
          @if (isset($progress[$project->id]) && $progress[$project->id]['total'] > 0)
            <x-ui.progress
              class="hidden w-28 sm:flex"
              size="sm"
              :name="'Postęp wypełnienia: ' . $project->name"
              :value="$progress[$project->id]['percent']"
              :hint="$progress[$project->id]['percent'] . '%'"
            />
          @endif
          <x-ui.badge :variant="$project->status->badge()">{{ $project->status->label() }}</x-ui.badge>
        </div>
      </a>
    </li>
  @endforeach
</ul>
