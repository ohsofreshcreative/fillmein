{{-- Sekcja pominięta w imporcie („Pomiń całą sekcję”) — zostaje na swoim miejscu, wyszarzona, z „Przywróć”. Dane: $node. --}}
<x-ui.card data-import-hidden-section>
  <div class="flex items-center justify-between gap-3">
    <div class="flex min-w-0 items-center gap-3 opacity-50">
      <span aria-hidden="true" class="flex size-10 shrink-0 items-center justify-center rounded-full border border-dashed border-ink-300 text-ink-400">–</span>
      <div class="min-w-0">
        <p class="text-sm text-ink-500">Sekcja ukryta · nie trafi do edycji klienta</p>
        <h2 class="truncate text-md font-semibold text-ink-700 line-through">{{ $node['name'] !== '' ? $node['name'] : $node['node_id'] }}</h2>
      </div>
    </div>
    <form method="POST" action="{{ route('fmi.projects.pages.figma.rules', [$project, $page]) }}" class="shrink-0" data-keep-scroll>
      @csrf
      <input type="hidden" name="node" value="{{ $node['node_id'] }}">
      <input type="hidden" name="action" value="{{ $node['auto_reason'] ? 'include' : 'reset' }}">
      <x-ui.button type="submit" variant="secondary" size="sm">
        Przywróć<span class="sr-only"> sekcję {{ $node['name'] }}</span>
      </x-ui.button>
    </form>
  </div>
</x-ui.card>
