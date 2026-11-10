@extends('fillmein.layouts.client')

@section('title', 'Kod dostępu')

@section('content')
  <div class="mx-auto flex max-w-sm flex-col gap-6 py-12">
    <div class="text-center">
      <h1 class="text-xl font-semibold tracking-tighter">Podaj kod dostępu</h1>
      <p class="mt-2 text-md text-ink-600">Ten link recenzji jest dodatkowo chroniony kodem. Kod przekazuje opiekun projektu.</p>
    </div>
    @if (isset($locked))
      <x-ui.alert variant="danger" data-code-locked>Zbyt wiele błędnych prób. Spróbuj ponownie za {{ $locked }} min.</x-ui.alert>
    @else
      <x-ui.card>
        <form method="POST" action="{{ route('fmi.review.unlock.submit') }}" class="flex flex-col gap-4" data-loading-form>
          @csrf
          <x-ui.input name="code" label="Kod dostępu" type="password" autocomplete="off" required autofocus />
          <x-ui.button type="submit">Otwórz recenzję</x-ui.button>
        </form>
      </x-ui.card>
    @endif
  </div>
@endsection
