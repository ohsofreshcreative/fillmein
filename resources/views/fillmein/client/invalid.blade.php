@extends('fillmein.layouts.client')

@section('title', 'Link jest nieaktywny')

@section('content')
  <div class="mx-auto flex max-w-md flex-col items-center py-16 text-center">
    <div class="mb-5 flex size-12 items-center justify-center rounded-2xl bg-ink-100 text-ink-500" aria-hidden="true">
      <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1M4 4l16 16" /></svg>
    </div>
    <h1 class="text-xl font-semibold tracking-tighter">Ten link jest nieaktywny</h1>
    <p class="mt-2 text-md text-ink-600">Link mógł wygasnąć lub zostać unieważniony. Skontaktuj się ze swoim opiekunem w OhSoFresh, aby otrzymać nowy.</p>
  </div>
@endsection
