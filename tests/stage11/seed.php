<?php

/**
 * Etap 11 — pola wszystkich typów z treścią. Token klienta trafia wyłącznie do /tmp/fmi-stage11-ids.json (0600).
 * Świadome braki: wymagane puste pole, za mało zdjęć, zdjęcie bez ALT, puste pole w wierszu, pole niezmapowane.
 */

use App\Models\Page;
use App\Models\Project;
use App\Models\WpMapping;
use App\Services\AssetService;
use App\Services\ClientAccessService;
use App\Services\ContentService;
use App\Services\PageService;
use App\Services\SectionService;
use App\Support\Actor;
use Illuminate\Http\UploadedFile;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych etapu 03.\n");
	exit(2);
}

$admin = get_userdata((int) $manifest['users']['admin']);
$service = app(SectionService::class);
$content = app(ContentService::class);
$assets = app(AssetService::class);
$actor = Actor::user($admin);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$home = Page::query()->findOrFail((int) $manifest['pages']['a']);
$child = app(PageService::class)->create($project, ['name' => 'O nas', 'parent_id' => $home->id], $admin);
$retired = app(PageService::class)->create($project, ['name' => 'Stara strona'], $admin);
app(PageService::class)->archive($retired, $admin);

$temp = tempnam(sys_get_temp_dir(), 'fmi11');
$upload = function (int $red) use ($temp) {
	$image = imagecreatetruecolor(320, 200);
	imagefill($image, 0, 0, imagecolorallocate($image, $red, 90, 140));
	imagepng($image, $temp);
	imagedestroy($image);

	return new UploadedFile($temp, "zdjecie-{$red}.png", null, null, true);
};

$make = fn ($section, array $data) => $service->createField($section, $data + ['label' => 'Pole'], $admin);
$hero = $service->create($home, ['name' => 'Główna sekcja'], $admin);
$values = $service->create($home, ['name' => 'Wartości'], $admin);
$about = $service->create($child, ['name' => 'Główna sekcja'], $admin);

$title = $make($hero, ['type' => 'text', 'label' => 'Nagłówek', 'is_required' => true, 'settings' => ['max_length' => 120]]);
$lead = $make($hero, ['type' => 'textarea', 'label' => 'Wstęp']);
$body = $make($hero, ['type' => 'wysiwyg', 'label' => 'Opis']);
$button = $make($hero, ['type' => 'link', 'label' => 'Przycisk']);
$photo = $make($hero, ['type' => 'image', 'label' => 'Zdjęcie', 'settings' => ['require_alt' => 1]]);
$gallery = $make($hero, ['type' => 'gallery', 'label' => 'Galeria', 'settings' => ['min_items' => 3]]);
$empty = $make($hero, ['type' => 'text', 'label' => 'Podtytuł', 'is_required' => true]);
$hidden = $make($hero, ['type' => 'text', 'label' => 'Notatka', 'is_hidden' => true]);
$old = $make($hero, ['type' => 'text', 'label' => 'Stare pole']);
$service->archiveField($old, $admin);

$group = $make($values, ['type' => 'group', 'label' => 'Wartości']);
$intro = $make($values, ['type' => 'text', 'label' => 'Nagłówek listy', 'parent_id' => $group->id]);
$repeater = $make($values, ['type' => 'repeater', 'label' => 'Lista', 'parent_id' => $group->id]);
$itemTitle = $make($values, ['type' => 'text', 'label' => 'Tytuł', 'is_required' => true, 'parent_id' => $repeater->id]);
$itemPhoto = $make($values, ['type' => 'image', 'label' => 'Ikona', 'parent_id' => $repeater->id]);
$nested = $make($values, ['type' => 'repeater', 'label' => 'Punkty', 'parent_id' => $repeater->id]);
$point = $make($values, ['type' => 'text', 'label' => 'Punkt', 'parent_id' => $nested->id]);
$unmapped = $make($about, ['type' => 'text', 'label' => 'Pole z obcym mapowaniem']);

$content->save($title, 'Tytuł strony', 0, $actor);
$content->save($lead, "Pierwszy wiersz\nDrugi wiersz", 0, $actor);
$content->save($body, '<p>Opis <strong>projektu</strong></p>', 0, $actor);
$content->save($button, json_encode(['label' => 'Kontakt', 'url' => 'https://example.com/kontakt']), 0, $actor);
$content->attachAsset($photo, $assets->store($upload(10), $photo, $actor), 0, $actor);

$first = $assets->store($upload(60), $gallery, $actor);
$second = $assets->store($upload(110), $gallery, $actor);
$first->update(['alt' => 'Pierwsze', 'caption' => 'Podpis']);
$content->attachAsset($gallery, $first, 0, $actor);
$content->attachAsset($gallery, $second, 1, $actor);
$content->save($intro, 'Nasze wartości', 0, $actor);

[$rowA] = $content->addRow($repeater, 0, $actor);
[$rowB] = $content->addRow($repeater, 1, $actor);
$content->save($itemTitle, 'Pierwsza wartość', 0, $actor, $rowA);
$content->attachAsset($itemPhoto, $assets->store($upload(160), $itemPhoto, $actor), 0, $actor, $rowA);
[$nestedRow] = $content->addRow($nested, 0, $actor, $rowA);
$content->save($point, 'Punkt w wierszu', 0, $actor, $nestedRow);

WpMapping::query()->create([
	'project_id' => $project->id,
	'mappable_type' => 'field',
	'mappable_id' => $unmapped->id,
	'settings' => ['acf_type' => 'gallery'],
]);

$access = app(ClientAccessService::class)->generate($project, $admin);

unlink($temp);

$path = '/tmp/fmi-stage11-ids.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'token' => $access->token,
	'project_uuid' => $project->uuid,
	'project_b_uuid' => Project::query()->findOrFail((int) $manifest['projects']['b'])->uuid,
	'pages' => ['child' => $child->id, 'retired' => $retired->id],
	'fields' => [
		'title' => $title->id, 'lead' => $lead->id, 'button' => $button->id, 'photo' => $photo->id,
		'empty' => $empty->id, 'item_title' => $itemTitle->id, 'unmapped' => $unmapped->id, 'old' => $old->id,
		'hidden' => $hidden->id, 'group' => $group->id, 'repeater' => $repeater->id, 'nested' => $nested->id,
		'point' => $point->id, 'gallery' => $gallery->id,
	],
	'sections' => ['hero' => $hero->id, 'values' => $values->id],
	'rows' => ['a' => $rowA->uuid, 'b' => $rowB->uuid],
]));

fwrite(STDOUT, "Dane etapu 11 zasiane.\n");
