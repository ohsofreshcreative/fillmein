@extends('fillmein.layouts.app')

@section('title', 'Podstrona: ' . $page->name)

@section('content')
  <a href="{{ route('fmi.projects.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $project->name }}
  </a>

  <x-ui.page-header eyebrow="Podstrona" :title="$page->name">
    <x-slot:actions>
      <x-ui.badge variant="muted">{{ $page->source->label() }}</x-ui.badge>
      @if ($page->isArchived())
        <x-ui.badge variant="warning">W archiwum</x-ui.badge>
      @endif
    </x-slot:actions>
  </x-ui.page-header>

  <div class="mt-8 grid items-start gap-6 lg:grid-cols-3">
    <div class="flex flex-col gap-6 lg:col-span-2">
      <form method="POST" action="{{ route('fmi.projects.pages.update', [$project, $page]) }}" data-loading-form>
        @csrf

        <x-ui.card>
          <div class="flex flex-col gap-5">
            <x-ui.input name="name" label="Nazwa podstrony" :value="$page->name" required maxlength="190" />

            <x-ui.input
              name="slug"
              label="Slug"
              :value="$page->slug"
              maxlength="190"
              hint="Małe litery, cyfry i myślniki. Musi być unikalny w obrębie podstrony nadrzędnej."
            />

            <x-ui.select
              name="parent_id"
              label="Podstrona nadrzędna"
              :options="$parentOptions"
              :value="$page->parent_id"
              placeholder="— poziom główny —"
              hint="Lista nie zawiera tej podstrony ani jej podstron podrzędnych, żeby nie powstał cykl."
            />

            <x-ui.input
              name="figma_frame_url"
              label="Link do frame'u Figmy"
              type="url"
              :value="$page->figma_frame_url"
              maxlength="500"
              hint="Jeden frame na podstronę. Adres musi zawierać parametr node-id."
            />

            @if ($page->figma_node_id)
              <p class="-mt-2 text-sm text-ink-500">
                Rozpoznany identyfikator warstwy: <span class="font-mono text-xs">{{ $page->figma_node_id }}</span>
              </p>
            @endif

            <x-ui.checkbox
              name="client_can_duplicate"
              label="Klient może duplikować tę podstronę"
              description="Klient bez konta (link dostępowy) będzie mógł utworzyć kopię tej podstrony. Kopia nie zawiera treści."
              :checked="(bool) $page->client_can_duplicate"
            />
          </div>

          <x-slot:footer>
            <div class="flex justify-end gap-2">
              <x-ui.button :href="route('fmi.projects.show', $project)" variant="secondary">Anuluj</x-ui.button>
              <x-ui.button type="submit">Zapisz podstronę</x-ui.button>
            </div>
          </x-slot:footer>
        </x-ui.card>
      </form>

      @include('fillmein.projects.pages.partials.figma')
    </div>

    <div class="flex flex-col gap-6">
      <x-ui.card>
        <x-slot:header>
          <h2 class="text-md font-semibold">Zawartość</h2>
        </x-slot:header>

        <dl class="flex flex-col gap-3 text-base">
          <div class="flex justify-between gap-3">
            <dt class="text-sm text-ink-500">Sekcje</dt>
            <dd class="font-semibold">{{ $sectionsCount }}</dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-sm text-ink-500">Podstrony podrzędne</dt>
            <dd class="font-semibold">{{ $childrenCount }}</dd>
          </div>
          @if ($page->duplicatedFrom)
            <div class="flex justify-between gap-3">
              <dt class="text-sm text-ink-500">Duplikat z</dt>
              <dd class="truncate font-medium">{{ $page->duplicatedFrom->name }}</dd>
            </div>
          @endif
        </dl>

        <div class="mt-4">
          <x-ui.button :href="route('fmi.projects.pages.content.edit', [$project, $page])" variant="secondary" size="sm">Treść</x-ui.button>
          <x-ui.button :href="route('fmi.projects.pages.structure', [$project, $page])" variant="secondary" size="sm">Sekcje i pola</x-ui.button>
        </div>
      </x-ui.card>

      <x-ui.card>
        <x-slot:header>
          <h2 class="text-md font-semibold">Duplikowanie</h2>
        </x-slot:header>

        <form method="POST" action="{{ route('fmi.projects.pages.duplicate', [$project, $page]) }}" class="flex flex-col gap-4" data-loading-form>
          @csrf
          <x-ui.input name="name" label="Nazwa duplikatu" maxlength="190" :placeholder="$page->name . ' (kopia)'" />
          <x-ui.checkbox name="with_values" label="Skopiuj także zapisaną treść" description="Domyślnie duplikat jest pusty — kopiujemy tylko sekcje i pola." />
          <x-ui.checkbox name="with_children" label="Skopiuj podstrony podrzędne" />

          <div class="flex justify-end">
            <x-ui.button type="submit" variant="secondary" size="sm">Utwórz duplikat</x-ui.button>
          </div>
        </form>
      </x-ui.card>

      <x-ui.card>
        <x-slot:header>
          <h2 class="text-md font-semibold">Archiwum</h2>
        </x-slot:header>

        @if ($page->isArchived())
          <p class="text-base text-ink-600">Podstrona jest w archiwum — klient jej nie widzi. Treść i definicje pól są zachowane.</p>
          <div class="mt-4 flex justify-end">
            <form method="POST" action="{{ route('fmi.projects.pages.restore', [$project, $page]) }}">
              @csrf
              <x-ui.button type="submit" variant="secondary" size="sm">Przywróć podstronę</x-ui.button>
            </form>
          </div>
        @else
          <p class="text-base text-ink-600">
            Archiwizacja ukrywa podstronę (wraz z podstronami podrzędnymi) przed klientem.
            Nic nie jest usuwane — można ją przywrócić.
          </p>
          <div class="mt-4 flex justify-end">
            <x-ui.confirm
              :action="route('fmi.projects.pages.archive', [$project, $page])"
              title="Zarchiwizować podstronę?"
              confirm="Archiwizuj"
              trigger="Archiwizuj"
              trigger-size="sm"
            >
              Podstrona „{{ $page->name }}” i jej podstrony podrzędne znikną z edytora klienta.
            </x-ui.confirm>
          </div>
        @endif
      </x-ui.card>
    </div>
  </div>
@endsection
