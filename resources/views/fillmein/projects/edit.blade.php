@extends('fillmein.layouts.app')

@section('title', 'Ustawienia: ' . $project->name)

@section('content')
  <a href="{{ route('fmi.projects.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $project->name }}
  </a>

  <x-ui.page-header eyebrow="Ustawienia projektu" :title="$project->name" />

  <form method="POST" action="{{ route('fmi.projects.update', $project) }}" class="mt-8 max-w-2xl" data-loading-form>
    @csrf

    <x-ui.card>
      <div class="flex flex-col gap-5">
        <x-ui.input name="name" label="Nazwa projektu" :value="$project->name" required maxlength="190" />
        <x-ui.input name="client_name" label="Nazwa klienta" :value="$project->client_name" maxlength="190" />
        <x-ui.select name="status" label="Status" :options="$statuses" :value="$project->status->value" required />
        <x-ui.select name="manager_id" label="Opiekun projektu" :options="$staff" :value="$project->manager_id" placeholder="Bez opiekuna" />
        <x-ui.input
          name="figma_url"
          label="Link do pliku Figmy"
          type="url"
          :value="$project->figma_file_url"
          maxlength="500"
          hint="Plik nadrzędny projektu. Każda podstrona ma własny link do frame'u."
        />

        @include('fillmein.projects.partials.modules', ['selected' => array_map(fn ($module) => $module->value, $project->modules())])
      </div>

      <x-slot:footer>
        <div class="flex justify-end gap-2">
          <x-ui.button :href="route('fmi.projects.show', $project)" variant="secondary">Anuluj</x-ui.button>
          <x-ui.button type="submit">Zapisz zmiany</x-ui.button>
        </div>
      </x-slot:footer>
    </x-ui.card>
  </form>

  @if ($siteSchema)
    <div class="max-w-2xl">
      @include('fillmein.projects.partials.site-schema-upload', ['project' => $project, 'schema' => $siteSchema['schema'], 'class' => 'mt-6'])
    </div>
  @endif
@endsection
