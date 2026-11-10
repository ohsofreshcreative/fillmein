<?php

/**
 * Etap 09 — przyjmowanie plików, przypisania, galerie, rewizje i postęp. Baza w transakcji wycofywanej;
 * pliki zapisane na dysku trafiają do manifestu sprzątania.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Exceptions\ContentConflictException;
use App\Models\Asset;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Services\AssetService;
use App\Services\ClientAccessService;
use App\Services\ClientEditorService;
use App\Services\ContentService;
use App\Services\PageService;
use App\Services\ProjectService;
use App\Services\SectionService;
use App\Support\Actor;
use App\Support\ProjectProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;

if (! $admin) {
	fwrite(STDERR, "BLOCKED: brak użytkownika administratora do wykonania testów.\n");
	exit(2);
}

wp_set_current_user($admin->ID);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];
$throws = [Harness::class, 'throws'];

$assets = app(AssetService::class);
$content = app(ContentService::class);
$editor = app(ClientEditorService::class);
$sections = app(SectionService::class);
$pages = app(PageService::class);
$projects = app(ProjectService::class);
$actor = Actor::user($admin);
$disk = Storage::disk('fmi');
$fixture = fn (string $file, ?string $name = null) => new UploadedFile(__DIR__ . '/fixtures/' . $file, $name ?? $file, null, null, true);
$keep = function (Asset $asset) use ($disk) {
	$path = '/tmp/fmi-stage09-files.json';
	$known = json_decode((string) @file_get_contents($path), true) ?: [];

	foreach ([$asset->path, ...array_values($asset->variants ?? [])] as $relative) {
		$known[] = $disk->path($relative);
	}

	file_put_contents($path, json_encode(array_values(array_unique($known))));

	return $asset;
};

DB::beginTransaction();

try {
	$project = $projects->create(['name' => 'TEST etap09'], $admin);
	$other = $projects->create(['name' => 'TEST etap09 obcy'], $admin);
	$page = $pages->create($project, ['name' => 'Strona'], $admin);
	$section = $sections->create($page, ['name' => 'Sekcja'], $admin);
	$otherSection = $sections->create($pages->create($other, ['name' => 'Obca'], $admin), ['name' => 'Obca'], $admin);
	$make = fn (array $data, $in = null) => $sections->createField($in ?? $section, $data + ['type' => 'image', 'label' => 'Pole'], $admin);
	$image = $make(['label' => 'Zdjęcie']);
	$gallery = $make(['type' => 'gallery', 'label' => 'Galeria', 'settings' => ['max_items' => 3]]);
	$store = fn (string $file, $field = null, ?string $name = null) => $keep($assets->store($fixture($file, $name), $field ?? $gallery, $actor));

	$t('Akceptowane typy: rozpoznanie po zawartości', function () use ($store, $same) {
		foreach ([['ok.png', 'image/png', 'png'], ['ok.jpg', 'image/jpeg', 'jpg'], ['ok.webp', 'image/webp', 'webp'], ['ok.gif', 'image/gif', 'gif']] as [$file, $mime, $extension]) {
			$asset = $store($file);

			$same($mime, $asset->mime_type, "{$file}: MIME");
			$same($extension, $asset->extension, "{$file}: rozszerzenie");
		}

		$same('image/png', $store('second.png', null, 'udaje.jpg')->mime_type, 'Nazwa pliku nie decyduje o typie');
	});

	$t('Odrzucane: PHP jako .jpg, SVG ze skryptem, za duży, nieobsługiwany, uszkodzony, bomba pikselowa', function () use ($assets, $fixture, $gallery, $actor, $throws, $same) {
		foreach (['php-as.jpg', 'script.svg', 'oversized.png', 'unsupported.bmp', 'note.txt', 'broken.png', 'bomb.png'] as $file) {
			$throws(ValidationException::class, fn () => $assets->store($fixture($file), $gallery, $actor), $file);
		}

		$same(0, Asset::query()->where('project_id', $gallery->project_id)->where('original_name', 'like', 'php-as%')->count(), 'Bez wiersza');
	});

	$t('Minimalne wymiary z ustawień pola', function () use ($assets, $store, $make, $fixture, $actor, $throws, $ok) {
		$sized = $make(['label' => 'Rozmiar', 'settings' => ['min_width' => 100, 'min_height' => 100]]);

		$throws(ValidationException::class, fn () => $assets->store($fixture('small.png'), $sized, $actor), 'Za małe');
		$ok($store('third.png', $sized)->exists, 'Wystarczające');
	});

	$t('Plik: losowa ścieżka, suma SHA-256, bezpieczna nazwa, miniatury, wymiary, prawa 0600', function () use ($store, $disk, $image, $project, $same, $ok) {
		$asset = $store('fourth.png', $image, '../../złośliwy<script>.php.png');

		$same(hash_file('sha256', __DIR__ . '/fixtures/fourth.png'), $asset->checksum, 'Suma');
		$same(sprintf('projects/%s/%s/original.png', $project->uuid, $asset->uuid), $asset->path, 'Ścieżka z UUID');
		$ok(! str_contains($asset->original_name, '/') && ! str_contains($asset->original_name, '<'), 'Nazwa bez znaków specjalnych');
		$same([640, 480], [$asset->width, $asset->height], 'Wymiary');
		$ok($disk->exists($asset->path), 'Plik na dysku');
		$ok($disk->exists($asset->variants['thumb']), 'Miniatura');
		$ok(! isset($asset->variants['preview']), 'Bez powiększania: obraz mniejszy od podglądu');
		$same(400, getimagesize($disk->path($asset->variants['thumb']))[0], 'Szerokość miniatury');
		$same('0600', substr(sprintf('%o', fileperms($disk->path($asset->path))), -4), 'Prawa pliku');
		$ok($disk->exists('.htaccess') && $disk->exists('index.php'), 'Pliki blokujące');
		$ok(! array_key_exists('path', $asset->toArray()) && ! array_key_exists('variants', $asset->toArray()), 'Ścieżki poza serializacją');
	});

	$t('Deduplikacja: w projekcie ten sam wiersz, w innym projekcie osobny', function () use ($store, $make, $otherSection, $same, $ok) {
		$first = $store('fifth.png');
		$second = $store('fifth.png', null, 'inna-nazwa.png');
		$foreign = $store('fifth.png', $make(['label' => 'Obce'], $otherSection));

		$same($first->id, $second->id, 'Ten sam wiersz');
		$ok($foreign->id !== $first->id, 'Inny projekt');
		$ok($foreign->path !== $first->path, 'Inny plik');
	});

	$t('Zdjęcie: przypięcie zastępuje poprzednie, a każda zmiana to jedna rewizja', function () use ($store, $content, $image, $actor, $same) {
		$first = $store('ok.png', $image);
		$second = $store('second.png', $image);
		$value = $content->attachAsset($image, $first, 0, $actor);
		$value = $content->attachAsset($image, $second, $value->version, $actor);

		$same([$second->uuid], ContentService::rowList($value), 'Lista');
		$same([$second->id], $value->assets()->pluck('fmi_assets.id')->all(), 'Powiązanie');
		$same(2, FieldRevision::query()->where('field_value_id', $value->id)->count(), 'Rewizje');
		$same(json_encode([$second->uuid]), FieldRevision::query()->where('field_value_id', $value->id)->orderByDesc('id')->value('value'), 'Treść rewizji');
	});

	$t('Galeria: kolejność w wartości i w powiązaniach, limit, konflikt', function () use ($store, $content, $gallery, $actor, $same, $throws) {
		[$a, $b, $c] = [$store('ok.png'), $store('second.png'), $store('third.png')];
		$value = $content->attachAsset($gallery, $a, 0, $actor);
		$value = $content->attachAsset($gallery, $b, $value->version, $actor);
		$throws(ValidationException::class, fn () => $content->attachAsset($gallery, $a, $value->version, $actor), 'Duplikat');
		$value = $content->attachAsset($gallery, $c, $value->version, $actor);

		$throws(ValidationException::class, fn () => $content->attachAsset($gallery, $store('fourth.png'), $value->version, $actor), 'Limit');
		$throws(ContentConflictException::class, fn () => $content->reorderAssets($gallery, [$c->uuid, $a->uuid, $b->uuid], 1, $actor), 'Stara wersja');
		$throws(ValidationException::class, fn () => $content->reorderAssets($gallery, [$a->uuid], $value->version, $actor), 'Niepełna lista');

		$value = $content->reorderAssets($gallery, [$c->uuid, $a->uuid, $b->uuid], $value->version, $actor);
		$same([$c->uuid, $a->uuid, $b->uuid], ContentService::rowList($value), 'Lista');
		$same([$c->id, $a->id, $b->id], $value->assets()->pluck('fmi_assets.id')->all(), 'Pozycje powiązań');

		$revisions = FieldRevision::query()->where('field_value_id', $value->id)->count();
		$same(4, $revisions, 'Rewizja na każdą zmianę');

		$detached = $content->detachAsset($gallery, $a, $value->version, $actor);
		$same([$c->uuid, $b->uuid], ContentService::rowList($detached), 'Po odpięciu');
	});

	$t('Odpięcie: plik i wiersz zostają', function () use ($store, $content, $make, $actor, $disk, $same, $ok) {
		$field = $make(['label' => 'Odpinanie']);
		$asset = $store('sixth.png', $field);
		$value = $content->attachAsset($field, $asset, 0, $actor);
		$content->detachAsset($field, $asset, $value->version, $actor);

		$fresh = Asset::query()->findOrFail($asset->id);

		$same(null, $fresh->deleted_at, 'Wiersz bez zmian');
		$ok($disk->exists($fresh->path), 'Oryginał');
		$ok($disk->exists($fresh->variants['thumb']), 'Miniatura');
		$same(0, $value->fresh()->assets()->count(), 'Bez powiązania');
	});

	$t('Odrzucane przypięcia: plik innego projektu, pole archiwalne, typ pola', function () use ($store, $content, $make, $sections, $otherSection, $actor, $admin, $throws) {
		$foreign = $store('ok.gif', $make(['label' => 'Obce'], $otherSection));
		$archived = $make(['label' => 'Archiwum']);
		$sections->archiveField($archived, $admin);

		$throws(\InvalidArgumentException::class, fn () => $content->attachAsset($make(['label' => 'Tu']), $foreign, 0, $actor), 'Plik innego projektu');
		$throws(ValidationException::class, fn () => $content->attachAsset($archived->fresh(), $store('ok.webp'), 0, $actor), 'Archiwum');
		$throws(\InvalidArgumentException::class, fn () => $content->attachAsset($make(['type' => 'text', 'label' => 'Tekst']), $store('ok.webp'), 0, $actor), 'Pole tekstowe');
	});

	$t('Pliki w wierszu repeatera mają osobną wartość', function () use ($store, $content, $make, $actor, $same) {
		$repeater = $make(['type' => 'repeater', 'label' => 'Lista']);
		$child = $make(['label' => 'Zdjęcie w wierszu', 'parent_id' => $repeater->id]);
		[$row, $value] = $content->addRow($repeater, 0, $actor);
		[$secondRow] = $content->addRow($repeater, $value->version, $actor);

		$content->attachAsset($child, $store('ok.png', $child), 0, $actor, $row);
		$same(0, FieldValue::query()->where('field_id', $child->id)->where('row_key', $secondRow->uuid)->count(), 'Drugi wiersz pusty');
	});

	$t('Postęp: zdjęcie, galeria z min_items, wymagany tekst alternatywny', function () use ($projects, $pages, $sections, $make, $store, $content, $actor, $admin, $same) {
		$project = $projects->create(['name' => 'TEST etap09 postęp'], $admin);
		$section = $sections->create($pages->create($project, ['name' => 'Strona'], $admin), ['name' => 'Sekcja'], $admin);
		$image = $make(['label' => 'Wymagane', 'is_required' => true, 'settings' => ['require_alt' => 1]], $section);
		$gallery = $make(['type' => 'gallery', 'label' => 'Min', 'is_required' => true, 'settings' => ['min_items' => 2]], $section);
		$stats = fn () => ProjectProgress::forProjectIds([$project->id])[$project->id];

		$same([0, 2], [$stats()['filled'], $stats()['total']], 'Puste');

		$asset = $store('ok.png', $image);
		$content->attachAsset($image, $asset, 0, $actor);
		$same(0, $stats()['filled'], 'Zdjęcie bez opisu nie wystarcza');

		$asset->update(['alt' => 'Opis']);
		$same(1, $stats()['filled'], 'Zdjęcie z opisem');

		$value = $content->attachAsset($gallery, $store('second.png', $gallery), 0, $actor);
		$same(1, $stats()['filled'], 'Galeria poniżej minimum');

		$content->attachAsset($gallery, $store('third.png', $gallery), $value->version, $actor);
		$same(2, $stats()['filled'], 'Galeria z minimum');
		$same(100, $stats()['percent'], 'Komplet');
	});

	$t('Edytor: tekst alternatywny tylko dla pliku przypiętego do pola', function () use ($store, $editor, $content, $make, $project, $admin, $actor, $same) {
		$access = app(ClientAccessService::class)->generate($project, $admin)->access;
		$field = $make(['label' => 'Alt']);
		$asset = $store('ok.png', $field);
		$content->attachAsset($field, $asset, 0, $actor);
		$loose = $store('second.png', $field);

		$same('Opis klienta', $editor->updateAlt($access, $field, null, $asset->uuid, ' Opis klienta ')->alt, 'Zapis');

		try {
			$editor->updateAlt($access, $field, null, $loose->uuid, 'x');
			$same(404, 200, 'Plik nieprzypięty');
		} catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
			$same(404, $error->getStatusCode(), 'Plik nieprzypięty');
		}

		$rendered = $editor->structure($field->section->page)->assets($field, null);
		$same(['Opis klienta'], $rendered->pluck('alt')->all(), 'Struktura zwraca opis');
	});
} finally {
	DB::rollBack();
}

exit(Harness::summary('ETAP 09 — logika'));
