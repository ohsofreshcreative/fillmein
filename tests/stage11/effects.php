<?php

/**
 * Etap 11 — skutki eksportu z testów HTTP: historia, plik na prywatnym dysku, dziennik, ścieżka błędu.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ExportStatus;
use App\Models\Activity;
use App\Models\Export;
use App\Models\Project;
use App\Services\ExportService;
use App\Services\ExportValidator;
use Illuminate\Support\Facades\Storage;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) file_get_contents('/tmp/fmi-stage11-ids.json'), true);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$admin = get_userdata((int) $manifest['users']['admin']);
$disk = Storage::disk('fmi');
$export = Export::query()->where('project_id', $project->id)->latest('id')->firstOrFail();

Harness::test('Historia: status, autor, rozmiar, wersja, suma', function () use ($export, $disk, $admin) {
	Harness::same(ExportStatus::Completed, $export->status, 'status');
	Harness::same((int) $admin->ID, $export->user_id, 'autor');
	Harness::same('1.2', $export->summary['schema_version'], 'wersja');

	$json = $disk->get($export->file_path);

	Harness::same(strlen($json), $export->summary['size'], 'rozmiar');
	Harness::same(hash('sha256', $json), $export->checksum, 'suma');
	Harness::same(1, $export->summary['unmapped'], 'liczba niezmapowanych');
	Harness::assert($export->summary['required_empty'] >= 4, 'liczba pustych');
});

Harness::test('Zapisany plik przechodzi walidację i nie ma danych wrażliwych', function () use ($export, $disk, $ids) {
	$json = $disk->get($export->file_path);

	Harness::same([], app(ExportValidator::class)->validate(json_decode($json, true)), 'walidacja');
	Harness::assert(! str_contains($json, $ids['token']), 'token');
	Harness::assert(! str_contains($json, '@'), 'e-mail');
});

Harness::test('Plik jest poza WordPressem, 0600, ze ścieżką względną', function () use ($export, $disk) {
	$absolute = $disk->path($export->file_path);

	Harness::assert(! str_starts_with($export->file_path, '/'), 'ścieżka względna w bazie');
	Harness::assert(! str_contains($export->file_path, '..'), 'bez wyjścia z dysku');
	Harness::assert(! str_starts_with(realpath($absolute), realpath(ABSPATH)), 'poza WordPressem');
	Harness::same('600', substr(sprintf('%o', fileperms($absolute)), -3), 'uprawnienia JSON');
	Harness::same('600', substr(sprintf('%o', fileperms(preg_replace('/json$/', 'zip', $absolute))), -3), 'uprawnienia ZIP');
	Harness::assert($disk->exists('.htaccess'), 'blokada katalogu');
});

Harness::test('Dziennik: eksport i mapowanie, bez tokenu', function () use ($project, $ids) {
	$events = Activity::query()->where('project_id', $project->id)->whereIn('event', ['export.completed', 'mapping.updated'])->get();

	Harness::same(1, $events->where('event', 'export.completed')->count(), 'eksport');
	Harness::same(1, $events->where('event', 'mapping.updated')->count(), 'mapowanie');
	Harness::assert(! str_contains($events->toJson(), $ids['token']), 'token');
});

Harness::test('Odrzucona walidacja: status failed, bez pliku, komunikat bez ścieżek', function () use ($project, $admin) {
	$broken = new class extends ExportValidator
	{
		public function validate(array $payload): array
		{
			return ['/: błąd testowy'];
		}
	};

	app()->instance(ExportValidator::class, $broken);

	$export = app(ExportService::class)->run($project, $admin);

	app()->forgetInstance(ExportValidator::class);

	Harness::same(ExportStatus::Failed, $export->status, 'status');
	Harness::same(null, $export->file_path, 'plik');
	Harness::same('Eksport nie przeszedł walidacji schematu.', $export->error_message, 'komunikat');
	Harness::same(1, Activity::query()->where('project_id', $project->id)->where('event', 'export.failed')->count(), 'dziennik');
});

Harness::test('Eksport projektu B nie zawiera niczego z projektu A', function () use ($manifest, $project) {
	$other = Project::query()->findOrFail((int) $manifest['projects']['b']);
	$json = ExportService::encode(app(ExportService::class)->build($other));

	Harness::assert(! str_contains($json, $project->uuid), 'projekt');
	Harness::assert(! str_contains($json, 'Tytuł strony'), 'treść');
});

exit(Harness::summary('ETAP 11 — skutki'));
