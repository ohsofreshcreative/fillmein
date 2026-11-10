@extends('fillmein.layouts.client')

@section('title', 'Brak uprawnień')

@section('content')
  <div class="mx-auto flex max-w-md flex-col items-center py-16 text-center">
    <h1 class="text-xl font-semibold tracking-tighter">Ten link nie pozwala na tę operację</h1>
    <p class="mt-2 text-md text-ink-600">Zakres linku recenzji ustala opiekun projektu w OhSoFresh.</p>
    <x-ui.button :href="route('fmi.review.show')" variant="secondary" class="mt-6">Wróć do recenzji</x-ui.button>
  </div>
@endsection
