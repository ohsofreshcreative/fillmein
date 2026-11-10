{{-- Zakładki modułu Treści w projekcie. Dane: $project, $active (review|mapping|exports). Zakładka tylko z uprawnieniem. --}}
@php
  $tabs = array_filter([
    'review' => \Illuminate\Support\Facades\Gate::allows('editContent', $project) ? ['Przegląd treści', route('fmi.projects.review', $project)] : null,
    'mapping' => \Illuminate\Support\Facades\Gate::allows('export', $project) ? ['Mapowanie ACF', route('fmi.projects.mapping', $project)] : null,
    'exports' => \Illuminate\Support\Facades\Gate::allows('export', $project) ? ['Eksport', route('fmi.projects.exports', $project)] : null,
  ]);
@endphp
<nav aria-label="Moduł Treści" class="mt-6 border-b border-ink-150" data-content-tabs>
  <ul class="-mb-px flex gap-1 overflow-x-auto" role="list">
    @foreach ($tabs as $key => [$label, $href])
      <li>
        <a
          href="{{ $href }}"
          @if ($key === $active) aria-current="page" @endif
          @class([
            'inline-flex items-center border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fresh-700',
            'border-ink-900 text-ink-950' => $key === $active,
            'border-transparent text-ink-500 hover:border-ink-300 hover:text-ink-900' => $key !== $active,
          ])
        >{{ $label }}</a>
      </li>
    @endforeach
  </ul>
</nav>
