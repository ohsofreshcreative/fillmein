{{-- Numerowane kotwice sekcji podstrony. Dane: $structure. --}}
@if ($structure->sections->count() > 1)
  <nav aria-label="Sekcje tej podstrony" class="min-w-0 flex-1">
    <ul class="flex gap-2 overflow-x-auto xl:flex-wrap">
      @foreach ($structure->sections as $section)
        @php($sectionName = \App\Support\ClientLabels::section($section, $structure))
        <li class="shrink-0">
          <a href="#sekcja-{{ $section->id }}" title="{{ $sectionName }}" class="inline-flex items-center gap-2 rounded-full border border-ink-200 bg-white py-1 pr-3 pl-1 text-sm text-ink-800 transition hover:border-fresh-500 hover:bg-fresh-50">
            <span aria-hidden="true" class="flex size-6 items-center justify-center rounded-full bg-fresh-400 text-xs font-semibold text-ink-950">{{ $loop->iteration }}</span>
            {{ \Illuminate\Support\Str::limit($sectionName, 32) }}
          </a>
        </li>
      @endforeach
    </ul>
  </nav>
@endif
