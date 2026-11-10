{{-- Szkielet HTML aplikacji. Celowo bez wp_head()/wp_footer(): aplikacja ma własny frontend,
     niezależny od assetów strony i paska admina WordPressa. --}}
<!doctype html>
<html lang="pl" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="@yield('referrer', 'same-origin')">
  <meta name="csrf-token" content="{{ rescue(fn () => csrf_token(), '', false) }}">
  <title>@hasSection('title')@yield('title') · @endif{{ 'Fill Me In' }}</title>
  {{-- Layout nie wywołuje wp_head(), więc ikonę witryny (Ustawienia → Ogólne) wstawiamy sami. --}}
  @if (has_site_icon())
    <link rel="icon" href="{{ get_site_icon_url(32) }}" sizes="32x32">
    <link rel="icon" href="{{ get_site_icon_url(192) }}" sizes="192x192">
    <link rel="apple-touch-icon" href="{{ get_site_icon_url(180) }}">
  @endif
  @vite(['resources/css/fillmein.css', 'resources/js/fillmein.js'])
</head>
<body class="h-full @yield('body-class')">
  @yield('body')
</body>
</html>
