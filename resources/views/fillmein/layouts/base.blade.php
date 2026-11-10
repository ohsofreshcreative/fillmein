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
  @vite(['resources/css/fillmein.css', 'resources/js/fillmein.js'])
</head>
<body class="h-full @yield('body-class')">
  @yield('body')
</body>
</html>
