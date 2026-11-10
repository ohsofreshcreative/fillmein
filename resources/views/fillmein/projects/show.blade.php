@extends('fillmein.layouts.app')

@section('title', $project->name)

@section('content')
  <a href="{{ route('fmi.projects.index') }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    Treści
  </a>

  <x-ui.page-header :eyebrow="$project->client_name ?: 'Projekt'" :title="$project->name">
    <x-slot:actions>
      @foreach ($project->modules() as $module)
        <x-ui.badge variant="neutral">{{ $module->label() }}</x-ui.badge>
      @endforeach
      <x-ui.badge :variant="$project->status->badge()">{{ $project->status->label() }}</x-ui.badge>
      @if ($can['update'])
        <x-ui.button :href="route('fmi.projects.edit', $project)" variant="secondary" size="sm">Ustawienia</x-ui.button>
      @endif
    </x-slot:actions>
  </x-ui.page-header>

  <div class="mt-8 grid items-start gap-6 lg:grid-cols-3">
    {{-- Lewa kolumna: struktura i podstrony --}}
    <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
      @if ($project->usesModule(\App\Enums\ProjectModule::Content))
        <x-ui.card>
          <x-slot:header>
            <h2 class="text-md font-semibold">Struktura</h2>
          </x-slot:header>

          <dl class="grid grid-cols-3 gap-4">
            <div><dt class="text-sm text-ink-500">Podstrony</dt><dd class="mt-1 text-xl font-semibold">{{ $project->pages_count }}</dd></div>
            <div><dt class="text-sm text-ink-500">Sekcje</dt><dd class="mt-1 text-xl font-semibold">{{ $project->sections_count }}</dd></div>
            <div><dt class="text-sm text-ink-500">Pola</dt><dd class="mt-1 text-xl font-semibold">{{ $project->fields_count }}</dd></div>
          </dl>

          <x-ui.progress
            class="mt-6"
            label="Postęp wypełnienia treści"
            :value="$progress['percent']"
            :hint="\App\Support\ProjectProgress::hint($progress)"
          />

          <div class="mt-6 flex items-center gap-3 rounded-xl bg-ink-50 px-4 py-3 text-sm">
            @include('fillmein.partials.icon', ['name' => 'figma', 'class' => 'text-ink-500'])
            @if ($project->figma_file_url)
              <a href="{{ $project->figma_file_url }}" target="_blank" rel="noopener noreferrer" class="truncate font-medium text-ink-900 underline decoration-ink-300 underline-offset-2 hover:decoration-ink-900">
                {{ $project->figma_file_name ?: $project->figma_file_url }}
              </a>
            @else
              <span class="text-ink-500">Plik Figmy nie jest jeszcze podłączony.</span>
            @endif
          </div>
        </x-ui.card>

        <x-ui.card>
          <x-slot:header>
            <div>
              <h2 class="text-md font-semibold">Podstrony</h2>
              <p class="mt-0.5 text-sm text-ink-500">Każda podstrona ma własny link do jednego frame'u Figmy.</p>
            </div>
          </x-slot:header>

          @if ($tree === [])
            <x-ui.empty-state
              title="Brak podstron"
              description="Dodaj pierwszą podstronę i wskaż frame Figmy, z którego pochodzi."
            />
          @else
            @include('fillmein.projects.partials.page-tree', [
              'nodes' => $tree,
              'project' => $project,
              'depth' => 0,
              'canManage' => $can['managePages'],
            ])
          @endif

          @if ($can['managePages'])
            <details class="mt-6 rounded-xl border border-ink-150 bg-ink-50 px-4 py-3">
              <summary class="cursor-pointer text-sm font-medium text-ink-800">Dodaj podstronę</summary>

              <form method="POST" action="{{ route('fmi.projects.pages.store', $project) }}" class="mt-4 flex flex-col gap-4" data-loading-form>
                @csrf
                <x-ui.input name="name" label="Nazwa podstrony" required maxlength="190" />
                <x-ui.input name="slug" label="Slug" maxlength="190" hint="Opcjonalnie — domyślnie powstaje z nazwy. Musi być unikalny w obrębie podstrony nadrzędnej." />
                <x-ui.select name="parent_id" label="Podstrona nadrzędna" :options="$pageOptions" placeholder="— poziom główny —" />
                <x-ui.input
                  name="figma_frame_url"
                  label="Link do frame'u Figmy"
                  type="url"
                  maxlength="500"
                  hint="Adres musi wskazywać konkretny frame (zawierać parametr node-id)."
                />
                <x-ui.checkbox name="client_can_duplicate" label="Klient może duplikować tę podstronę" />

                <div class="flex justify-end">
                  <x-ui.button type="submit">Dodaj podstronę</x-ui.button>
                </div>
              </form>
            </details>
          @endif

          @if ($archivedPages->isNotEmpty())
            <details class="mt-4 rounded-xl border border-ink-150 px-4 py-3">
              <summary class="cursor-pointer text-sm font-medium text-ink-800">
                Zarchiwizowane podstrony ({{ $archivedPages->count() }})
              </summary>

              <ul class="mt-4 flex flex-col gap-2">
                @foreach ($archivedPages as $archived)
                  <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-ink-50 px-3 py-2">
                    <span class="min-w-0">
                      <span class="font-medium text-ink-800">{{ $archived->name }}</span>
                      <span class="ml-2 font-mono text-xs text-ink-500">/{{ $archived->slug }}</span>
                    </span>
                    @if ($can['managePages'])
                      <form method="POST" action="{{ route('fmi.projects.pages.restore', [$project, $archived]) }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" size="sm">Przywróć</x-ui.button>
                      </form>
                    @endif
                  </li>
                @endforeach
              </ul>
            </details>
          @endif
        </x-ui.card>

        @if ($can['manageClientAccess'])
          @include('fillmein.projects.partials.client-access')
        @endif

        @if ($can['editContent'])
          <x-ui.card>
            <x-slot:header>
              <h2 class="text-md font-semibold">Przegląd treści</h2>
              <x-ui.button :href="route('fmi.projects.review', $project)" variant="secondary" size="sm">Otwórz przegląd</x-ui.button>
            </x-slot:header>

            <p class="text-base text-ink-600">Status pól, autor ostatniej zmiany i oznaczenia do poprawy.</p>
          </x-ui.card>
        @endif

        @if ($can['export'])
          <x-ui.card>
            <x-slot:header>
              <h2 class="text-md font-semibold">Eksport</h2>
              <x-ui.button :href="route('fmi.projects.exports', $project)" variant="secondary" size="sm">Eksport</x-ui.button>
            </x-slot:header>

            <p class="text-base text-ink-600">Paczka ZIP (content.json i media/) całego projektu lub wybranych podstron oraz <a href="{{ route('fmi.projects.mapping', $project) }}" class="font-medium underline">mapowanie ACF</a>.</p>
          </x-ui.card>
        @endif
      @else
        <x-ui.card>
          <x-slot:header>
            <h2 class="text-md font-semibold">Treści</h2>
          </x-slot:header>
          <p class="text-base text-ink-600">Moduł Treści jest wyłączony w tym projekcie. Włączysz go w ustawieniach projektu; wcześniej zebrane treści zostają zachowane.</p>
        </x-ui.card>
      @endif

      @if ($can['viewActivity'])
        <x-ui.card>
          <x-slot:header>
            <h2 class="text-md font-semibold">Aktywność</h2>
            <x-ui.button :href="route('fmi.projects.activity', $project)" variant="ghost" size="sm">Cały dziennik</x-ui.button>
          </x-slot:header>

          @include('fillmein.projects.partials.activity', ['activity' => $activity])
        </x-ui.card>
      @endif
    </div>

    {{-- Prawa kolumna: zespół, status, operacje --}}
    <div class="flex min-w-0 flex-col gap-6">
      <x-ui.card>
        <x-slot:header>
          <h2 class="text-md font-semibold">Zespół</h2>
        </x-slot:header>

        <dl class="flex flex-col gap-4 text-base">
          <div>
            <dt class="text-sm text-ink-500">Opiekun</dt>
            <dd class="mt-1">@include('fillmein.partials.user', ['person' => $manager])</dd>
          </div>
          <div>
            <dt class="text-sm text-ink-500">Autor</dt>
            <dd class="mt-1">@include('fillmein.partials.user', ['person' => $author])</dd>
          </div>
          <div>
            <dt class="text-sm text-ink-500">Przypisani pracownicy</dt>
            <dd class="mt-1 flex flex-col gap-2">
              @forelse ($members as $member)
                @include('fillmein.partials.user', ['person' => $member])
              @empty
                <span class="text-ink-500">Brak</span>
              @endforelse
            </dd>
          </div>
        </dl>

        @if ($can['manageMembers'])
          <form method="POST" action="{{ route('fmi.projects.members', $project) }}" class="mt-5 border-t border-ink-150 pt-5" data-loading-form>
            @csrf
            <fieldset>
              <legend class="text-sm font-medium text-ink-800">Dodatkowi pracownicy</legend>
              <p class="mt-1 text-sm text-ink-500">Autor i opiekun mają dostęp zawsze — nie trzeba ich tu zaznaczać.</p>

              @error('member_ids')
                <p class="mt-2 text-sm text-danger-700">{{ $message }}</p>
              @enderror

              <div class="mt-3 flex max-h-64 flex-col gap-2 overflow-y-auto">
                @foreach ($staff as $id => $label)
                  @continue($id === $project->author_id || $id === $project->manager_id)
                  <x-ui.checkbox
                    name="member_ids[]"
                    :value="$id"
                    :label="$label"
                    :checked="$members->contains(fn ($person) => $person->ID === $id)"
                  />
                @endforeach
              </div>

              <div class="mt-4 flex justify-end">
                <x-ui.button type="submit" variant="secondary" size="sm">Zapisz zespół</x-ui.button>
              </div>
            </fieldset>
          </form>
        @endif

        <x-slot:footer>
          <p class="text-xs text-ink-500">
            Utworzono {{ $project->created_at?->format('d.m.Y H:i') }} · zaktualizowano {{ $project->updated_at?->format('d.m.Y H:i') }}
          </p>
        </x-slot:footer>
      </x-ui.card>

      @if ($can['update'])
        <x-ui.card>
          <x-slot:header>
            <h2 class="text-md font-semibold">Status</h2>
          </x-slot:header>

          <form method="POST" action="{{ route('fmi.projects.status', $project) }}" class="flex flex-col gap-4" data-loading-form>
            @csrf
            <x-ui.select name="status" label="Status projektu" :options="$statuses" :value="$project->status->value" required />
            <div class="flex justify-end">
              <x-ui.button type="submit" variant="secondary" size="sm">Zmień status</x-ui.button>
            </div>
          </form>
        </x-ui.card>
      @endif

      @if ($can['delete'])
        <x-ui.card>
          <x-slot:header>
            <h2 class="text-md font-semibold text-danger-800">Usunięcie projektu</h2>
          </x-slot:header>

          <p class="text-base text-ink-600">
            Projekt zniknie z listy, a aktywne linki klientów przestaną działać.
            Dane pozostają w bazie — przywrócenie wymaga pomocy administratora.
          </p>

          <div class="mt-4 flex justify-end">
            <x-ui.confirm
              :action="route('fmi.projects.destroy', $project)"
              title="Usunąć projekt?"
              confirm="Usuń projekt"
              trigger="Usuń projekt"
              trigger-size="sm"
            >
              Projekt „{{ $project->name }}” wraz z podstronami i treścią zostanie usunięty z aplikacji.
              Tej operacji nie można cofnąć z panelu.
            </x-ui.confirm>
          </div>
        </x-ui.card>
      @endif
    </div>
  </div>
@endsection
