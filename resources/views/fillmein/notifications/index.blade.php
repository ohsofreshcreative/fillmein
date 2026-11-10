@extends('fillmein.layouts.app')

@section('title', 'Powiadomienia')

@section('content')
  <x-ui.page-header eyebrow="Design Review" title="Powiadomienia" description="Uwagi, odpowiedzi i decyzje w projektach, do których należysz.">
    <x-slot:actions>
      @if ($unread > 0)
        <form method="POST" action="{{ route('fmi.notifications.read-all') }}" data-loading-form>
          @csrf
          <x-ui.button type="submit" variant="secondary" data-read-all>Oznacz wszystkie jako przeczytane</x-ui.button>
        </form>
      @endif
    </x-slot:actions>
  </x-ui.page-header>

  @if (session('status'))
    <x-ui.alert variant="success" class="mt-6">{{ session('status') }}</x-ui.alert>
  @endif

  <nav class="mt-6 flex gap-2" aria-label="Filtr powiadomień">
    <a href="{{ route('fmi.notifications.index') }}" @if (! $unreadOnly) aria-current="page" @endif @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-ink-100 text-ink-950' => ! $unreadOnly, 'text-ink-600 hover:bg-ink-50' => $unreadOnly])>Wszystkie</a>
    <a href="{{ route('fmi.notifications.index', ['filtr' => 'nieprzeczytane']) }}" @if ($unreadOnly) aria-current="page" @endif @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-ink-100 text-ink-950' => $unreadOnly, 'text-ink-600 hover:bg-ink-50' => ! $unreadOnly])>Nieprzeczytane <span data-unread-count>{{ $unread }}</span></a>
  </nav>

  <div class="mt-4">
    @if ($notifications->isEmpty())
      <x-ui.empty-state title="Brak powiadomień" description="Gdy ktoś doda uwagę, odpowie albo podejmie decyzję w Twoim projekcie, zobaczysz to tutaj." />
    @else
      <ul class="flex flex-col divide-y divide-ink-150 rounded-2xl border border-ink-150 bg-white" data-notifications>
        @foreach ($notifications as $item)
          <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:justify-between" data-notification="{{ $item->id }}" data-read="{{ $item->read_at ? '1' : '0' }}">
            <div class="min-w-0">
              <a href="{{ route('fmi.notifications.open', $item->id) }}" class="font-medium text-ink-950 underline-offset-2 hover:underline" data-notification-link>
                @if (! $item->read_at)<span class="sr-only">Nieprzeczytane: </span>@endif{{ $item->title() }}
              </a>
              <p class="mt-0.5 text-sm text-ink-500">
                {{ $item->project?->name }} · <time datetime="{{ $item->created_at?->toIso8601String() }}">{{ $item->created_at?->format('d.m.Y H:i') }}</time>
              </p>
              @if (! empty($item->data['excerpt']))
                <p class="mt-1 text-sm break-words text-ink-700" data-notification-excerpt>{{ $item->data['excerpt'] }}</p>
              @endif
            </div>
            <div class="flex shrink-0 items-center gap-2">
              @if ($item->read_at)
                <x-ui.badge variant="muted">Przeczytane</x-ui.badge>
              @else
                <x-ui.badge variant="fresh">Nowe</x-ui.badge>
                <form method="POST" action="{{ route('fmi.notifications.read', $item->id) }}">
                  @csrf
                  <x-ui.button type="submit" variant="ghost" size="sm" data-mark-read>Oznacz jako przeczytane</x-ui.button>
                </form>
              @endif
            </div>
          </li>
        @endforeach
      </ul>
    @endif
  </div>
@endsection
