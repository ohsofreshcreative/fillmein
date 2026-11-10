{{--
  Recenzja na ekranach rewizji: przełącznik ekranów, pełny obraz frame'u z Figmy i uwagi jako dymki.
  Dane: $project, $revision, $screens (ReviewScreen), $threads (ReviewThread bieżącej rewizji z comments).
  Opcjonalnie: $openUrl (link „Otwórz w Figmie”), $canComment (domyślnie true), $commentUrl, $imageUrl (fn (int): string),
  $replyUrl (fn (ReviewThread): ?string), $statusUrl (fn (ReviewThread): ?string) — strona gościa podaje własne trasy.
  Pinezki: x, y (0–1) względem obrazu frame'u (ReviewScreen::PIN_REFERENCE). Treści tylko przez x-text.
--}}
@php
  use App\Enums\ReviewThreadStatus;
  $canComment ??= true;
  $replyUrl ??= fn ($thread) => route('fmi.design-review.threads.replies.store', [$project, $thread->id]);
  $statusUrl ??= fn ($thread) => Gate::allows('changeReviewStatus', [$project, $thread, ReviewThreadStatus::Resolved]) ? route('fmi.design-review.threads.status', [$project, $thread->id]) : null;
  $screensData = $screens->map(fn ($screen) => [
    'id' => $screen->id,
    'name' => $screen->name,
    'confirmed' => $screen->surface_confirmed,
    'width' => $screen->width,
    'height' => $screen->height,
    'image' => isset($imageUrl) ? $imageUrl($screen->id) : route('fmi.design-review.screens.image', [$project, $screen->id]),
  ])->values();
  $threadsData = $threads->map(fn ($thread) => [
    'number' => $thread->number,
    'screen_id' => $thread->screen_id,
    'pin' => $thread->hasPin() ? ['x' => (float) $thread->pin_x, 'y' => (float) $thread->pin_y] : null,
    'status' => $thread->status->value,
    'status_label' => $thread->status->label(),
    'version' => $thread->lock_version,
    'reply_url' => $canComment && ! $thread->isResolved() ? $replyUrl($thread) : null,
    'status_url' => $thread->isResolved() ? null : $statusUrl($thread),
    'comments' => $thread->comments->map(fn ($comment) => [
      'author' => $comment->authorName(),
      'guest' => $comment->isGuest(),
      'time' => $comment->created_at?->format('d.m.Y H:i'),
      'body' => $comment->body,
    ])->values(),
  ])->values();
@endphp
<div
  x-data="reviewComposer({ draftKey: @js('fmi-review-draft:' . $project->id . ':' . $revision->id), action: @js($canComment ? ($commentUrl ?? route('fmi.design-review.comments.store', $project)) : null), csrf: @js(csrf_token()), screens: @js($screensData), threads: @js($threadsData), canComment: @js($canComment), needsName: @js($needsName ?? false) })"
  x-on:keydown.escape="onEscape()"
  x-on:keydown.window="onKey($event)"
  class="flex flex-col gap-4"
  data-review-composer
>
  {{-- Pasek ekranów --}}
  <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    @if ($screens->isNotEmpty())
      <div class="flex min-w-0 items-center gap-2">
        <button type="button" x-on:click="step(-1)" class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-700 outline-none transition hover:bg-ink-50 focus-visible:ring-2 focus-visible:ring-fresh-500" aria-label="Poprzedni ekran" data-screen-prev>
          @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
        </button>
        <nav aria-label="Ekrany rewizji" class="min-w-0 flex-1 overflow-x-auto">
          <ul class="flex gap-1.5 py-0.5" data-screen-tabs>
            @foreach ($screens as $screen)
              <li class="shrink-0">
                <button
                  type="button"
                  x-on:click="selectScreen({{ $screen->id }})"
                  :aria-current="String(screenId) === '{{ $screen->id }}' ? 'page' : null"
                  :class="String(screenId) === '{{ $screen->id }}' ? 'bg-ink-950 text-white' : 'bg-white text-ink-700 ring-1 ring-inset ring-ink-200 hover:bg-ink-50'"
                  class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium whitespace-nowrap outline-none transition focus-visible:ring-2 focus-visible:ring-fresh-500"
                  data-screen-tab="{{ $screen->id }}"
                >
                  <span>{{ $screen->name }}</span>
                  <span x-show="openCount(screens[{{ $loop->index }}]) > 0" x-text="openCount(screens[{{ $loop->index }}])" class="rounded-full bg-fresh-500 px-1.5 text-xs font-semibold text-ink-950 tabular-nums" data-screen-count></span>
                </button>
              </li>
            @endforeach
          </ul>
        </nav>
        <button type="button" x-on:click="step(1)" class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-700 outline-none transition hover:bg-ink-50 focus-visible:ring-2 focus-visible:ring-fresh-500" aria-label="Następny ekran" data-screen-next>
          @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4 rotate-180'])
        </button>
      </div>
    @endif

    <div class="flex shrink-0 flex-wrap items-center gap-2">
      <label x-show="resolvedCount > 0" x-cloak class="inline-flex items-center gap-2 text-sm text-ink-700">
        <input type="checkbox" x-model="showResolved" class="size-4 rounded border-ink-300 accent-ink-950" data-show-resolved>
        Pokaż rozwiązane (<span x-text="resolvedCount"></span>)
      </label>
      @if ($canComment || ($needsName ?? false))
        <x-ui.button type="button" variant="secondary" size="sm" x-on:click="wholeScreen()" data-comment-whole>{{ $screens->isEmpty() ? 'Dodaj uwagę' : 'Uwaga do całego ekranu' }}</x-ui.button>
      @endif
      @if (! empty($openUrl))
        <x-ui.button variant="ghost" size="sm" :href="$openUrl" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" data-embed-open>Otwórz prototyp w Figmie</x-ui.button>
      @endif
    </div>
  </div>

  <p class="text-sm text-ink-600" data-mode-status>
    @if ($screens->isEmpty())
      Rewizja nie ma jeszcze ekranów z Figmy — uwagi trafią do opisanego kontekstu, bez pinezek.
    @elseif ($canComment)
      <span x-show="!screen || screen.confirmed">Kliknij w dowolne miejsce ekranu, aby dodać uwagę. Kliknij dymek, aby zobaczyć wątek. Strzałki ← → zmieniają ekran.</span>
      <span x-show="screen && !screen.confirmed" x-cloak class="text-warning-800">Obraz tego ekranu nie odpowiada granicom frame'u w Figmie — uwagi tylko do całego ekranu, bez pinezek.</span>
    @elseif ($needsName ?? false)
      Żeby dodać uwagę, najpierw wpisz u góry, jak Cię podpisać. Kliknij dymek, aby zobaczyć wątek. Strzałki ← → zmieniają ekran.
    @else
      Kliknij dymek, aby zobaczyć wątek. Strzałki ← → zmieniają ekran.
    @endif
  </p>

  <p x-show="hasDraft && !open" x-cloak class="text-sm text-ink-600" data-draft-badge>
    Masz niezapisany szkic uwagi.
    <button type="button" class="font-medium text-ink-950 underline underline-offset-2 outline-none focus-visible:ring-2 focus-visible:ring-fresh-500" x-on:click="startForm()">Wróć do szkicu</button>
  </p>
  <p x-show="notice" x-cloak class="text-sm text-ink-600" role="status" x-text="notice" data-comment-notice></p>

  <div x-ref="stage" class="rounded-xl border border-ink-150 bg-ink-100 p-2 sm:p-4" data-screen-viewport>
    <div class="relative mx-auto max-w-full" :style="screen && screen.width ? `width: ${screen.width}px` : ''">
      <div
        x-ref="surface"
        class="relative"
        :class="(canComment || needsName) && screen && screen.confirmed ? 'cursor-crosshair' : ((canComment || needsName) && screen ? 'cursor-pointer' : '')"
        x-on:click="pickPin($event)"
        :data-screen="screen ? screen.id : null"
        data-pin-surface
      >
        <template x-if="screen">
          <img :src="screen.image" :alt="'Ekran: ' + screen.name" class="block h-auto w-full rounded-md bg-white shadow-xs select-none" draggable="false" data-screen-image>
        </template>
        @if ($screens->isEmpty())
          <div class="flex min-h-48 items-center justify-center rounded-md border border-dashed border-ink-200 bg-white p-6 text-center text-sm text-ink-500" data-screens-empty-surface>
            Dodaj ekrany rewizji (frame'y z Figmy), żeby komentować bezpośrednio na podstronach.
          </div>
        @endif

        {{-- Dymki uwag --}}
        <template x-for="item in screenPins" :key="item.number">
          <button
            type="button"
            x-on:click.stop="toggleThread(item.number)"
            class="absolute z-10 flex size-9 origin-bottom-left -translate-y-full items-center justify-center rounded-full rounded-bl-none border-2 border-white text-sm font-bold shadow-lg outline-none transition hover:scale-110 focus-visible:ring-2 focus-visible:ring-fresh-500"
            :class="item.status === 'resolved' ? 'bg-success-600 text-white' : (item.status === 'in_progress' ? 'bg-warning-600 text-white' : 'bg-info-600 text-white')"
            :style="`left: ${item.pin.x * 100}%; top: ${item.pin.y * 100}%`"
            :aria-label="`Uwaga ${item.number}: ${item.status_label}, ${item.comments[0]?.author ?? ''}`"
            :aria-expanded="(active === item.number).toString()"
            :data-pin="item.number"
            :data-pin-status="item.status"
            x-text="item.number"
          ></button>
        </template>

        {{-- Nowa pinezka --}}
        <span
          x-show="pin"
          x-cloak
          class="pointer-events-none absolute z-20 size-9 -translate-y-full rounded-full rounded-bl-none border-2 border-white bg-fresh-500 shadow-lg"
          :style="pin ? `left: ${pin.x * 100}%; top: ${pin.y * 100}%` : ''"
          aria-hidden="true"
          data-new-pin
        ></span>

        {{-- Dymek wątku --}}
        <div
          x-show="thread"
          x-cloak
          x-ref="threadPopover"
          x-on:click.stop
          tabindex="-1"
          role="dialog"
          :aria-label="thread ? `Uwaga ${thread.number}` : null"
          class="absolute z-30 flex w-80 max-w-[calc(100vw-3rem)] flex-col gap-3 rounded-xl border border-ink-150 bg-white p-4 text-left shadow-lg outline-none"
          :style="thread ? popoverStyle(thread.pin) : ''"
          data-thread-popover
        >
          <template x-if="thread">
            <div class="flex flex-col gap-3">
              <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold text-ink-950" x-text="`Uwaga ${thread.number}`"></span>
                <div class="flex items-center gap-2">
                  <span class="rounded-full bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-700" x-text="thread.status_label" data-popover-status></span>
                  <button type="button" x-on:click="closeThread()" data-popover-close class="rounded-md p-1 text-ink-500 outline-none hover:text-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500" aria-label="Zamknij">
                    <span aria-hidden="true" class="block size-4 text-center text-md leading-4">×</span>
                  </button>
                </div>
              </div>
              <ol class="flex max-h-60 flex-col gap-3 overflow-y-auto" data-popover-comments>
                <template x-for="(comment, index) in thread.comments" :key="index">
                  <li class="flex flex-col gap-0.5">
                    <div class="flex flex-wrap items-baseline gap-x-2 text-xs text-ink-500">
                      <span class="text-sm font-semibold text-ink-950" x-text="comment.author"></span>
                      <span x-show="comment.guest">gość</span>
                      <span x-text="comment.time"></span>
                    </div>
                    <p class="text-sm break-words whitespace-pre-line text-ink-900" x-text="comment.body"></p>
                  </li>
                </template>
              </ol>
              <form x-show="thread.reply_url" x-on:submit.prevent="sendReply()" class="flex flex-col gap-2" novalidate data-popover-reply>
                <label :for="`popover-reply-${thread.number}`" class="sr-only">Odpowiedz</label>
                <textarea :id="`popover-reply-${thread.number}`" x-model="reply" :maxlength="bodyMax" rows="2" placeholder="Odpowiedz…" class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500"></textarea>
                <p x-show="replyError" class="text-sm text-danger-800" role="alert" x-text="replyError"></p>
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <a :href="`#uwaga-${thread.number}`" class="text-xs font-medium text-ink-600 underline underline-offset-2 outline-none hover:text-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500">Pokaż na liście</a>
                  <div class="flex gap-2">
                    <x-ui.button type="button" variant="secondary" size="sm" x-show="thread.status_url" x-on:click="resolve()" ::disabled="replySaving" data-popover-resolve>Rozwiąż</x-ui.button>
                    <x-ui.button type="submit" size="sm" ::disabled="replySaving">Odpowiedz</x-ui.button>
                  </div>
                </div>
              </form>
              <div x-show="!thread.reply_url" class="flex flex-wrap items-center justify-between gap-2">
                <a :href="`#uwaga-${thread.number}`" class="text-xs font-medium text-ink-600 underline underline-offset-2 outline-none hover:text-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500">Pokaż na liście</a>
                <p x-show="thread.status === 'resolved'" class="text-xs text-ink-500">Uwaga rozwiązana.</p>
              </div>
            </div>
          </template>
        </div>

        {{-- Dymek nowej uwagi (z pinezką przy niej, bez pinezki u góry ekranu) --}}
        @if ($canComment)
          <form
            x-show="open"
            x-cloak
            x-on:click.stop
            x-on:submit.prevent="submit()"
            class="absolute z-30 flex w-80 max-w-[calc(100vw-3rem)] flex-col gap-3 rounded-xl border border-ink-150 bg-white p-4 text-left shadow-lg"
            :class="pin ? '' : 'top-3 left-1/2 -translate-x-1/2'"
            :style="pin ? popoverStyle(pin) : ''"
            aria-labelledby="comment-form-title"
            novalidate
            data-comment-form
          >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <h3 id="comment-form-title" class="text-sm font-semibold text-ink-950">Nowa uwaga</h3>
              <span class="text-xs text-ink-500" x-text="pin ? 'Pinezka w tym miejscu' : (screen ? `Do całego ekranu „${screen.name}”` : 'Bez ekranu')" data-comment-kind></span>
            </div>

            <div x-show="!screen" class="flex flex-col gap-1.5">
              <label for="comment-context" class="text-sm font-medium text-ink-900">Kontekst</label>
              <input id="comment-context" type="text" x-model="context" :maxlength="contextMax" class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500" placeholder="np. Koszyk, Strona produktu" data-comment-context>
            </div>

            <div class="flex flex-col gap-1.5">
              <label for="comment-body" class="sr-only">Treść uwagi</label>
              <textarea
                id="comment-body"
                x-ref="body"
                x-model="body"
                :maxlength="bodyMax"
                rows="3"
                placeholder="Dodaj uwagę…"
                x-on:keydown.meta.enter.prevent="submit()"
                x-on:keydown.ctrl.enter.prevent="submit()"
                class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500"
                data-comment-body
              ></textarea>
              <p class="text-xs text-ink-500"><span x-text="body.length"></span> / <span x-text="bodyMax"></span> · Ctrl/⌘ + Enter — wyślij</p>
            </div>

            <p x-show="error" x-cloak class="text-sm text-danger-800" role="alert" x-text="error" data-comment-error></p>

            <div class="flex flex-wrap items-center justify-between gap-2">
              <button type="button" x-show="pin" class="text-xs font-medium text-ink-600 underline underline-offset-2 outline-none hover:text-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500" x-on:click="removePin()" data-remove-pin>Bez pinezki</button>
              <button type="button" x-show="!pin && hasDraft" class="text-xs font-medium text-ink-600 underline underline-offset-2 outline-none hover:text-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500" x-on:click="discard()" data-comment-discard>Odrzuć szkic</button>
              <div class="ml-auto flex gap-2">
                <x-ui.button type="button" variant="ghost" size="sm" x-on:click="cancel()" data-comment-cancel>Anuluj</x-ui.button>
                <x-ui.button type="submit" size="sm" ::disabled="saving" data-comment-submit>Dodaj</x-ui.button>
              </div>
            </div>
          </form>
        @endif
      </div>
    </div>
  </div>
</div>
