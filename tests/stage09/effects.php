<?php

/**
 * Etap 09 — skutki żądań HTTP w bazie i na dysku. Tokeny nie są wypisywane.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Activity;
use App\Models\Asset;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Support\ProjectProgress;
use Illuminate\Support\Facades\Storage;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) @file_get_contents('/tmp/fmi-stage09-ids.json'), true);
$http = json_decode((string) @file_get_contents('/tmp/fmi-stage09-http.json'), true) ?: [];
@unlink('/tmp/fmi-stage09-http.json');
$disk = Storage::disk('fmi');
$value = fn (string $name) => FieldValue::query()->where('field_id', $ids['fields'][$name])->first();
$project = (int) $manifest['projects']['a'];

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$t('Odrzucone pliki nie zostawiły śladu', function () use ($project, $same) {
	$names = Asset::query()->where('project_id', $project)->pluck('original_name')->implode(' ');

	foreach (['php-as', 'script', 'oversized', 'unsupported', 'note', 'broken', 'bomb'] as $name) {
		$same(false, str_contains($names, $name), "Bez wiersza: {$name}");
	}
});

$t('Galeria: kolejność po sortowaniu w wartości i powiązaniach', function () use ($value, $http, $same) {
	$gallery = $value('gallery');
	$list = json_decode($gallery->value, true);

	$same($http['gallery_order'], $list, 'Lista');
	$same($list, $gallery->assets()->pluck('uuid')->all(), 'Pozycje powiązań');
});

$t('Tekst alternatywny zapisany', function () use ($http, $same) {
	$same('Opis z testu', Asset::query()->where('uuid', $http['gallery_order'][0])->value('alt'), 'Alt');
});

$t('Odpięty plik: wiersz i pliki na dysku istnieją', function () use ($http, $value, $disk, $same, $ok) {
	$asset = Asset::query()->where('uuid', $http['detached'])->first();

	$ok($asset !== null, 'Wiersz fmi_assets');
	$same(null, $asset->deleted_at, 'Bez usunięcia');
	$ok($disk->exists($asset->path), 'Oryginał');
	$ok(collect($asset->variants ?? [])->every(fn ($path) => $disk->exists($path)), 'Warianty');
	$ok(! in_array($http['detached'], json_decode($value('gallery')->value, true), true), 'Poza listą pola');
});

$t('Zmiany obrazów: rewizje z autorem', function () use ($value, $same, $ok) {
	$gallery = $value('gallery');
	$revisions = FieldRevision::query()->where('field_value_id', $gallery->id)->orderBy('version')->get();

	$same($gallery->version, $revisions->count(), 'Rewizja na każdą wersję');
	$same('client', $revisions->first()->actor_type->value, 'Autor klient');
	$same(FieldRevision::query()->where('field_value_id', $value('image')->id)->count(), $value('image')->version, 'Zdjęcie: rewizje');
});

$t('Postęp liczy zdjęcia', function () use ($project, $ids, $same) {
	$stats = ProjectProgress::forProject($project)['project'];

	$same(true, $stats['required'], 'Wariant wymaganych');
	$same(2, $stats['filled'], 'Zdjęcie z opisem i galeria z minimum');
});

$t('Dziennik: zdarzenia plików bez ścieżek', function () use ($manifest, $project, $ok) {
	$events = Activity::query()->where('id', '>', (int) $manifest['activity_watermark'])->where('project_id', $project)->get();

	foreach (['content.asset_added', 'content.asset_removed', 'content.assets_reordered'] as $event) {
		$ok($events->contains('event', $event), "Wpis {$event}");
	}

	$ok(! str_contains($events->toJson(), 'original.png'), 'Bez ścieżek');
});

exit(Harness::summary('ETAP 09 — skutki'));
