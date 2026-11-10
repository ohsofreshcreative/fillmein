@extends('fillmein.layouts.app')

@section('title', 'Sekcja: ' . $section->name)

@section('content')
  <a href="{{ route('fmi.projects.pages.structure', [$project, $page]) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    Sekcje i pola
  </a>

  <x-ui.page-header eyebrow="Sekcja" :title="$section->name" />

  <form method="POST" action="{{ route('fmi.projects.pages.sections.update', [$project, $page, $section]) }}" class="mt-8 max-w-2xl" data-loading-form>
    @csrf
    <x-ui.card>
      <div class="flex flex-col gap-5">
        <x-ui.input name="name" label="Nazwa sekcji" :value="$section->name" required maxlength="190" />
        <x-ui.checkbox name="is_hidden" label="Ukryj sekcję przed klientem" :checked="(bool) old('is_hidden', $section->is_hidden)" />
      </div>

      <x-slot:footer>
        <div class="flex justify-end gap-2">
          <x-ui.button :href="route('fmi.projects.pages.structure', [$project, $page])" variant="secondary">Anuluj</x-ui.button>
          <x-ui.button type="submit">Zapisz sekcję</x-ui.button>
        </div>
      </x-slot:footer>
    </x-ui.card>
  </form>
@endsection
