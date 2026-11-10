@extends('fillmein.layouts.client')

@section('title', 'Historia pola')

@section('header-meta')
  <p class="truncate text-sm font-medium text-ink-900">{{ $project->name }}</p>
@endsection

@section('content')
  <div data-editor-scope="p{{ $project->id }}">
    <a href="{{ $backUrl }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
      @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
      Wróć do edytora
    </a>

    <x-ui.page-header eyebrow="Historia pola" :title="\App\Support\ClientLabels::field($field)" description="Przywrócenie dodaje nową wersję, więc poprzednie zostają." />

    <div class="mt-8 max-w-3xl">
      @include('fillmein.client.partials.history', ['forStaff' => false])
    </div>
  </div>
@endsection
