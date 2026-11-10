{{-- Paginacja. $url: callable(int $page): string. --}}
@props(['page', 'lastPage', 'url', 'total' => null])

@if ($lastPage > 1)
  <nav {{ $attributes->class(['flex items-center justify-between gap-4 text-sm']) }} aria-label="Paginacja">
    <span class="text-ink-500">
      Strona {{ $page }} z {{ $lastPage }}@if ($total !== null) · {{ $total }} pozycji @endif
    </span>
    <div class="flex gap-2">
      @if ($page > 1)
        <x-ui.button :href="$url($page - 1)" variant="secondary" size="sm" rel="prev">Poprzednia</x-ui.button>
      @endif
      @if ($page < $lastPage)
        <x-ui.button :href="$url($page + 1)" variant="secondary" size="sm" rel="next">Następna</x-ui.button>
      @endif
    </div>
  </nav>
@endif
