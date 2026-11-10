<?php

/**
 * Etap 09 — trwałe dane testów HTTP. Tokeny trafiają wyłącznie do /tmp/fmi-stage09-ids.json (0600).
 */

use App\Models\ClientAccess;
use App\Models\Page;
use App\Models\Project;
use App\Services\AssetService;
use App\Services\ClientAccessService;
use App\Services\ContentService;
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
$accesses = app(ClientAccessService::class);
$assets = app(AssetService::class);
$actor = Actor::user($admin);
$fixture = fn (string $name) => new UploadedFile(__DIR__ . '/fixtures/' . $name, $name, null, null, true);

$projectA = Project::query()->findOrFail((int) $manifest['projects']['a']);
$projectB = Project::query()->findOrFail((int) $manifest['projects']['b']);

$section = $service->create(Page::query()->findOrFail((int) $manifest['pages']['a']), ['name' => 'Zdjęcia'], $admin);
$make = fn ($in, array $data) => $service->createField($in, $data + ['label' => 'Pole'], $admin);

$image = $make($section, ['type' => 'image', 'label' => 'Zdjęcie główne', 'is_required' => true, 'settings' => ['require_alt' => 1]]);
$gallery = $make($section, ['type' => 'gallery', 'label' => 'Galeria', 'is_required' => true, 'settings' => ['min_items' => 2, 'max_items' => 3]]);
$sized = $make($section, ['type' => 'image', 'label' => 'Zdjęcie o minimalnym rozmiarze', 'settings' => ['min_width' => 100, 'min_height' => 100]]);
$text = $make($section, ['type' => 'text', 'label' => 'Tekst']);
$archived = $make($section, ['type' => 'image', 'label' => 'Zdjęcie archiwalne']);
$service->archiveField($archived, $admin);
$hidden = $make($section, ['type' => 'image', 'label' => 'Zdjęcie ukryte', 'is_hidden' => true]);

$sectionB = $service->create(Page::query()->findOrFail((int) $manifest['pages']['b']), ['name' => 'Zdjęcia B'], $admin);
$imageB = $make($sectionB, ['type' => 'image', 'label' => 'Zdjęcie B']);
$assetB = $assets->store($fixture('ok.jpg'), $imageB, $actor);
$content->attachAsset($imageB, $assetB, 0, $actor);

$active = $accesses->generate($projectA, $admin);
$activeB = $accesses->generate($projectB, $admin);
$revoked = $accesses->generate($projectA, $admin);
$accesses->revoke($revoked->access, $admin);
$expired = $accesses->generate($projectA, $admin);
ClientAccess::query()->whereKey($expired->access->id)->update(['expires_at' => now()->subHour()]);

$path = '/tmp/fmi-stage09-ids.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'tokens' => ['a' => $active->token, 'b' => $activeB->token, 'revoked' => $revoked->token, 'expired' => $expired->token],
	'project_uuid' => $projectA->uuid,
	'fields' => ['image' => $image->id, 'gallery' => $gallery->id, 'sized' => $sized->id, 'text' => $text->id, 'archived' => $archived->id, 'hidden' => $hidden->id, 'foreign' => $imageB->id],
	'assets' => ['foreign' => $assetB->uuid],
	'fixtures' => __DIR__ . '/fixtures',
]));

fwrite(STDOUT, "Dane etapu 09 zasiane.\n");
