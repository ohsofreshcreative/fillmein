{{--
  Uwagi rewizji widziane przez gościa: odczyt, odpowiedzi (gdy link pozwala). Bez zmian statusu i edycji.
  Nazwy gości są deklarowane — zawsze z dopiskiem „gość”. Treści tylko przez {{ }}.
--}}
<x-ui.card id="uwagi">
  <x-slot:header>
    <h2 class="text-md font-semibold">Uwagi — {{ $revision->title }} <span class="font-normal text-ink-500">({{ $threads->count() }})</span></h2>
  </x-slot:header>

  @if ($threads->isEmpty())
    <p class="text-sm text-ink-500" data-threads-empty>Brak uwag w tej rewizji.</p>
  @else
    <ol class="flex flex-col divide-y divide-ink-150" data-threads>
      @foreach ($threads as $thread)
        @php
          $threadErrors = $errors->getBag('thread' . $thread->id);
          $ownForm = (string) old('thread_form') === (string) $thread->id;
        @endphp
        <li id="uwaga-{{ $thread->number }}" class="flex scroll-mt-24 flex-col gap-3 py-5 first:pt-0 last:pb-0" data-thread="{{ $thread->number }}">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span class="inline-flex size-7 items-center justify-center rounded-full {{ $thread->hasPin() ? 'bg-ink-950 text-white' : 'bg-ink-100 text-ink-700' }} text-xs font-semibold" aria-hidden="true">{{ $thread->number }}</span>
            <span class="sr-only">Uwaga {{ $thread->number }}</span>
            <x-ui.badge :variant="$thread->status->badge()">{{ $thread->status->label() }}</x-ui.badge>
            <span class="text-ink-600">
              @if ($thread->screen)
                Ekran: {{ $thread->screen->name }}{{ $thread->hasPin() ? ' · pinezka' : ' · bez pinezki' }}
              @else
                Kontekst: {{ $thread->context }} · bez pinezki
              @endif
            </span>
          </div>
          <ol class="flex flex-col gap-3">
            @foreach ($thread->comments as $comment)
              <li @class(['flex flex-col gap-1 rounded-xl p-3', 'bg-ink-50' => ! $loop->first, 'border border-ink-150' => $loop->first]) data-comment="{{ $comment->id }}">
                <div class="flex flex-wrap items-center gap-x-2 text-xs text-ink-500">
                  <span class="text-sm font-semibold text-ink-950" data-comment-author>{{ $comment->authorName() }}</span>
                  @if ($comment->isGuest())
                    <span data-guest-mark>gość · nazwa podana przez osobę komentującą</span>
                  @endif
                  <time datetime="{{ $comment->created_at?->toIso8601String() }}">{{ $comment->created_at?->format('d.m.Y H:i') }}</time>
                  @if ($comment->edited_at)
                    <span>· edytowano</span>
                  @endif
                </div>
                <p class="text-sm break-words whitespace-pre-line text-ink-950" data-message-body>{{ $comment->body }}</p>
              </li>
            @endforeach
          </ol>

          @if ($threadErrors->any())
            <x-ui.alert variant="danger" data-thread-error>{{ $threadErrors->first() }}</x-ui.alert>
          @endif

          @if ($canReply && ! $thread->isResolved())
            <form method="POST" action="{{ route('fmi.review.threads.replies.store', $thread->id) }}" class="flex flex-col gap-2" data-loading-form data-reply-form>
              @csrf
              <input type="hidden" name="thread_form" value="{{ $thread->id }}">
              <input type="hidden" name="request_id" value="{{ $ownForm && old('request_id') ? old('request_id') : (string) \Illuminate\Support\Str::uuid() }}">
              <label for="reply-{{ $thread->id }}" class="text-sm font-medium text-ink-900">Odpowiedz</label>
              <textarea id="reply-{{ $thread->id }}" name="body" rows="2" maxlength="{{ \App\Models\ReviewComment::BODY_MAX }}" required class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500">{{ $ownForm ? old('body') : '' }}</textarea>
              <div><x-ui.button type="submit" variant="secondary" size="sm">Wyślij odpowiedź</x-ui.button></div>
            </form>
          @elseif ($thread->isResolved())
            <p class="text-sm text-ink-500">Uwaga rozwiązana przez zespół.</p>
          @endif
        </li>
      @endforeach
    </ol>
  @endif
</x-ui.card>
