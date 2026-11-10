{{--
  Recenzja dla klienta (link zakresu `review`, sesja gościa). Bez nawigacji panelu, bez danych innych projektów.
  Możliwości z linku: $guest->access->can_comment (uwagi i odpowiedzi pod nazwą podaną przez gościa).
--}}
@extends('fillmein.layouts.client')

@section('title', 'Recenzja: ' . $project->name)
@section('container', 'mx-auto w-full max-w-6xl px-4 sm:px-6')

@section('header-meta')
  <div class="flex items-center gap-3">
    @if ($guest->name)
      <span class="hidden text-sm text-ink-600 sm:inline" data-guest-name>{{ $guest->name }} <span class="text-ink-500">(gość)</span></span>
    @endif
    <form method="POST" action="{{ route('fmi.review.leave') }}">
      @csrf
      <x-ui.button type="submit" variant="ghost" size="sm">Zakończ</x-ui.button>
    </form>
  </div>
@endsection

@section('content')
  @php
    $canComment = $guest->access->can_comment;
  @endphp
  <div class="flex flex-col gap-6" data-guest-review>
    <div>
      <p class="text-sm font-medium text-ink-500">Recenzja projektu</p>
      <h1 class="mt-1 text-2xl font-semibold tracking-tighter text-ink-950">{{ $project->name }}</h1>
      <p class="mt-1 text-sm text-ink-600">
        {{ $revision ? $revision->title : 'Brak rewizji do recenzji' }}
        · Twój dostęp: <span data-guest-abilities>{{ implode(', ', $guest->access->reviewAbilities()) }}</span>
        @if ($guest->access->expires_at)
          · ważny do {{ $guest->access->expires_at->format('d.m.Y') }}
        @endif
      </p>
    </div>

    @if (session('status'))
      <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.alert variant="info" title="Dostęp do Figmy jest osobny" data-figma-notice>
      Ten link otwiera recenzję w Fill Me In, ale nie daje dostępu do pliku w Figmie. Ekrany i uwagi poniżej działają bez
      konta w Figmie. „Otwórz prototyp w Figmie” prowadzi do Figmy: jeśli plik nie jest udostępniony dla każdego z linkiem,
      Figma poprosi o zalogowanie albo pokaże brak dostępu.
    </x-ui.alert>

    @if (($canComment || $guest->access->can_approve) && ! $guest->name)
      <x-ui.card>
        <form method="POST" action="{{ route('fmi.review.name') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end" data-guest-name-form>
          @csrf
          <div class="min-w-0 flex-1">
            <x-ui.input name="name" label="Jak Cię podpisać?" maxlength="{{ \App\Support\ReviewGuest::NAME_MAX }}" autocomplete="name" hint="Nazwa, którą podajesz sam(a) — zespół zobaczy ją przy Twoich uwagach z dopiskiem „gość”. Nie zakładamy konta." required />
          </div>
          <x-ui.button type="submit">Zapisz i komentuj</x-ui.button>
        </form>
      </x-ui.card>
    @endif

    @if (! $revision)
      <x-ui.empty-state title="Brak rewizji" description="Zespół nie udostępnił jeszcze prototypu do recenzji." />
    @else
      @include('fillmein.design-review.partials.composer', [
        'project' => $project,
        'revision' => $revision,
        'screens' => $screens,
        'threads' => $threads,
        'canComment' => $canComment && $guest->name !== null,
        'needsName' => $canComment && $guest->name === null,
        'openUrl' => $embed['open_url'] ?? null,
        'commentUrl' => route('fmi.review.comments.store'),
        'imageUrl' => fn (int $id) => route('fmi.review.screens.image', $id),
        'replyUrl' => fn ($thread) => route('fmi.review.threads.replies.store', $thread->id),
        'statusUrl' => fn ($thread) => null,
      ])
    @endif

    @if ($revision && $guest->access->can_approve)
      @include('fillmein.design-review.partials.decision', [
        'revision' => $revision,
        'action' => $guest->name && $revision->status !== \App\Enums\ReviewRevisionStatus::Superseded ? route('fmi.review.decision.store') : null,
        'who' => 'nazwę „' . $guest->name . '” (podaną przez Ciebie, niepotwierdzoną)',
      ])
    @endif

    @if ($revision)
      @include('fillmein.client.partials.review-threads', ['threads' => $threads, 'revision' => $revision, 'canReply' => $canComment && $guest->name !== null])
    @endif
  </div>
@endsection
