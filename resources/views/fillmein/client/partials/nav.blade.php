{{-- Rekurencyjna lista podstron. Linki są względne (sam parametr), więc HTML nie zawiera tokenu. --}}
@php
  // Podstrony dodane jako „kolejna taka” (duplicated_from_id) pokazujemy zaraz pod oryginałem, jako jedną grupę.
  $byId = collect($nodes)->keyBy(fn ($node) => (int) $node['page']->id);
  $root = function ($node) use ($byId) {
    $seen = [];
    while (($from = (int) $node['page']->duplicated_from_id) && $byId->has($from) && ! in_array($from, $seen, true)) {
      $seen[] = $from;
      $node = $byId[$from];
    }
    return (int) $node['page']->id;
  };
  $copies = collect($nodes)->filter(fn ($node) => $root($node) !== (int) $node['page']->id)->groupBy($root);
  $ordered = [];
  foreach ($nodes as $node) {
    if ($root($node) !== (int) $node['page']->id) {
      continue;
    }
    $node['copy'] = false;
    $node['family'] = $copies->has((int) $node['page']->id);
    $ordered[] = $node;
    foreach ($copies->get((int) $node['page']->id, []) as $copy) {
      $copy['copy'] = true;
      $copy['family'] = true;
      $ordered[] = $copy;
    }
  }
@endphp
<ul @class(['flex flex-col gap-1', 'mt-1 border-l border-ink-150 pl-3' => $depth > 0])>
  @foreach ($ordered as $node)
    @php($isCurrent = (int) $node['page']->id === (int) $current->id)
    <li @class(['ml-3 border-l-2 border-fresh-400 pl-2' => $node['copy']])>
      <a
        href="?strona={{ $node['page']->id }}"
        @if ($isCurrent) aria-current="page" @endif
        @class([
          'block rounded-lg px-3 py-2 text-sm',
          'bg-ink-900 font-medium text-white' => $isCurrent,
          'text-ink-700 hover:bg-ink-100 hover:text-ink-950' => ! $isCurrent,
        ])
        @if ($node['family']) title="Podstrony tego samego typu" @endif
      >
        <span class="flex items-center justify-between gap-2">
          <span class="min-w-0 truncate">{{ $node['page']->name }}</span>
          @if ($node['family'] && ! $node['copy'])
            <span class="shrink-0 rounded-full bg-fresh-400 px-1.5 text-xs text-ink-950" aria-label="{{ $copies->get((int) $node['page']->id)->count() + 1 }} podstrony tego samego typu">×{{ $copies->get((int) $node['page']->id)->count() + 1 }}</span>
          @endif
        </span>
      </a>

      @if ($node['children'] !== [])
        @include('fillmein.client.partials.nav', ['nodes' => $node['children'], 'depth' => $depth + 1])
      @endif
    </li>
  @endforeach
</ul>
