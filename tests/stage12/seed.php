<?php

/**
 * Etap 12 — dane na bazie etapu 11 i eksport do chronionego katalogu. Dodatkowo: pole o tej samej nazwie w dwóch
 * sekcjach jednej strony i znak `&` w tytule. Tworzy też uszkodzone warianty eksportu do testów odmowy.
 */

use App\Enums\ExportStatus;
use App\Models\Page;
use App\Models\Project;
use App\Models\Section;
use App\Services\ContentService;
use App\Services\ExportService;
use App\Services\Import\WpImporter;
use App\Services\SectionService;
use App\Support\Actor;
use Illuminate\Support\Facades\Storage;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) file_get_contents('/tmp/fmi-stage11-ids.json'), true);
$admin = get_userdata((int) $manifest['users']['admin']);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);

Page::query()->whereKey($ids['pages']['child'])->update(['name' => 'O nas & zespół']);

$twin = app(SectionService::class)->createField(Section::query()->findOrFail($ids['sections']['values']), [
	'type' => 'text',
	'label' => 'Nagłówek',
], $admin);

app(ContentService::class)->save($twin, 'Nagłówek drugiej sekcji', 0, Actor::user($admin));

$export = app(ExportService::class)->run($project, $admin);

if ($export->status !== ExportStatus::Completed) {
	fwrite(STDERR, "BLOCKED: eksport nie powiódł się.\n");
	exit(2);
}

$disk = Storage::disk('fmi');
$json = $disk->path($export->file_path);
$zip = preg_replace('/json$/', 'zip', $json);
$document = json_decode((string) file_get_contents($json), true);

$broken = $document;
unset($broken['pages'][0]['mapping']);
file_put_contents('/tmp/fmi-stage12-broken.json', json_encode($broken));

$version = $document;
$version['schema_version'] = '2.0';
file_put_contents('/tmp/fmi-stage12-version.json', json_encode($version));

copy($zip, '/tmp/fmi-stage12-tampered.zip');
$archive = new ZipArchive;
$archive->open('/tmp/fmi-stage12-tampered.zip');
$archive->addFromString($document['assets'][0]['path'], 'podmieniona zawartość');
$archive->close();

file_put_contents('/tmp/fmi-stage12-state.json', json_encode([
	'zip' => $zip,
	'json' => $json,
	'source' => WpImporter::source($document),
	'twin' => $twin->id,
]));

fwrite(STDOUT, "Eksport testowy gotowy.\n");
