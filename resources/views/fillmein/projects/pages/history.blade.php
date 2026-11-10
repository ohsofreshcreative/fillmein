@extends('fillmein.layouts.app')

@section('title', 'Historia pola')

@section('content')
  <div data-editor-scope="s{{ $project->id }}" data-editor-base="{{ route('fmi.projects.pages.content.edit', [$project, $page]) }}">
    <a href="{{ route('fmi.projects.pages.content.edit', [$project, $page]) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
      @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
      {{ $page->name }}
    </a>

    <x-ui.page-header eyebrow="Historia pola" :title="$field->label" description="Przywrócenie dodaje nową wersję, więc poprzednie zostają." />

    <div class="mt-8 max-w-3xl">
      @include('fillmein.client.partials.history', ['forStaff' => true])
    </div>
  </div>
@endsection
