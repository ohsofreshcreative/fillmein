<?php

/**
 * Krok 0 — token klienta nie może trafić jawnym tekstem do pliku sesji ani do odpowiedzi.
 *
 * Tworzy tymczasowy projekt i link klienta, wykonuje prawdziwe żądanie HTTP do /p/{token}
 * i sprawdza pliki sesji zmienione w trakcie testu. Token NIGDY nie jest wypisywany.
 * Sprząta: plik sesji z tego żądania, projekt (kaskadowo dostęp) i wpisy dziennika tego przebiegu.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Activity;
use App\Services\ClientAccessService;
use App\Services\ProjectService;

$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;

if (! $admin) {
	fwrite(STDERR, "BLOCKED: brak użytkownika administratora do wykonania testów.\n");
	exit(2);
}

wp_set_current_user($admin->ID);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$sessionDir = (string) config('session.files');
$watermark = (int) Activity::query()->max('id');
$startedAt = time() - 1;
$project = app(ProjectService::class)->create(['name' => 'TEST krok00 sesja klienta'], $admin);
$issued = app(ClientAccessService::class)->generate($project, $admin);
$token = $issued->token;
$sessionFile = null;

try {
	$response = wp_remote_get(home_url('/p/' . $token), ['timeout' => 25, 'redirection' => 0]);

	$t('Ważny token → 200, bez tokenu w treści odpowiedzi i nagłówkach', function () use ($response, $token, $same, $ok) {
		$ok(! is_wp_error($response), 'Żądanie HTTP wykonane');
		$same(200, (int) wp_remote_retrieve_response_code($response), 'Kod odpowiedzi');
		$ok(! str_contains((string) wp_remote_retrieve_body($response), $token), 'Token nie występuje w HTML');
		$ok(! str_contains(json_encode(wp_remote_retrieve_headers($response)->getAll()), $token), 'Token nie występuje w nagłówkach');
		$same('no-referrer', wp_remote_retrieve_header($response, 'referrer-policy'), 'Referrer-Policy');
	});

	$cookieName = (string) config('session.cookie');

	foreach (wp_remote_retrieve_cookies($response) as $cookie) {
		if ($cookie->name === $cookieName && preg_match('/^[A-Za-z0-9]{40}$/', $cookie->value)) {
			$sessionFile = $sessionDir . '/' . $cookie->value;
		}
	}

	$t('Plik sesji tego żądania nie zawiera tokenu klienta (`_previous.url`)', function () use ($sessionFile, $token, $ok) {
		$ok($sessionFile !== null && is_file($sessionFile), 'Sesja zapisana w pliku (cookie sesji obecne)');
		$ok(! str_contains((string) file_get_contents($sessionFile), $token), 'Token w pliku sesji');
	});

	$t('Żaden plik sesji zmieniony w trakcie testu nie zawiera tokenu', function () use ($sessionDir, $startedAt, $token, $same) {
		$leaks = 0;

		foreach (glob($sessionDir . '/*') ?: [] as $file) {
			if (is_file($file) && filemtime($file) >= $startedAt && str_contains((string) file_get_contents($file), $token)) {
				$leaks++;
			}
		}

		$same(0, $leaks, 'Liczba plików sesji z tokenem');
	});
} finally {
	if ($sessionFile && is_file($sessionFile)) {
		unlink($sessionFile);
	}

	Activity::query()->where('id', '>', $watermark)->where('project_id', $project->id)->delete();
	$project->forceDelete();
	unset($token, $issued);
}

exit(Harness::summary('Krok 00 — sesja klienta'));
