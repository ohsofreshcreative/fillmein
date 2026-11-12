{{--
  Klient: „Dodaj kolejną taką podstronę” (gdy zespół zezwolił — client_can_duplicate) i usunięcie podstrony,
  którą klient sam dodał ($canRemove). Adres akcji składa JS z location.pathname, bo HTML nie może zawierać tokenu.
--}}
@if ($current->client_can_duplicate || $canRemove)
  <div class="flex flex-wrap items-start justify-between gap-3 rounded-2xl border border-ink-150 bg-white px-5 py-4" data-client-page-actions>
    @if ($current->client_can_duplicate)
      <details class="min-w-0 flex-1" @if ($errors->has('name')) open @endif>
        <summary class="cursor-pointer text-sm font-medium text-ink-900">+ Dodaj kolejną taką podstronę</summary>
        <p class="mt-2 text-sm text-ink-500">
          Powstanie nowa podstrona z takimi samymi sekcjami i polami jak „{{ $current->name }}” — z pustą treścią do uzupełnienia (np. dla kolejnego produktu).
        </p>
        <form
          method="POST"
          x-data
          x-bind:action="location.pathname.replace(/\/+$/, '') + '/pages/{{ $current->id }}/duplicate'"
          class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end"
          data-loading-form
          data-client-duplicate
        >
          @csrf
          <x-ui.input name="name" label="Nazwa nowej podstrony" required maxlength="190" class="sm:flex-1" placeholder="np. Płyta DK-NG Grafit" />
          <x-ui.button type="submit">Dodaj podstronę</x-ui.button>
        </form>
      </details>
    @endif

    @if ($canRemove)
      <form
        method="POST"
        x-data
        x-bind:action="location.pathname.replace(/\/+$/, '') + '/pages/{{ $current->id }}/archive'"
        x-on:submit="if (! confirm({{ \Illuminate\Support\Js::from('Usunąć podstronę „' . $current->name . '”? Zespół OhSoFresh może ją przywrócić.') }})) $event.preventDefault()"
        class="shrink-0"
        data-client-remove
      >
        @csrf
        <x-ui.button type="submit" variant="ghost" size="sm">Usuń tę podstronę</x-ui.button>
      </form>
    @endif
  </div>
@endif
