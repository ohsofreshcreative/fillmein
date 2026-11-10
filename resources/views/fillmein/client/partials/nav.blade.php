{{-- Rekurencyjna lista podstron. Linki są względne (sam parametr), więc HTML nie zawiera tokenu. --}}
<ul @class(['flex flex-col gap-1', 'mt-1 border-l border-ink-150 pl-3' => $depth > 0])>
  @foreach ($nodes as $node)
    @php($isCurrent = (int) $node['page']->id === (int) $current->id)
    <li>
      <a
        href="?strona={{ $node['page']->id }}"
        @if ($isCurrent) aria-current="page" @endif
        @class([
          'block rounded-lg px-3 py-2 text-sm',
          'bg-ink-900 font-medium text-white' => $isCurrent,
          'text-ink-700 hover:bg-ink-100 hover:text-ink-950' => ! $isCurrent,
        ])
      >{{ $node['page']->name }}</a>

      @if ($node['children'] !== [])
        @include('fillmein.client.partials.nav', ['nodes' => $node['children'], 'depth' => $depth + 1])
      @endif
    </li>
  @endforeach
</ul>
