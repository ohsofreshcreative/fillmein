@extends('fillmein.layouts.app')

@section('title', 'Użytkownicy')

@section('content')
  <x-ui.page-header title="Użytkownicy" description="Konta zespołu OhSoFresh z dostępem do Fill Me In.">
    <x-slot:actions>
      <x-ui.button :href="route('fmi.users.create')">
        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" /></svg>
        Dodaj użytkownika
      </x-ui.button>
    </x-slot:actions>
  </x-ui.page-header>

  <form method="GET" action="{{ route('fmi.users.index') }}" class="mt-8 grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_12rem_auto] sm:items-end" role="search">
    <x-ui.input name="q" label="Szukaj" :value="$filters['q']" placeholder="Imię, nazwisko lub e-mail" type="search" />
    <x-ui.select name="role" label="Rola" :options="$roles" :value="$filters['role']" placeholder="Wszystkie role" />
    <x-ui.select name="status" label="Status" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$filters['status']" placeholder="Wszystkie statusy" />
    <div class="flex gap-2">
      <x-ui.button type="submit" variant="secondary">Filtruj</x-ui.button>
      @if (array_filter($filters))
        <x-ui.button :href="route('fmi.users.index')" variant="ghost">Wyczyść</x-ui.button>
      @endif
    </div>
  </form>

  <div class="mt-6">
    @if ($users === [])
      <x-ui.empty-state
        :title="array_filter($filters) ? 'Brak wyników' : 'Brak użytkowników'"
        :description="array_filter($filters) ? 'Zmień kryteria wyszukiwania lub wyczyść filtry.' : 'Dodaj pierwszego pracownika — dostanie zaproszenie e-mailem.'"
      />
    @else
      <div class="relative overflow-x-auto rounded-2xl border border-ink-150 bg-white shadow-xs">
        <table class="w-full text-left text-base">
          <caption class="sr-only">Lista użytkowników Fill Me In</caption>
          <thead class="border-b border-ink-150 bg-ink-50 text-sm text-ink-500">
            <tr>
              <th scope="col" class="px-5 py-3 font-medium sm:px-6">Imię i nazwisko</th>
              <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">E-mail</th>
              <th scope="col" class="hidden px-4 py-3 font-medium sm:table-cell">Rola</th>
              <th scope="col" class="px-4 py-3 font-medium">Status</th>
              <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Dodano</th>
              <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Ostatnie logowanie</th>
              <th scope="col" class="hidden px-5 py-3 text-right font-medium sm:table-cell sm:px-6"><span class="sr-only">Akcje</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-ink-150">
            @foreach ($users as $person)
              @php
                $status = \App\Enums\AccountStatus::of($person);
                $lastLogin = get_user_meta($person->ID, \App\Enums\AccountStatus::META_LAST_LOGIN_AT, true);
              @endphp
              <tr class="transition hover:bg-ink-50">
                <td class="px-5 py-3.5 sm:px-6">
                  <div class="flex min-w-0 items-center gap-3">
                    <x-ui.avatar :person="$person" />
                    <div class="min-w-0">
                      <p class="truncate font-medium text-ink-950">
                        <a href="{{ route('fmi.users.edit', $person->ID) }}" class="rounded hover:underline hover:decoration-ink-300 hover:underline-offset-2">{{ \App\Support\Staff::fullName($person) }}</a>
                        @if ($person->ID === $currentUserId) <span class="font-normal text-ink-500">(Ty)</span> @endif
                      </p>
                      <p class="truncate text-sm text-ink-500 md:hidden">{{ $person->user_email }}</p>
                      <p class="truncate text-sm text-ink-500 sm:hidden">{{ \App\Support\Staff::roleLabel($person) }}</p>
                    </div>
                  </div>
                </td>
                <td class="hidden px-4 py-3.5 text-ink-600 md:table-cell">{{ $person->user_email }}</td>
                <td class="hidden px-4 py-3.5 text-ink-700 sm:table-cell">{{ \App\Support\Staff::roleLabel($person) }}</td>
                <td class="px-4 py-3.5"><x-ui.badge :variant="$status->badge()">{{ $status->label() }}</x-ui.badge></td>
                <td class="hidden px-4 py-3.5 text-sm text-ink-500 lg:table-cell">{{ mysql2date('d.m.Y', $person->user_registered) }}</td>
                <td class="hidden px-4 py-3.5 text-sm text-ink-500 lg:table-cell">
                  {{ $lastLogin ? get_date_from_gmt($lastLogin, 'd.m.Y H:i') : 'Nigdy' }}
                </td>
                <td class="hidden px-5 py-3.5 text-right sm:table-cell sm:px-6">
                  <x-ui.button :href="route('fmi.users.edit', $person->ID)" variant="ghost" size="sm" :aria-label="'Edytuj: ' . \App\Support\Staff::fullName($person)">Edytuj</x-ui.button>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <x-ui.pagination class="mt-6" :page="$page" :last-page="$lastPage" :url="$pageUrl" :total="$total" />
    @endif
  </div>
@endsection
