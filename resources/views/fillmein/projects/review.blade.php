@extends('fillmein.layouts.app')

@section('title', 'Przegląd treści: ' . $project->name)

@section('content')
  <a href="{{ route('fmi.projects.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $project->name }}
  </a>

  <x-ui.page-header eyebrow="Przegląd treści" title="Treści projektu" description="Status pól i ostatnie zmiany. Komentarze do poprawy widzi tylko zespół." />

  @include('fillmein.projects.partials.content-tabs', ['project' => $project, 'active' => 'review'])

  @if ($errors->any())
    <x-ui.alert variant="danger" class="mt-6">{{ $errors->first() }}</x-ui.alert>
  @endif

  <form method="GET" action="{{ route('fmi.projects.review', $project) }}" class="mt-8 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <x-ui.select name="strona" label="Podstrona" :options="$pages" :value="$filters['strona']" placeholder="Wszystkie" />
    <x-ui.select name="status" label="Status" :options="\App\Services\ContentReviewService::STATUSES" :value="$filters['status']" placeholder="Wszystkie" />
    <x-ui.select name="autor" label="Autor" :options="\App\Services\ContentReviewService::AUTHORS" :value="$filters['autor']" placeholder="Wszyscy" />
    <div class="flex gap-2">
      <x-ui.button type="submit" variant="secondary" size="sm">Filtruj</x-ui.button>
      @if (array_filter($filters))
        <x-ui.button :href="route('fmi.projects.review', $project)" variant="ghost" size="sm">Wyczyść</x-ui.button>
      @endif
    </div>
  </form>

  <div class="mt-6 flex flex-wrap gap-2">
    <x-ui.badge variant="muted">Puste: {{ $counts['empty'] }}</x-ui.badge>
    <x-ui.badge variant="success">Wypełnione: {{ $counts['filled'] }}</x-ui.badge>
    <x-ui.badge variant="warning">Do poprawy: {{ $counts['review'] }}</x-ui.badge>
  </div>

  @if ($items->isEmpty())
    <x-ui.empty-state class="mt-6" title="Brak pól" description="Zmień filtry albo dodaj pola do podstron." />
  @else
    <ul class="mt-6 flex flex-col gap-3" role="list">
      @foreach ($items as $item)
        @php
          $value = $item['value'];
          $variant = ['empty' => 'muted', 'filled' => 'success', 'review' => 'warning'][$item['status']];
          $noteId = 'note-' . ($value?->id ?? 0);
        @endphp
        <li class="rounded-xl border border-ink-150 bg-white p-4">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <h2 class="text-base font-semibold text-ink-950">{{ $item['field']->label }}</h2>
              <p class="text-sm text-ink-500">{{ $item['page']->name }} · {{ $item['path'] }}</p>
            </div>
            <x-ui.badge :variant="$variant">{{ \App\Services\ContentReviewService::STATUSES[$item['status']] }}</x-ui.badge>
          </div>

          @if ($item['excerpt'])
            <p class="mt-2 text-base break-words text-ink-700">{{ $item['excerpt'] }}</p>
            <p class="mt-1 text-sm text-ink-500">
              {{ $item['author'] }} · <time datetime="{{ $value->updated_at?->toIso8601String() }}">{{ $value->updated_at?->format('d.m.Y H:i') }}</time>
            </p>
          @endif

          @if ($value?->review_requested_at)
            <p class="mt-2 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-ink-800">{{ $value->review_note }}</p>
            <form method="POST" action="{{ route('fmi.projects.review.clear', [$project, $value->id]) }}" class="mt-2">
              @csrf
              <x-ui.button type="submit" variant="secondary" size="sm">
                Zdejmij oznaczenie<span class="sr-only">: {{ $item['field']->label }}</span>
              </x-ui.button>
            </form>
          @elseif ($item['excerpt'])
            <details class="mt-2">
              <summary class="cursor-pointer text-sm font-medium text-ink-700">
                Oznacz do poprawy<span class="sr-only">: {{ $item['field']->label }}</span>
              </summary>
              <form method="POST" action="{{ route('fmi.projects.review.mark', [$project, $value->id]) }}" class="mt-2 flex flex-col items-start gap-2">
                @csrf
                <div class="w-full">
                  <x-ui.textarea name="note" :id="$noteId" label="Komentarz dla zespołu" maxlength="1000" required />
                </div>
                <x-ui.button type="submit" size="sm">Zapisz oznaczenie</x-ui.button>
              </form>
            </details>
          @endif
        </li>
      @endforeach
    </ul>
  @endif

  <x-ui.pagination
    class="mt-6"
    :page="$page"
    :last-page="$lastPage"
    :total="$total"
    :url="fn ($number) => route('fmi.projects.review', [$project, ...array_filter($filters), 'page' => $number])"
  />
@endsection
