{{--
  Decyzja o bieżącej rewizji (akceptacja / poprawki) — panel i strona gościa.
  Dane: $revision (bieżąca, z relacją decision), $action (adres zapisu albo null — brak prawa),
  opcjonalnie: $history (rewizje z decyzjami), $project + $canCreate (panel: nowa rewizja, sprawdzenie wersji),
  $who (opis decydującego: konto albo nazwa deklarowana).
  Decyzja dotyczy wyłącznie tej rewizji; to nie jest podpis elektroniczny.
--}}
@php
  use App\Enums\ReviewRevisionStatus;
  $decision = $revision->decision;
  $decisionErrors = $errors->getBag('decision');
  $check = session('version_check');
@endphp
<x-ui.card id="decyzja" class="scroll-mt-24" data-decision-card>
  <x-slot:header>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-md font-semibold">Decyzja — {{ $revision->title }}</h2>
      <x-ui.badge :variant="$revision->status->badge()" data-revision-status>{{ $revision->status->label() }}</x-ui.badge>
    </div>
  </x-slot:header>

  <div class="flex flex-col gap-4">
    @if ($decisionErrors->any())
      <x-ui.alert variant="danger" data-decision-error>{{ $decisionErrors->first() }}</x-ui.alert>
    @endif

    @if ($decision)
      <div class="flex flex-col gap-2 rounded-xl border border-ink-150 p-4" data-decision="{{ $decision->decision->value }}">
        <p class="text-sm font-semibold text-ink-950">{{ $decision->isApproval() ? 'Zaakceptowano' : 'Zgłoszono poprawki' }} — {{ $revision->title }}</p>
        <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
          <dt class="text-ink-500">Kto</dt>
          <dd class="text-ink-950" data-decision-actor>{{ $decision->actorLabel() }}</dd>
          <dt class="text-ink-500">Identyfikacja</dt>
          <dd class="text-ink-950" data-decision-identification>{{ $decision->identificationLabel() }}</dd>
          <dt class="text-ink-500">Czas (serwer)</dt>
          <dd class="text-ink-950"><time datetime="{{ $decision->created_at?->toIso8601String() }}">{{ $decision->created_at?->format('d.m.Y H:i:s') }}</time></dd>
          <dt class="text-ink-500">Wersja pliku Figmy</dt>
          <dd class="text-ink-950" data-decision-version="{{ $decision->version_status }}">
            @if ($decision->version_status === \App\Models\ReviewDecision::PINNED)
              {{ $decision->figma_version }}{{ $decision->figma_last_modified ? ' · ostatnia zmiana w Figmie ' . $decision->figma_last_modified->format('d.m.Y H:i') : '' }}
            @else
              nieustalona
            @endif
          </dd>
        </dl>
        @if ($decision->note)
          <p class="text-sm break-words whitespace-pre-line text-ink-950" data-decision-note>{{ $decision->note }}</p>
        @endif
      </div>

      @if ($decision->version_status === \App\Models\ReviewDecision::UNVERIFIED)
        <x-ui.alert variant="warning" data-version-limit>
          Fill Me In nie odczytał wersji pliku z Figmy w chwili decyzji. Decyzja dotyczy zawartości linku prototypu z tej chwili,
          ale nie ma dowodu, jaka to była wersja — link Figmy zawsze pokazuje bieżący stan pliku.
        </x-ui.alert>
      @else
        <p class="text-xs text-ink-500" data-version-note>
          Link Figmy zawsze pokazuje bieżący stan pliku. Decyzja obejmuje wersję zapisaną powyżej — zmiany wprowadzone później
          w Figmie wymagają nowej rewizji i nowej decyzji.
        </p>
      @endif

      @if (isset($project) && ($canCreate ?? false))
        @if ($check && (int) $check['decision'] === $decision->id)
          @if ($check['status'] === 'changed')
            <x-ui.alert variant="warning" title="Plik w Figmie zmienił się od decyzji" data-version-check="changed">
              Ten sam link pokazuje teraz inną wersję pliku{{ $check['last_modified'] ? ' (ostatnia zmiana ' . $check['last_modified'] . ')' : '' }}. Decyzja nie obejmuje tych zmian — utwórz nową rewizję.
            </x-ui.alert>
          @elseif ($check['status'] === 'same')
            <x-ui.alert variant="success" data-version-check="same">Plik w Figmie ma tę samą wersję co w chwili decyzji.</x-ui.alert>
          @else
            <x-ui.alert variant="warning" data-version-check="unknown">Nie można porównać wersji (brak wersji z chwili decyzji albo odpowiedzi Figmy).</x-ui.alert>
          @endif
        @endif
        <div class="flex flex-wrap gap-2">
          <form method="POST" action="{{ route('fmi.design-review.decisions.check', [$project, $decision->id]) }}" data-loading-form>
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm" data-version-check-button>Sprawdź, czy plik się zmienił</x-ui.button>
          </form>
          <form method="POST" action="{{ route('fmi.design-review.revisions.store', $project) }}" data-loading-form>
            @csrf
            <x-ui.button type="submit" size="sm" data-new-revision>Utwórz nową rewizję</x-ui.button>
          </form>
        </div>
        <p class="text-xs text-ink-500">Nowa rewizja zaczyna bez decyzji („Oczekuje na decyzję”). Ta decyzja zostaje w historii.</p>
      @endif
    @elseif ($action)
      <form method="POST" action="{{ $action }}" class="flex flex-col gap-4" data-loading-form data-decision-form novalidate>
        @csrf
        <input type="hidden" name="revision" value="{{ $revision->number }}">
        <input type="hidden" name="request_id" value="{{ old('request_id') ?: (string) \Illuminate\Support\Str::uuid() }}">
        <fieldset class="flex flex-col gap-2">
          <legend class="mb-1 text-sm font-medium text-ink-900">Twoja decyzja dla: {{ $revision->title }}</legend>
          @foreach ([ReviewRevisionStatus::Approved->value => 'Akceptuję tę rewizję', ReviewRevisionStatus::ChangesRequested->value => 'Proszę o poprawki'] as $value => $label)
            <label class="flex items-center gap-2 text-sm text-ink-950">
              <input type="radio" name="decision" value="{{ $value }}" @checked(old('decision') === $value) required class="size-4 accent-ink-900">
              {{ $label }}
            </label>
          @endforeach
        </fieldset>
        <div class="flex flex-col gap-1.5">
          <label for="decision-note" class="text-sm font-medium text-ink-900">Komentarz <span class="font-normal text-ink-500">(wymagany przy poprawkach)</span></label>
          <textarea id="decision-note" name="note" rows="3" maxlength="{{ \App\Models\ReviewComment::BODY_MAX }}" class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-ink-950 focus-visible:ring-2 focus-visible:ring-fresh-500">{{ old('note') }}</textarea>
        </div>
        <label class="flex items-start gap-2 text-sm text-ink-950">
          <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 accent-ink-900">
          <span>
            Decyzja dotyczy wyłącznie: {{ $revision->title }} (stan prototypu w Figmie z chwili zapisu).
            Zapiszemy: {{ $who ?? 'Twoją nazwę' }}, sposób identyfikacji i czas serwera. To nie jest podpis elektroniczny.
          </span>
        </label>
        <div><x-ui.button type="submit" data-decision-submit>Zapisz decyzję</x-ui.button></div>
      </form>
    @else
      <p class="text-sm text-ink-500" data-decision-pending>Rewizja czeka na decyzję.</p>
    @endif

    @if (isset($history) && $history->filter->decision->isNotEmpty())
      <div class="border-t border-ink-150 pt-4">
        <h3 class="text-sm font-semibold text-ink-950">Historia decyzji</h3>
        <ol class="mt-2 flex flex-col gap-1.5 text-sm text-ink-600" data-decision-history>
          @foreach ($history->filter->decision as $past)
            <li data-history-revision="{{ $past->number }}">
              <span class="font-medium text-ink-950">{{ $past->title }}</span>:
              {{ $past->decision->isApproval() ? 'zaakceptowana' : 'do poprawy' }} — {{ $past->decision->actorLabel() }}
              ({{ $past->decision->identification === 'wp_account' ? 'konto' : 'link, nazwa deklarowana' }}),
              {{ $past->decision->created_at?->format('d.m.Y H:i') }}
            </li>
          @endforeach
        </ol>
      </div>
    @endif
  </div>
</x-ui.card>
