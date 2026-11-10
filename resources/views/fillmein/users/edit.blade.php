@extends('fillmein.layouts.app')

@section('title', \App\Support\Staff::fullName($person))

@php
  $events = [
    'user.created' => 'Utworzono konto',
    'user.invitation_sent' => 'Wysłano zaproszenie',
    'user.invitation_failed' => 'Nie udało się wysłać zaproszenia',
    'user.password_set' => 'Ustawiono hasło z zaproszenia',
    'user.password_changed' => 'Zmieniono hasło',
    'user.updated' => 'Zmieniono dane',
    'user.role_changed' => 'Zmieniono rolę',
    'user.capabilities_changed' => 'Zmieniono dodatkowe uprawnienia',
    'user.deactivated' => 'Dezaktywowano konto',
    'user.reactivated' => 'Aktywowano ponownie',
  ];
  $fmtDate = fn ($gmt) => $gmt ? get_date_from_gmt((string) $gmt, 'd.m.Y H:i') : null;
@endphp

@section('content')
  <a href="{{ route('fmi.users.index') }}" class="mb-6 inline-flex items-center gap-1.5 rounded text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    Użytkownicy
  </a>

  <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-4">
      <x-ui.avatar :person="$person" size="lg" />
      <div class="min-w-0">
        <h1 class="truncate text-2xl font-semibold tracking-tighter">{{ \App\Support\Staff::fullName($person) }}</h1>
        <p class="truncate text-md text-ink-600">{{ $person->user_email }}</p>
      </div>
    </div>
    <x-ui.badge :variant="$status->badge()">{{ $status->label() }}</x-ui.badge>
  </div>

  <div class="mt-8 grid items-start gap-6 lg:grid-cols-3">
    <form method="POST" action="{{ route('fmi.users.update', $person->ID) }}" class="lg:col-span-2" data-loading-form novalidate>
      @csrf

      <x-ui.card>
        <x-slot:header><h2 class="text-md font-semibold">Dane i uprawnienia</h2></x-slot:header>

        <div class="flex flex-col gap-5">
          <div class="grid gap-5 sm:grid-cols-2">
            <x-ui.input name="first_name" label="Imię" :value="$person->first_name" required />
            <x-ui.input name="last_name" label="Nazwisko" :value="$person->last_name" required />
          </div>

          <x-ui.input name="email_display" label="E-mail" :value="$person->user_email" disabled hint="Adresu e-mail nie zmienia się w aplikacji." />

          @if ($isWpAdministrator)
            <x-ui.alert variant="info" title="Administrator WordPressa">
              To konto ma pełne uprawnienia Fill Me In jako administrator WordPressa. Rolę zmienia się w panelu WordPressa.
            </x-ui.alert>
          @elseif ($isSelf)
            <x-ui.select name="role_display" label="Rola" :options="$roles" :value="$role" disabled hint="Nie możesz zmienić własnej roli." />
          @else
            <x-ui.select name="role" label="Rola" :options="$roles" :value="$role" required />
          @endif

          @unless ($isWpAdministrator)
            @include('fillmein.users.partials.capabilities', ['granted' => $grantedExtra, 'disabled' => $isSelf])
            @if ($isSelf)
              @foreach ($grantedExtra as $capability)
                <input type="hidden" name="capabilities[]" value="{{ $capability }}">
              @endforeach
            @endif
          @endunless
        </div>

        <x-slot:footer>
          <div class="flex justify-end">
            <x-ui.button type="submit">Zapisz zmiany</x-ui.button>
          </div>
        </x-slot:footer>
      </x-ui.card>
    </form>

    <div class="flex flex-col gap-6">
      <x-ui.card>
        <x-slot:header><h2 class="text-md font-semibold">Konto</h2></x-slot:header>

        <dl class="flex flex-col gap-3 text-base">
          <div class="flex justify-between gap-4"><dt class="text-ink-500">Rola</dt><dd class="text-right">{{ \App\Support\Staff::roleLabel($person) }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-ink-500">Dodano</dt><dd>{{ mysql2date('d.m.Y', $person->user_registered) }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-ink-500">Ostatnie logowanie</dt><dd>{{ $fmtDate($meta['lastLogin']) ?? 'Nigdy' }}</dd></div>
          @if ($status === \App\Enums\AccountStatus::Invited)
            <div class="flex justify-between gap-4"><dt class="text-ink-500">Zaproszenie</dt><dd>{{ $fmtDate($meta['invitationSent']) ?? 'Niewysłane' }}</dd></div>
          @endif
          @if ($meta['deactivatedAt'])
            <div class="flex justify-between gap-4"><dt class="text-ink-500">Dezaktywowano</dt><dd>{{ $fmtDate($meta['deactivatedAt']) }}</dd></div>
          @endif
        </dl>

        @unless ($isSelf)
          <x-slot:footer>
            <div class="flex flex-wrap gap-2">
              @if ($status === \App\Enums\AccountStatus::Invited)
                <form method="POST" action="{{ route('fmi.users.invitation', $person->ID) }}" data-loading-form>
                  @csrf
                  <x-ui.button type="submit" variant="secondary" size="sm">Wyślij zaproszenie ponownie</x-ui.button>
                </form>
              @endif

              @if ($status === \App\Enums\AccountStatus::Inactive)
                <x-ui.confirm
                  :action="route('fmi.users.reactivate', $person->ID)"
                  title="Aktywować konto ponownie?"
                  confirm="Aktywuj"
                  variant="primary"
                  trigger-size="sm"
                >
                  {{ \App\Support\Staff::fullName($person) }} odzyska dostęp do Fill Me In zgodnie ze swoją rolą i przypisaniami.
                </x-ui.confirm>
              @else
                <x-ui.confirm
                  :action="route('fmi.users.deactivate', $person->ID)"
                  title="Dezaktywować konto?"
                  confirm="Dezaktywuj"
                  trigger-size="sm"
                >
                  {{ \App\Support\Staff::fullName($person) }} straci dostęp do Fill Me In i zostanie wylogowany na wszystkich urządzeniach.
                  Konto, historia aktywności i przypisania do projektów zostaną zachowane.
                </x-ui.confirm>
              @endif
            </div>
          </x-slot:footer>
        @endunless
      </x-ui.card>

      <x-ui.card>
        <x-slot:header><h2 class="text-md font-semibold">Historia</h2></x-slot:header>

        @if ($history->isEmpty())
          <p class="text-sm text-ink-500">Brak zdarzeń.</p>
        @else
          <ol class="flex flex-col gap-3">
            @foreach ($history as $entry)
              @php($actor = $entry->user_id ? get_userdata($entry->user_id) : null)
              <li class="text-sm">
                <p class="font-medium text-ink-900">
                  {{ $events[$entry->event] ?? $entry->event }}
                  @if ($entry->event === 'user.role_changed')
                    <span class="font-normal text-ink-500">({{ \App\Services\RoleManager::label($entry->properties['from'] ?? null) }} → {{ \App\Services\RoleManager::label($entry->properties['to'] ?? null) }})</span>
                  @endif
                </p>
                <p class="text-ink-500">
                  {{ $entry->created_at?->timezone(wp_timezone())->format('d.m.Y H:i') }}
                  · {{ $actor ? \App\Support\Staff::fullName($actor) : 'System' }}
                </p>
              </li>
            @endforeach
          </ol>
        @endif
      </x-ui.card>
    </div>
  </div>
@endsection
