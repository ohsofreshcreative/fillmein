{{--
  Linki recenzji dla klienta (zakres `review`): odczyt zawsze, komentowanie i akceptacja — jawnie. Bez terminu ważności: działa do unieważnienia.
  Adres widać tylko raz (link-issued). Dane: $project, $links (ClientAccess zakresu review).
--}}
<x-ui.card>
  <x-slot:header>
    <div>
      <h2 class="text-md font-semibold">Linki dla klienta</h2>
      <p class="mt-0.5 text-sm text-ink-500">Recenzja bez konta w Fill Me In. Nie daje dostępu do pliku w Figmie.</p>
    </div>
  </x-slot:header>

  @if ($links->isEmpty())
    <p class="text-sm text-ink-500" data-links-empty>Brak linków recenzji.</p>
  @else
    <ul class="flex flex-col gap-2" data-review-links>
      @foreach ($links as $link)
        @php
          [$badgeLabel, $badgeVariant] = $link->statusBadge();
        @endphp
        <li class="flex flex-col gap-1.5 rounded-xl border border-ink-150 p-3" data-review-link="{{ $link->id }}">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="min-w-0 text-sm font-medium break-words text-ink-950">{{ $link->label ?: 'Link #' . $link->id }}</span>
            <x-ui.badge :variant="$badgeVariant">{{ $badgeLabel }}</x-ui.badge>
          </div>
          <p class="text-xs text-ink-500" data-link-abilities>
            {{ implode(' · ', $link->reviewAbilities()) }}{{ $link->hasCode() ? ' · kod dostępu' : '' }}
          </p>
          <p class="text-xs text-ink-500">
            {{ $link->expires_at ? 'Ważny do ' . $link->expires_at->format('d.m.Y H:i') : 'Bez terminu — do unieważnienia' }}{{ $link->last_used_at ? ' · ostatnio użyty ' . $link->last_used_at->format('d.m.Y H:i') : ' · jeszcze nieużyty' }}
          </p>
          @if ($link->isActive())
            <form method="POST" action="{{ route('fmi.design-review.links.revoke', [$project, $link->id]) }}" class="flex justify-end" data-loading-form>
              @csrf
              <x-ui.button type="submit" variant="danger-ghost" size="sm" data-revoke-link>Unieważnij</x-ui.button>
            </form>
          @endif
        </li>
      @endforeach
    </ul>
  @endif

  <form method="POST" action="{{ route('fmi.design-review.links.store', $project) }}" class="mt-4 flex flex-col gap-3 border-t border-ink-150 pt-4" data-loading-form novalidate data-link-form>
    @csrf
    <x-ui.input name="label" label="Opis (opcjonalnie)" maxlength="190" placeholder="np. Anna — akceptacja makiet" />
    <fieldset class="flex flex-col gap-2">
      <legend class="mb-1 text-sm font-medium text-ink-900">Możliwości klienta</legend>
      <p class="text-xs text-ink-500">Podgląd prototypu, ekranów i uwag — zawsze.</p>
      <input type="hidden" name="can_comment" value="0">
      <x-ui.checkbox name="can_comment" label="Komentowanie" description="Nowe uwagi i odpowiedzi pod podaną przez klienta nazwą." :checked="old('can_comment', '1') === '1'" />
      <input type="hidden" name="can_approve" value="0">
      <x-ui.checkbox name="can_approve" label="Akceptacja rewizji" description="Decyzja o rewizji (mechanizm akceptacji — kolejny etap)." :checked="old('can_approve') === '1'" />
    </fieldset>
    <x-ui.input name="code" label="Kod dostępu (opcjonalnie)" type="password" autocomplete="new-password" minlength="6" maxlength="64" hint="Klient wpisze go po otwarciu linku. Przekaż go innym kanałem niż link." />
    <div class="flex justify-end">
      <x-ui.button type="submit" variant="secondary" size="sm">Utwórz link</x-ui.button>
    </div>
  </form>
</x-ui.card>
