@extends('fillmein.layouts.app')

@section('title', 'Mapowanie ACF: ' . $project->name)

@section('content')
  <a href="{{ route('fmi.projects.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $project->name }}
  </a>

  <x-ui.page-header eyebrow="Treści" title="Mapowanie ACF" description="Puste pola oznaczają wartości domyślne, wynikające z typu i klucza pola. Mapowanie trafia do export.json w paczce." />

  @include('fillmein.projects.partials.content-tabs', ['project' => $project, 'active' => 'mapping'])

  @if ($errors->any() && ! $errors->has('schema'))
    <x-ui.alert variant="danger" class="mt-6">{{ $errors->first() }}</x-ui.alert>
  @endif

  @include('fillmein.projects.partials.site-schema-upload', ['project' => $project, 'schema' => null, 'class' => 'mt-6'])

  @if (! $page)
    <x-ui.empty-state class="mt-8" title="Brak podstron" description="Dodaj podstrony, aby ustawić mapowanie." />
  @else
    <form method="GET" action="{{ route('fmi.projects.mapping', $project) }}" class="mt-8 flex flex-wrap items-end gap-3">
      <div class="w-full sm:w-80">
        <x-ui.select name="strona" label="Podstrona" :options="$pages" :value="$page->id" />
      </div>
      <x-ui.button type="submit" variant="secondary" size="sm">Pokaż</x-ui.button>
    </form>

    <form method="POST" action="{{ route('fmi.projects.mapping.update', [$project, $page]) }}" class="mt-6 flex flex-col gap-6" data-loading-form>
      @csrf

      <x-ui.card>
        <x-slot:header>
          <h2 class="text-md font-semibold">Podstrona w WordPressie</h2>
        </x-slot:header>

        <div class="grid gap-4 sm:grid-cols-3">
          <x-ui.input name="page[post_type]" id="page-post-type" label="Typ wpisu" :value="old('page.post_type', $form['page']['post_type'])" :error="$errors->first('page.post_type')" required maxlength="20" />
          <x-ui.input name="page[slug]" id="page-slug" label="Slug" :value="old('page.slug', $form['page']['slug'])" :error="$errors->first('page.slug')" maxlength="190" />
          <x-ui.input name="page[template]" id="page-template" label="Szablon" :value="old('page.template', $form['page']['template'])" :error="$errors->first('page.template')" maxlength="190" />
        </div>
      </x-ui.card>

      @foreach ($form['sections'] as $entry)
        @php
          $section = $entry['section'];
        @endphp
        <x-ui.card>
          <x-slot:header>
            <h2 class="min-w-0 truncate text-md font-semibold">{{ $section->name }}</h2>
          </x-slot:header>

          <x-ui.input
            :name="'sections[' . $section->id . '][block]'"
            :id="'section-' . $section->id . '-block'"
            label="Blok ACF"
            :value="old('sections.' . $section->id . '.block', $entry['override'])"
            :error="$errors->first('sections.' . $section->id . '.block')"
            :placeholder="$entry['block']"
            maxlength="190"
          />

          @if ($entry['fields'])
            <ul class="mt-4 divide-y divide-ink-100" role="list">
              @foreach ($entry['fields'] as $row)
                @php
                  $field = $row['field'];
                  $options = array_combine($row['alternatives'], $row['alternatives']);
                @endphp
                <li class="py-3" @style(['margin-left: ' . min($row['depth'], 3) * 0.75 . 'rem' => $row['depth'] > 0])>
                  <p class="flex flex-wrap items-center gap-2 font-medium text-ink-950">
                    {{ $field->label }}
                    <x-ui.badge variant="muted">{{ $field->type->label() }}</x-ui.badge>
                    @unless ($row['acf']['mapped'])
                      <x-ui.badge variant="warning">Niezmapowane</x-ui.badge>
                    @endunless
                  </p>
                  <p class="mt-1 text-sm break-all text-ink-500">{{ $row['acf']['path'] }}</p>
                  @if ($row['acf']['message'])
                    <p class="mt-1 text-sm text-ink-700">{{ $row['acf']['message'] }}</p>
                  @endif

                  <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <x-ui.input
                      :name="'fields[' . $field->id . '][name]'"
                      :id="'field-' . $field->id . '-name'"
                      :label="'Nazwa ACF: ' . $field->label"
                      :value="old('fields.' . $field->id . '.name', $row['name_override'])"
                      :error="$errors->first('fields.' . $field->id . '.name')"
                      :placeholder="$row['default_name']"
                      maxlength="60"
                    />
                    @if (count($options) > 1)
                      <x-ui.select
                        :name="'fields[' . $field->id . '][type]'"
                        :id="'field-' . $field->id . '-type'"
                        :label="'Typ ACF: ' . $field->label"
                        :options="$options"
                        :value="old('fields.' . $field->id . '.type', $row['type_override'] ?? $row['acf']['type'])"
                        :error="$errors->first('fields.' . $field->id . '.type')"
                      />
                    @endif
                  </div>
                </li>
              @endforeach
            </ul>
          @endif
        </x-ui.card>
      @endforeach

      <input type="hidden" name="complete" value="1">
      <div>
        <x-ui.button type="submit">Zapisz mapowanie</x-ui.button>
      </div>
    </form>
  @endif
@endsection
