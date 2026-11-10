@extends('fillmein.layouts.app')

@section('title', 'Nowy projekt')

@section('content')
  <a href="{{ route('fmi.projects.index') }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    Treści
  </a>

  <x-ui.page-header title="Nowy projekt" description="Podstrony i ich frame'y Figmy dodasz po utworzeniu projektu." />

  <form method="POST" action="{{ route('fmi.projects.store') }}" class="mt-8 max-w-2xl" data-loading-form>
    @csrf

    <x-ui.card>
      <div class="flex flex-col gap-5">
        <x-ui.input name="name" label="Nazwa projektu" required autofocus maxlength="190" />
        <x-ui.input name="client_name" label="Nazwa klienta" maxlength="190" hint="Opcjonalnie — pomaga odnaleźć projekt na liście." />

        <x-ui.select
          name="manager_id"
          label="Opiekun projektu"
          :options="$staff"
          :value="$currentUserId"
          hint="Domyślnie Ty. Opiekun i autor mają dostęp do projektu niezależnie od listy pracowników."
        />

        <x-ui.input
          name="figma_url"
          label="Link do pliku Figmy"
          type="url"
          maxlength="500"
          hint="Opcjonalnie. Link do konkretnego frame'u ustawia się osobno na każdej podstronie."
        />

        @include('fillmein.projects.partials.modules', ['selected' => [\App\Enums\ProjectModule::Content->value]])
      </div>

      <x-slot:footer>
        <div class="flex justify-end gap-2">
          <x-ui.button :href="route('fmi.projects.index')" variant="secondary">Anuluj</x-ui.button>
          <x-ui.button type="submit">Utwórz projekt</x-ui.button>
        </div>
      </x-slot:footer>
    </x-ui.card>
  </form>
@endsection
