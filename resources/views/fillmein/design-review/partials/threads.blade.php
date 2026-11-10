{{--
  Lista uwag rewizji: filtr statusu z licznikami, wątki z odpowiedziami, edycją własnych wiadomości, statusem
  i historią. Treści użytkowników tylko przez {{ }}. Każdy formularz niesie `filter`, żeby wrócić do tej samej listy.
  Dane: $project, $revision, $threads (wszystkie), $listed (po filtrze), $filter (?ReviewThreadStatus), $counts.
--}}
@php
  use App\Enums\ReviewThreadStatus;
  $me = wp_get_current_user();
  $filterValue = $filter?->filter();
  $tabs = [['label' => 'Wszystkie', 'value' => null, 'count' => $threads->count()]];
  foreach (ReviewThreadStatus::cases() as $case) {
    $tabs[] = ['label' => $case->label(), 'value' => $case->filter(), 'count' => $counts[$case->value] ?? 0];
  }
@endphp
<x-ui.card class="mt-6" id="uwagi">
  <x-slot:header>
    <div class="flex flex-col gap-3">
      <h2 class="text-md font-semibold">Uwagi {{ $revision ? '— ' . $revision->title : '' }}</h2>
      <nav aria-label="Filtr statusu uwag">
        <ul class="flex flex-wrap gap-2" data-thread-filters>
          @foreach ($tabs as $tab)
            @php
              $active = $tab['value'] === $filterValue;
            @endphp
            <li>
              <a
                href="{{ route('fmi.design-review.show', array_filter([$project, 'status' => $tab['value']])) }}#uwagi"
                @class([
                  'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium outline-none ring-1 ring-inset focus-visible:ring-2 focus-visible:ring-fresh-500',
                  'bg-ink-950 text-white ring-ink-950' => $active,
                  'bg-white text-ink-700 ring-ink-200 hover:bg-ink-50' => ! $active,
                ])
                @if ($active) aria-current="page" @endif
                data-filter="{{ $tab['value'] ?? 'all' }}"
              >
                {{ $tab['label'] }} <span @class(['tabular-nums', 'text-ink-300' => $active, 'text-ink-500' => ! $active]) data-filter-count>{{ $tab['count'] }}</span>
              </a>
            </li>
          @endforeach
        </ul>
      </nav>
    </div>
  </x-slot:header>

  @if ($threads->isEmpty())
    <p class="text-sm text-ink-500" data-threads-empty>Brak uwag w tej rewizji. Kliknij w miejsce na ekranie powyżej, żeby dodać pierwszą.</p>
  @elseif ($listed->isEmpty())
    <p class="text-sm text-ink-500" data-threads-empty>Brak uwag ze statusem „{{ $filter->label() }}”.</p>
  @else
    <ol class="flex flex-col divide-y divide-ink-150" data-threads>
      @foreach ($listed as $thread)
        @php
          $first = $thread->comments->first();
          $threadErrors = $errors->getBag('thread' . $thread->id);
          $ownForm = (string) old('thread_form') === (string) $thread->id;
          $targets = collect(ReviewThreadStatus::cases())
            ->reject(fn ($case) => $case === $thread->status)
            ->filter(fn ($case) => Gate::allows('changeReviewStatus', [$project, $thread, $case]));
        @endphp
        <li id="uwaga-{{ $thread->number }}" class="flex scroll-mt-24 flex-col gap-3 py-5 first:pt-0 last:pb-0" data-thread="{{ $thread->number }}" data-thread-status="{{ $thread->status->value }}">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span class="inline-flex size-7 items-center justify-center rounded-full {{ $thread->hasPin() ? 'bg-ink-950 text-white' : 'bg-ink-100 text-ink-700' }} text-xs font-semibold" aria-hidden="true">{{ $thread->number }}</span>
            <span class="sr-only">Uwaga {{ $thread->number }}</span>
            <x-ui.badge :variant="$thread->status->badge()" data-thread-badge>{{ $thread->status->label() }}</x-ui.badge>
            <span class="text-ink-500">{{ $revision?->title }}</span>
          </div>
          <p class="text-sm text-ink-600" data-thread-context>
            @if ($thread->screen)
              Ekran: <span class="font-medium text-ink-900">{{ $thread->screen->name }}</span>
              @if ($thread->hasPin())
                · pinezka
              @else
                · bez pinezki
              @endif
            @else
              Kontekst: <span class="font-medium text-ink-900">{{ $thread->context }}</span> · bez pinezki
            @endif
          </p>

          <ol class="flex flex-col gap-3" data-comments>
            @foreach ($thread->comments as $comment)
              @php
                $commentErrors = $errors->getBag('comment' . $comment->id);
                $editing = (string) old('comment_form') === (string) $comment->id;
              @endphp
              <li @class(['flex flex-col gap-1 rounded-xl p-3', 'bg-ink-50' => ! $loop->first, 'border border-ink-150' => $loop->first]) data-comment="{{ $comment->id }}">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink-500">
                  <span class="text-sm font-semibold text-ink-950" data-comment-author>{{ $comment->authorName() }}</span>
                  @if ($comment->isGuest())
                    <x-ui.badge variant="muted" data-guest-mark>Gość · nazwa deklarowana</x-ui.badge>
                  @endif
                  <time datetime="{{ $comment->created_at?->toIso8601String() }}">{{ $comment->created_at?->format('d.m.Y H:i') }}</time>
                  @if ($comment->edited_at)
                    <span data-comment-edited>· edytowano {{ $comment->edited_at->format('d.m.Y H:i') }}</span>
                  @endif
                </div>
                <p class="text-sm break-words whitespace-pre-line text-ink-950" data-message-body>{{ $comment->body }}</p>

                @if (! $thread->isResolved() && Gate::allows('editReviewComment', [$project, $comment]))
                  <details class="group mt-1" @if ($editing) open @endif>
                    <summary class="w-fit cursor-pointer text-xs font-medium text-ink-700 underline underline-offset-2 outline-none focus-visible:ring-2 focus-visible:ring-fresh-500">Edytuj</summary>
                    <form method="POST" action="{{ route('fmi.design-review.threads.comments.update', [$project, $thread->id, $comment->id]) }}" class="mt-2 flex flex-col gap-2" data-loading-form data-edit-form>
                      @csrf
                      <input type="hidden" name="comment_form" value="{{ $comment->id }}">
                      <input type="hidden" name="version" value="{{ $comment->lock_version }}">
                      <input type="hidden" name="filter" value="{{ $filterValue }}">
                      <label for="edit-{{ $comment->id }}" class="sr-only">Treść wiadomości</label>
                      <textarea id="edit-{{ $comment->id }}" name="body" rows="3" maxlength="{{ \App\Models\ReviewComment::BODY_MAX }}" required class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500" @if ($commentErrors->any()) aria-invalid="true" aria-describedby="edit-{{ $comment->id }}-error" @endif>{{ $editing ? old('body') : $comment->body }}</textarea>
                      @if ($commentErrors->any())
                        <p id="edit-{{ $comment->id }}-error" class="text-sm text-danger-700" role="alert" data-edit-error>{{ $commentErrors->first() }}</p>
                      @endif
                      <p class="text-xs text-ink-500">Poprzednia treść zostanie w historii uwagi.</p>
                      <div><x-ui.button type="submit" variant="secondary" size="sm">Zapisz zmianę</x-ui.button></div>
                    </form>
                  </details>
                @endif
              </li>
            @endforeach
          </ol>

          @if ($threadErrors->any())
            <x-ui.alert variant="danger" data-thread-error>{{ $threadErrors->first() }}</x-ui.alert>
          @endif

          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            @if ($thread->isResolved())
              <p class="text-sm text-ink-500" data-thread-closed>Uwaga rozwiązana{{ $thread->resolved_at ? ' ' . $thread->resolved_at->format('d.m.Y H:i') : '' }}. Odpowiedzi i edycja po ponownym otwarciu.</p>
            @else
              <form method="POST" action="{{ route('fmi.design-review.threads.replies.store', [$project, $thread->id]) }}" class="flex min-w-0 flex-1 flex-col gap-2" data-loading-form data-reply-form>
                @csrf
                <input type="hidden" name="thread_form" value="{{ $thread->id }}">
                {{-- Nowy identyfikator przy każdym wczytaniu; po błędzie walidacji ten sam (ponowienie = bez duplikatu). --}}
                <input type="hidden" name="request_id" value="{{ $ownForm && old('request_id') ? old('request_id') : (string) \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="filter" value="{{ $filterValue }}">
                <label for="reply-{{ $thread->id }}" class="text-sm font-medium text-ink-900">Odpowiedz</label>
                <textarea id="reply-{{ $thread->id }}" name="body" rows="2" maxlength="{{ \App\Models\ReviewComment::BODY_MAX }}" required class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500">{{ $ownForm ? old('body') : '' }}</textarea>
                <div><x-ui.button type="submit" variant="secondary" size="sm">Wyślij odpowiedź</x-ui.button></div>
              </form>
            @endif

            @if ($targets->isNotEmpty())
              <div class="flex flex-wrap gap-2 sm:justify-end" data-status-actions>
                @foreach ($targets as $target)
                  <form method="POST" action="{{ route('fmi.design-review.threads.status', [$project, $thread->id]) }}" data-loading-form>
                    @csrf
                    <input type="hidden" name="status" value="{{ $target->value }}">
                    <input type="hidden" name="version" value="{{ $thread->lock_version }}">
                    <input type="hidden" name="filter" value="{{ $filterValue }}">
                    <x-ui.button type="submit" size="sm" :variant="$target === ReviewThreadStatus::Resolved ? 'primary' : 'secondary'" data-status-target="{{ $target->value }}">
                      {{ match (true) {
                        $thread->isResolved() && $target === ReviewThreadStatus::Open => 'Otwórz ponownie',
                        $thread->isResolved() => 'Otwórz ponownie: W trakcie',
                        $target === ReviewThreadStatus::Resolved => 'Rozwiąż',
                        default => 'Oznacz: ' . $target->label(),
                      } }}
                    </x-ui.button>
                  </form>
                @endforeach
              </div>
            @endif
          </div>

          <details class="text-sm" data-history>
            <summary class="w-fit cursor-pointer text-xs font-medium text-ink-600 underline underline-offset-2 outline-none focus-visible:ring-2 focus-visible:ring-fresh-500">Historia zmian ({{ $thread->events->count() }})</summary>
            <ol class="mt-2 flex flex-col gap-2 border-l-2 border-ink-150 pl-3 text-xs text-ink-600">
              <li>
                <span class="font-medium text-ink-900">{{ $first?->authorName() }}</span> utworzył(a) uwagę
                <time datetime="{{ $thread->created_at?->toIso8601String() }}">{{ $thread->created_at?->format('d.m.Y H:i') }}</time>
              </li>
              @foreach ($thread->events as $event)
                <li data-event="{{ $event->type }}">
                  <span class="font-medium text-ink-900">{{ $event->actorName() }}</span> {{ $event->describe() }}
                  <time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->format('d.m.Y H:i') }}</time>
                  @if ($event->previous_body !== null)
                    <span class="mt-1 block rounded-lg bg-ink-50 px-2 py-1 break-words whitespace-pre-line text-ink-700">Poprzednio: {{ $event->previous_body }}</span>
                  @endif
                </li>
              @endforeach
            </ol>
          </details>
        </li>
      @endforeach
    </ol>
  @endif
</x-ui.card>
