{{-- Ikony interfejsu aplikacji (inline SVG, 20×20, obrys). --}}
<svg class="size-5 shrink-0 {{ $class ?? '' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  @switch($name)
    @case('home')
      <path d="M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4.5v-5.5h-5V20H5a1 1 0 0 1-1-1v-8.5Z" />
      @break
    @case('bell')
      <path d="M6 16.5V11a6 6 0 1 1 12 0v5.5l1.5 2h-15l1.5-2Z" /><path d="M10 20.5a2 2 0 0 0 4 0" />
      @break
    @case('folder')
      <path d="M3.5 7.5A1.5 1.5 0 0 1 5 6h4.2l2 2H19a1.5 1.5 0 0 1 1.5 1.5v8A1.5 1.5 0 0 1 19 19H5a1.5 1.5 0 0 1-1.5-1.5v-10Z" />
      @break
    @case('review')
      <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" /><circle cx="12" cy="12" r="3" />
      @break
    @case('users')
      <circle cx="9" cy="8.5" r="3.5" /><path d="M2.5 19.5c.8-3.2 3.4-5 6.5-5s5.7 1.8 6.5 5" /><path d="M15.5 5.2a3.5 3.5 0 0 1 0 6.6M17.5 14.8c2 .6 3.4 2.2 4 4.7" />
      @break
    @case('logout')
      <path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" /><path d="M10 16l-4-4 4-4M6 12h10" />
      @break
    @case('figma')
      <path d="M9 3h3v6H9a3 3 0 0 1 0-6ZM12 3h3a3 3 0 0 1 0 6h-3V3ZM9 9h3v6H9a3 3 0 0 1 0-6ZM15 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6ZM9 15h3v3a3 3 0 1 1-3-3Z" />
      @break
    @case('arrow-left')
      <path d="M19 12H5M11 6l-6 6 6 6" />
      @break
    @case('arrow-up')
      <path d="M12 19V5M6 11l6-6 6 6" />
      @break
    @case('arrow-down')
      <path d="M12 5v14M6 13l6 6 6-6" />
      @break
    @case('grip')
      <circle cx="9" cy="6" r="1" /><circle cx="15" cy="6" r="1" /><circle cx="9" cy="12" r="1" /><circle cx="15" cy="12" r="1" /><circle cx="9" cy="18" r="1" /><circle cx="15" cy="18" r="1" />
      @break
    @case('chevron-down')
      <path d="M6 9l6 6 6-6" />
      @break
    @case('trash')
      <path d="M4.5 7h15M10 11v6M14 11v6M6.5 7l.8 11.2A2 2 0 0 0 9.3 20h5.4a2 2 0 0 0 2-1.8L17.5 7M9.5 7V5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v2" />
      @break
    @case('download')
      <path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19h14" />
      @break
    @default
      <circle cx="12" cy="12" r="8" />
  @endswitch
</svg>
