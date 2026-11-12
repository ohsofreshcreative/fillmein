<?php

/**
 * Pomocnik testu: allow <page> (włącza client_can_duplicate), state <page> (JSON: podstrony projektu, sekcje, pola, wartości).
 */

use App\Models\Page;

[$mode, $id] = $args;
$page = Page::query()->findOrFail((int) $id);

if ($mode === 'allow') {
	$page->forceFill(['client_can_duplicate' => true])->save();
	exit(0);
}

$pages = Page::query()->where('project_id', $page->project_id)->orderBy('id')->get();
echo json_encode($pages->map(fn (Page $p) => [
	'id' => $p->id,
	'name' => $p->name,
	'from' => $p->duplicated_from_id,
	'archived' => $p->archived_at !== null,
	'sections' => $p->sections()->whereNull('archived_at')->count(),
	// Aktywne pola w aktywnych sekcjach, bez pól pod zarchiwizowanym rodzicem (duplikat ich nie kopiuje).
	'fields' => \App\Models\Field::query()->whereIn('section_id', $p->sections()->whereNull('archived_at')->pluck('id'))->whereNull('archived_at')
		->where(fn ($q) => $q->whereNull('parent_id')->orWhereIn('parent_id', \App\Models\Field::query()->whereNull('archived_at')->select('id')))->count(),
	'values' => \App\Models\FieldValue::query()->whereIn('field_id', \App\Models\Field::query()->whereIn('section_id', $p->sections()->pluck('id'))->pluck('id'))->whereNotNull('value')->count(),
])->values(), JSON_UNESCAPED_UNICODE);
