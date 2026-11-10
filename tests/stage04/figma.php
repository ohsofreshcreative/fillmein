<?php

/**
 * Etap 04 — testy parsera linków, klienta Figma API i importu frame'u na fixture (bez sieci).
 *
 * Zapisy w bazie działają w transakcji wycofywanej na końcu. Pliki podglądów trafiają na prywatny
 * dysk `fmi` pod katalog projektu testowego i są usuwane w `finally`.
 */

require __DIR__ . '/../stage03/_harness.php';
require __DIR__ . '/_fake.php';

use App\Integrations\Figma\FigmaClient;
use App\Integrations\Figma\FigmaException;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Activity;
use App\Models\FigmaFrame;
use App\Models\FigmaPreview;
use App\Services\ActivityLogger;
use App\Services\FigmaFrameService;
use App\Services\PageService;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

/** Oczekiwany FigmaException danego rodzaju; zwraca wyjątek do dalszych asercji. */
$figmaError = function (string $kind, callable $fn, string $message): FigmaException {
	try {
		$fn();
	} catch (FigmaException $e) {
		if ($e->kind !== $kind) {
			throw new RuntimeException("{$message} — rodzaj {$e->kind}, oczekiwano {$kind} ({$e->getMessage()})");
		}

		if (str_contains($e->getMessage(), FakeFigma::TOKEN)) {
			throw new RuntimeException("{$message} — komunikat zawiera token");
		}

		return $e;
	}

	throw new RuntimeException("{$message} — nie zgłoszono FigmaException");
};

$nodesUrl = '~^https://api\.figma\.com/v1/files/' . FakeFigma::FILE_KEY . '/nodes\?~';
$imagesUrl = '~^https://api\.figma\.com/v1/images/' . FakeFigma::FILE_KEY . '\?~';
$cdnUrl = '~^https://figma-alpha-api\.s3\.us-west-2\.amazonaws\.com/images/~';
$frameUrl = 'https://www.figma.com/design/' . FakeFigma::FILE_KEY . '/Strona?node-id=1-2&t=abc';

// ===================================================================== C1: parser linków i ID
echo "== C1: parser linków i identyfikatorów ==\n";

$t('C1: link design z node-id → klucz pliku i frame', function () use ($same) {
	$same(['file_key' => 'abcDEF123456', 'node_id' => '12:345'], FigmaUrl::frame('https://www.figma.com/design/abcDEF123456/Nazwa-pliku?node-id=12-345&t=xyz-0'), 'frame()');
});

$t('C1: node-id zakodowany (%3A) i z dwukropkiem', function () use ($same) {
	$same('12:345', FigmaUrl::nodeId('https://www.figma.com/design/abcDEF123456/P?node-id=12%3A345'), '%3A');
	$same('12:345', FigmaUrl::nodeId('https://www.figma.com/design/abcDEF123456/P?node-id=12:345'), 'dwukropek');
	$same('7:8', FigmaUrl::nodeId('https://figma.com/file/abcDEF123456/P?type=design&node-id=7-8&mode=dev'), 'stary format /file/ bez www');
});

$t('C1: link do gałęzi → klucz gałęzi', function () use ($same) {
	$same(['file_key' => 'BRANCHkey9876', 'node_id' => '3:4'], FigmaUrl::frame('https://www.figma.com/design/abcDEF123456/branch/BRANCHkey9876/P?node-id=3-4'), 'gałąź');
});

$t('C1: odrzuca nieprawidłowe linki', function () use ($same) {
	$invalid = [
		'bez node-id' => 'https://www.figma.com/design/abcDEF123456/P',
		'http' => 'http://www.figma.com/design/abcDEF123456/P?node-id=1-2',
		'obca domena' => 'https://example.com/design/abcDEF123456/P?node-id=1-2',
		'subdomena-pułapka' => 'https://www.figma.com.evil.test/design/abcDEF123456/P?node-id=1-2',
		'userinfo' => 'https://figma.com@evil.test/design/abcDEF123456/P?node-id=1-2',
		'port' => 'https://www.figma.com:8443/design/abcDEF123456/P?node-id=1-2',
		'FigJam' => 'https://www.figma.com/board/abcDEF123456/P?node-id=1-2',
		'za krótki klucz' => 'https://www.figma.com/design/abc/P?node-id=1-2',
		'klucz z ../' => 'https://www.figma.com/design/../../v1/me?node-id=1-2',
		'node-id tekstowy' => 'https://www.figma.com/design/abcDEF123456/P?node-id=abc',
		'node-id potrójny' => 'https://www.figma.com/design/abcDEF123456/P?node-id=1-2-3',
		'warstwa instancji' => 'https://www.figma.com/design/abcDEF123456/P?node-id=I1%3A2%3B3%3A4',
		'node-id pusty' => 'https://www.figma.com/design/abcDEF123456/P?node-id=',
		'javascript' => 'javascript:alert(1)//https://www.figma.com/design/abcDEF123456/P?node-id=1-2',
		'pusty' => '',
	];

	foreach ($invalid as $label => $url) {
		$same(null, FigmaUrl::frame($url), "frame() dla: {$label}");
		$same(false, FigmaUrl::isFrame($url), "isFrame() dla: {$label}");
	}

	$same(null, FigmaUrl::frame(null), 'null');
});

$t('C1: kodowanie i dekodowanie ID warstwy', function () use ($same, $throws) {
	$same('12-345', FigmaUrl::encodeNodeId('12:345'), 'encode');
	$same('12:345', FigmaUrl::decodeNodeId('12-345'), 'decode z myślnikiem');
	$same('12:345', FigmaUrl::decodeNodeId('12%3A345'), 'decode zakodowany');
	$same('12:345', FigmaUrl::decodeNodeId(FigmaUrl::encodeNodeId('12:345')), 'round-trip');
	$same(null, FigmaUrl::decodeNodeId('12-345/../x'), 'decode odrzuca ścieżkę');
	$same(null, FigmaUrl::decodeNodeId('0'), 'decode odrzuca pojedynczą liczbę');
	$throws(InvalidArgumentException::class, fn () => FigmaUrl::encodeNodeId('../1:2'), 'encode odrzuca niepoprawne ID');
});

$t('C1/SSRF: klient odrzuca niepoprawny klucz i ID zanim wyśle żądanie', function () use ($figmaError, $same) {
	$fake = new FakeFigma();
	$client = $fake->client();

	$figmaError(FigmaException::INVALID_LINK, fn () => $client->fileNodes('../../me', ['1:2']), 'klucz z ../');
	$figmaError(FigmaException::INVALID_LINK, fn () => $client->fileNodes('abc?x=1&y=2', ['1:2']), 'klucz z zapytaniem');
	$figmaError(FigmaException::INVALID_NODE, fn () => $client->fileNodes(FakeFigma::FILE_KEY, ['1:2&depth=99']), 'ID z doklejonym parametrem');
	$figmaError(FigmaException::INVALID_NODE, fn () => $client->fileNodes(FakeFigma::FILE_KEY, []), 'brak ID');
	$same(0, count($fake->requests()), 'Żadne żądanie nie wyszło');
});

// ===================================================================== C2: błędy i limity
echo "\n== C2: obsługa błędów, ponowienia i limity ==\n";

$t('C2: brak tokenu → NOT_CONFIGURED bez żądania', function () use ($figmaError, $same) {
	if (defined(FigmaClient::TOKEN_NAME)) {
		throw new RuntimeException('Stała FMI_FIGMA_TOKEN w wp-config.php — nie da się zasymulować braku tokenu');
	}

	// Token ze zmiennej środowiskowej (np. dla testu LIVE) wyłączamy tylko na czas tego testu.
	$envToken = getenv(FigmaClient::TOKEN_NAME);
	putenv(FigmaClient::TOKEN_NAME);

	try {
		$fake = new FakeFigma();
		$client = $fake->client(null);

		$same(null, FigmaClient::configuredToken(), 'configuredToken');
		$same(false, $client->isConfigured(), 'isConfigured');
		$figmaError(FigmaException::NOT_CONFIGURED, fn () => $client->fileNodes(FakeFigma::FILE_KEY, ['1:2']), 'brak tokenu');
		$same(0, count($fake->requests()), 'Żadne żądanie nie wyszło');
	} finally {
		if ($envToken !== false) {
			putenv(FigmaClient::TOKEN_NAME . '=' . $envToken);
		}
	}
});

$t('C2: token tylko w nagłówku X-Figma-Token, stały host API, zapytanie o jeden węzeł', function () use ($nodesUrl, $same, $ok) {
	$fake = (new FakeFigma())->on($nodesUrl, [FakeFigma::fixture('nodes-frame.json'), 200]);
	$fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2'], 8);
	$requests = $fake->requests();

	$same(1, count($requests), 'Jedno żądanie');
	$same('https://api.figma.com/v1/files/' . FakeFigma::FILE_KEY . '/nodes?ids=1%3A2&depth=8', $requests[0]->url(), 'Dokładny adres');
	$same([FakeFigma::TOKEN], $requests[0]->header('X-Figma-Token'), 'Nagłówek tokenu');
	$ok(! str_contains($requests[0]->url(), FakeFigma::TOKEN), 'Token nie trafia do adresu');
});

foreach ([400 => FigmaException::BAD_REQUEST, 401 => FigmaException::UNAUTHORIZED, 403 => FigmaException::FORBIDDEN, 404 => FigmaException::NOT_FOUND] as $status => $kind) {
	$t("C2: {$status} → {$kind} bez ponowień", function () use ($status, $kind, $nodesUrl, $figmaError, $same) {
		$fake = (new FakeFigma())->on($nodesUrl, ['{"status":' . $status . ',"err":"x"}', $status]);
		$e = $figmaError($kind, fn () => $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']), (string) $status);

		$same(1, count($fake->requests()), 'Dokładnie jedno żądanie');
		$same($status, $e->status, 'Status w wyjątku');
		$same([], $fake->sleeps, 'Bez oczekiwania');
	});
}

$t('C2: 403 z powodu zakresu tokenu → osobny komunikat o file_content:read', function () use ($nodesUrl, $figmaError, $same, $ok) {
	$fake = (new FakeFigma())->on($nodesUrl, ['{"status":403,"err":"Invalid scope(s): file_metadata:read. This endpoint requires the file_content:read scope"}', 403]);
	$e = $figmaError(FigmaException::FORBIDDEN, fn () => $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']), '403 scope');

	$same(1, count($fake->requests()), 'Dokładnie jedno żądanie');
	$ok(str_contains($e->getMessage(), 'file_content:read'), 'Komunikat wskazuje brakujący zakres');
	$ok(! str_contains($e->getMessage(), FakeFigma::TOKEN), 'Komunikat bez tokenu');
});

$t('C2: 429 z Retry-After → odczekanie i sukces w 2. próbie', function () use ($nodesUrl, $same) {
	$fake = (new FakeFigma())->on($nodesUrl, ['{"status":429}', 429, ['Retry-After' => '2']], [FakeFigma::fixture('nodes-frame.json'), 200]);
	$data = $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']);

	$same('1111111111', $data['version'], 'Dane z drugiej próby');
	$same(2, count($fake->requests()), 'Dwa żądania');
	$same([2.0], $fake->sleeps, 'Odczekano Retry-After');
});

$t('C2: stałe 429 → RATE_LIMITED po max_attempts (bez nieskończonych ponowień)', function () use ($nodesUrl, $figmaError, $same) {
	$fake = (new FakeFigma())->on($nodesUrl, ['{"status":429}', 429, ['Retry-After' => '1']]);
	$e = $figmaError(FigmaException::RATE_LIMITED, fn () => $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']), '429');

	$same((int) config('fillmein.figma.max_attempts'), count($fake->requests()), 'Liczba prób = max_attempts');
	$same(3, $e->attempts, 'Licznik prób w wyjątku');
	$same([1.0, 1.0], $fake->sleeps, 'Oczekiwanie tylko między próbami');
});

$t('C2: 429 z długim Retry-After → błąd od razu, bez czekania', function () use ($nodesUrl, $figmaError, $same) {
	$fake = (new FakeFigma())->on($nodesUrl, ['{"status":429}', 429, ['Retry-After' => '3600']]);
	$figmaError(FigmaException::RATE_LIMITED, fn () => $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']), '429 długie');

	$same(1, count($fake->requests()), 'Jedno żądanie');
	$same([], $fake->sleeps, 'Bez czekania godzinę');
});

$t('C2: 500, 502 → sukces w 3. próbie z rosnącym odstępem', function () use ($nodesUrl, $same) {
	$fake = (new FakeFigma())->on($nodesUrl, ['err', 500], ['err', 502], [FakeFigma::fixture('nodes-frame.json'), 200]);
	$fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']);

	$same(3, count($fake->requests()), 'Trzy żądania');
	$same([0.5, 1.0], $fake->sleeps, 'Backoff wykładniczy');
});

$t('C2: stałe 503 → SERVER po max_attempts', function () use ($nodesUrl, $figmaError, $same) {
	$fake = (new FakeFigma())->on($nodesUrl, ['err', 503]);
	$figmaError(FigmaException::SERVER, fn () => $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']), '503');
	$same(3, count($fake->requests()), 'Trzy żądania');
});

$t('C2: błąd połączenia / timeout → TIMEOUT po max_attempts', function () use ($nodesUrl, $figmaError, $same) {
	$fake = (new FakeFigma())->failConnection($nodesUrl);
	$figmaError(FigmaException::TIMEOUT, fn () => $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']), 'połączenie');
	$same(3, count($fake->sleeps) + 1, 'Trzy próby (dwa odstępy)');
});

$t('C2: max_attempts=1 wyłącza ponowienia', function () use ($nodesUrl, $figmaError, $same) {
	$previous = config('fillmein.figma.max_attempts');
	config()->set('fillmein.figma.max_attempts', 1);

	try {
		$fake = (new FakeFigma())->on($nodesUrl, ['err', 500]);
		$figmaError(FigmaException::SERVER, fn () => $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']), '500');
		$same(1, count($fake->requests()), 'Jedno żądanie');
	} finally {
		config()->set('fillmein.figma.max_attempts', $previous);
	}
});

$t('C2: nieprawidłowy JSON i zbyt duża odpowiedź → INVALID_RESPONSE', function () use ($nodesUrl, $figmaError, $same) {
	$fake = (new FakeFigma())->on($nodesUrl, ['<html>nie JSON</html>', 200]);
	$figmaError(FigmaException::INVALID_RESPONSE, fn () => $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']), 'nie JSON');

	$previous = config('fillmein.figma.max_response_kb');
	config()->set('fillmein.figma.max_response_kb', 1);

	try {
		$fake = (new FakeFigma())->on($nodesUrl, [json_encode(['pad' => str_repeat('x', 4096)]), 200]);
		$figmaError(FigmaException::INVALID_RESPONSE, fn () => $fake->client()->fileNodes(FakeFigma::FILE_KEY, ['1:2']), 'za duża');
		$same(1, count($fake->requests()), 'Bez ponowień');
	} finally {
		config()->set('fillmein.figma.max_response_kb', $previous);
	}
});

$t('C2/SSRF: obrazy tylko z dozwolonych hostów HTTPS, bez tokenu', function () use ($cdnUrl, $figmaError, $same) {
	$fake = (new FakeFigma())->on($cdnUrl, [FakeFigma::png(), 200]);
	$client = $fake->client();

	foreach ([
		'http://figma-alpha-api.s3.us-west-2.amazonaws.com/images/x',
		'https://evil.test/images/x',
		'https://169.254.169.254/latest/meta-data/',
		'https://127.0.0.1/x',
		'https://figma-alpha-api.s3.us-west-2.amazonaws.com:8443/images/x',
		'https://user:pass@figma-alpha-api.s3.us-west-2.amazonaws.com/images/x',
		'https://figma-alpha-api.s3.us-west-2.amazonaws.com.evil.test/images/x',
		'file:///etc/passwd',
	] as $url) {
		$figmaError(FigmaException::DISALLOWED_URL, fn () => $client->download($url), $url);
	}

	$same(0, count($fake->requests()), 'Żadne niedozwolone żądanie nie wyszło');

	$client->download('https://figma-alpha-api.s3.us-west-2.amazonaws.com/images/ok');
	$requests = $fake->requests();
	$same(1, count($requests), 'Dozwolony host pobrany');
	$same([], $requests[0]->header('X-Figma-Token'), 'Token nie jest wysyłany do hosta obrazów');
});

$t('C2: /v1/images z err → INVALID_RESPONSE', function () use ($imagesUrl, $figmaError) {
	$fake = (new FakeFigma())->on($imagesUrl, ['{"err":"Render timeout","images":{}}', 200]);
	$figmaError(FigmaException::INVALID_RESPONSE, fn () => $fake->client()->images(FakeFigma::FILE_KEY, ['1:2']), 'err');
});

// ===================================================================== C3: import frame'u z fixture
echo "\n== C3: import ograniczony do wskazanego frame'u (fixture) ==\n";

$cleanupDirs = [];

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST etap04 projekt'], $admin);
	$other = app(ProjectService::class)->create(['name' => 'TEST etap04 projekt obcy'], $admin);
	$cleanupDirs[] = 'figma/' . $project->uuid;
	$cleanupDirs[] = 'figma/' . $other->uuid;

	$pages = app(PageService::class);
	$page = $pages->create($project, ['name' => 'Strona główna', 'figma_frame_url' => $frameUrl], $admin);
	$otherPage = $pages->create($other, ['name' => 'Obca', 'figma_frame_url' => $frameUrl], $admin);
	$noFrame = $pages->create($project, ['name' => 'Bez frame\'u'], $admin);

	$standardFake = function () use ($nodesUrl, $imagesUrl, $cdnUrl): FakeFigma {
		return (new FakeFigma())
			->on($nodesUrl, [FakeFigma::fixture('nodes-frame.json'), 200])
			->on($imagesUrl, [FakeFigma::fixture('images.json'), 200])
			->on($cdnUrl, [FakeFigma::png(16, 12), 200]);
	};

	$service = fn (FakeFigma $fake) => new FigmaFrameService($fake->client(), app(ActivityLogger::class));

	$fake = $standardFake();
	$result = $service($fake)->fetch($page, $admin);
	$frame = $result['frame'];

	$t('C3: jedno zapytanie o węzły — wyłącznie frame podstrony, nigdy cały plik', function () use ($fake, $same, $ok) {
		$nodeRequests = $fake->requests('~/v1/files/~');
		$same(1, count($nodeRequests), 'Jedno żądanie /files');
		$ok(str_contains($nodeRequests[0]->url(), '/nodes?ids=1%3A2&depth='), 'Endpoint /nodes z ids=1:2');
		$same(0, count($fake->requests('~/v1/files/' . FakeFigma::FILE_KEY . '(\?|$)~')), 'Brak GET /v1/files/:key (cały plik)');
		$same(0, count($fake->requests('~418~')), 'Brak nieoczekiwanych żądań');
	});

	$t('C3: cache metadanych z wersją, datą zmiany i czasem pobrania', function () use ($frame, $page, $same, $ok) {
		$same($page->project_id, $frame->project_id, 'Projekt');
		$same(FakeFigma::FILE_KEY, $frame->file_key, 'Klucz pliku');
		$same('1:2', $frame->node_id, 'Węzeł');
		$same('Strona główna — desktop', $frame->node_name, 'Nazwa');
		$same('FRAME', $frame->node_type, 'Typ');
		$same('1111111111', $frame->file_version, 'Wersja pliku');
		$same('2026-10-20 10:15:30', $frame->file_last_modified?->utc()->format('Y-m-d H:i:s'), 'lastModified');
		$same([1440, 3200], [$frame->width, $frame->height], 'Wymiary');
		$ok($frame->fetched_at !== null && $frame->fetched_at->diffInSeconds(now(), true) < 120, 'fetched_at ustawione');
		$same(1, FigmaFrame::query()->where('project_id', $page->project_id)->count(), 'Jeden wpis cache w projekcie');
	});

	$t('C3: zapisane jest tylko poddrzewo frame\'u, przycięte do potrzebnych pól', function () use ($frame, $same, $ok) {
		$json = json_encode($frame->document);
		$ok(! str_contains($json, '9:9') && ! str_contains($json, 'spoza żądania'), 'Węzeł spoza żądania pominięty');
		$ok(! str_contains($json, '"fills"') && ! str_contains($json, '"effects"') && ! str_contains($json, '"style"'), 'Bez stylów i wypełnień');
		$ok(! str_contains($json, 'thumbnails'), 'Bez adresów miniatur');
		$same(['1:10', '1:20', '1:30', '1:40'], array_column($frame->document['children'], 'id'), 'Dzieci frame\'u w kolejności');
		$same('Świeże strony dla Twojej firmy', $frame->document['children'][1]['children'][0]['characters'], 'Tekst warstwy TEXT');
		$same("Akapit pierwszy.\nAkapit drugi.", $frame->document['children'][1]['children'][1]['children'][0]['characters'], 'Głęboki tekst');
		$same(false, $frame->document['children'][3]['visible'], 'Ukryta warstwa oznaczona');
		$same(9, $frame->nodes_count, 'Liczba warstw (bez węzła spoza żądania)');
	});

	$t('C3: nazwa warstwy zapisana na podstronie', function () use ($page, $same) {
		$same('Strona główna — desktop', $page->fresh()->figma_node_name, 'figma_node_name');
	});

	$t('C3: podglądy frame\'u i widocznych dzieci w prywatnym cache', function () use ($fake, $frame, $project, $result, $same, $ok) {
		$images = $fake->requests('~/v1/images/~');
		$same(1, count($images), 'Jedno żądanie /images');
		parse_str((string) parse_url($images[0]->url(), PHP_URL_QUERY), $query);
		$same('1:2,1:10,1:20,1:30', $query['ids'] ?? null, 'ids bez ukrytej warstwy');
		$same('png', $query['format'] ?? null, 'Format');
		$same('true', $query['use_absolute_bounds'] ?? null, 'Render przycięty do granic frame\'u (use_absolute_bounds)');

		$previews = $frame->previews()->get();
		$same(['1:2', '1:10', '1:20', '1:30'], $previews->pluck('node_id')->all(), 'Podglądy: frame + widoczne dzieci');
		$same(4, $result['previews'], 'Pobrane podglądy');
		$same(0, $result['previews_failed'], 'Bez błędów');

		foreach ($previews as $preview) {
			$ok(str_starts_with($preview->path, 'figma/' . $project->uuid . '/' . FakeFigma::FILE_KEY . '/'), 'Ścieżka w katalogu projektu: ' . $preview->path);
			$ok(Storage::disk('fmi')->exists($preview->path), 'Plik istnieje: ' . $preview->path);
			$same('image/png', $preview->mime_type, 'MIME');
			$same([16, 12], [$preview->width, $preview->height], 'Wymiary z pliku');
			$same('1111111111~abs', $preview->file_version, 'Wersja podglądu (wersja pliku + sposób renderowania)');
			$ok($preview->remoteUrlValid(), 'Referencja zdalna ważna');
			$ok(! array_key_exists('remote_url', $preview->toArray()), 'remote_url ukryte w serializacji');
		}

		$root = rtrim(str_replace('\\', '/', ABSPATH), '/');
		$ok(! str_starts_with(str_replace('\\', '/', Storage::disk('fmi')->path('x')), $root . '/'), 'Dysk fmi poza katalogiem publicznym');
	});

	$t('C3: ponowne pobranie w TTL → cache, zero żądań', function () use ($standardFake, $service, $page, $admin, $same) {
		$fake = $standardFake();
		$result = $service($fake)->fetch($page, $admin);

		$same(true, $result['from_cache'], 'from_cache');
		$same(0, count($fake->requests()), 'Bez żądań do API');
	});

	$t('C3: wymuszone odświeżenie tej samej wersji → tylko /nodes, bez ponownych renderów', function () use ($standardFake, $service, $page, $admin, $same) {
		$fake = $standardFake();
		$result = $service($fake)->fetch($page, $admin, true);

		$same(false, $result['from_cache'], 'from_cache');
		$same(1, count($fake->requests('~/v1/files/~')), '/nodes');
		$same(0, count($fake->requests('~/v1/images/~')), 'Bez /images');
		$same(0, count($fake->requests('~amazonaws~')), 'Bez pobierania plików');
	});

	$t('C3: nowa wersja pliku → nowe podglądy, stare pliki cache usunięte', function () use ($nodesUrl, $imagesUrl, $cdnUrl, $service, $page, $admin, $frame, $same, $ok) {
		$oldPaths = $frame->previews()->pluck('path')->all();
		$changed = str_replace('"1111111111"', '"2222222222"', FakeFigma::fixture('nodes-frame.json'));
		$fake = (new FakeFigma())
			->on($nodesUrl, [$changed, 200])
			->on($imagesUrl, [FakeFigma::fixture('images.json'), 200])
			->on($cdnUrl, [FakeFigma::png(20, 10), 200]);

		$result = $service($fake)->fetch($page, $admin, true);

		$same('2222222222', $result['frame']->file_version, 'Nowa wersja w cache');
		$same(1, count($fake->requests('~/v1/images/~')), 'Odświeżone adresy');
		$same(4, $result['previews'], 'Nowe pliki');

		foreach ($oldPaths as $path) {
			$ok(! Storage::disk('fmi')->exists($path), 'Stary plik usunięty: ' . $path);
		}

		foreach ($result['frame']->previews as $preview) {
			$same([20, 10], [$preview->width, $preview->height], 'Nowy podgląd');
		}
	});

	$t('C3: brak pliku + wygasły adres → odświeżenie adresu (/images) i pobranie', function () use ($standardFake, $service, $page, $same, $ok) {
		$fake = $standardFake();
		$svc = $service($fake);
		$preview = $svc->previewFor($page, '1:20');

		Storage::disk('fmi')->delete($preview->path);
		$preview->forceFill(['remote_expires_at' => now()->subDay()])->save();

		$ok($svc->ensureLocal($preview->fresh()), 'ensureLocal');
		$same(1, count($fake->requests('~/v1/images/~')), 'Adres odświeżony');
		$same(1, count($fake->requests('~amazonaws~')), 'Plik pobrany');
		$ok($preview->fresh()->remoteUrlValid(), 'Nowa data wygaśnięcia');
	});

	$t('C3: brak pliku + ważny adres → samo pobranie, bez /images', function () use ($standardFake, $service, $page, $same, $ok) {
		$fake = $standardFake();
		$svc = $service($fake);
		$preview = $svc->previewFor($page, '1:30');

		Storage::disk('fmi')->delete($preview->path);

		$ok($svc->ensureLocal($preview->fresh()), 'ensureLocal');
		$same(0, count($fake->requests('~/v1/images/~')), 'Bez /images');
		$same(1, count($fake->requests('~amazonaws~')), 'Plik pobrany');
	});

	$t('C3: adres z cache odrzucony (403) → jednorazowe odświeżenie i ponowienie', function () use ($nodesUrl, $imagesUrl, $cdnUrl, $service, $page, $same, $ok) {
		$fake = (new FakeFigma())
			->on($nodesUrl, [FakeFigma::fixture('nodes-frame.json'), 200])
			->on($imagesUrl, [FakeFigma::fixture('images.json'), 200])
			->on($cdnUrl, ['AccessDenied', 403], [FakeFigma::png(), 200]);
		$svc = $service($fake);
		$preview = $svc->previewFor($page, '1:10');

		Storage::disk('fmi')->delete($preview->path);

		$ok($svc->ensureLocal($preview->fresh()), 'ensureLocal');
		$same(1, count($fake->requests('~/v1/images/~')), 'Jedno odświeżenie');
		$same(2, count($fake->requests('~amazonaws~')), 'Dwie próby pobrania');
	});

	$t('C3: podgląd tylko z frame\'u tej podstrony i tego projektu', function () use ($standardFake, $service, $page, $otherPage, $noFrame, $same) {
		$svc = $service($standardFake());

		$same(null, $svc->previewFor($page, '9:9'), 'Węzeł spoza frame\'u');
		$same(null, $svc->previewFor($page, '1:40'), 'Ukryta warstwa bez podglądu');
		$same(null, $svc->frameFor($otherPage), 'Ten sam frame w innym projekcie nie widzi cache');
		$same(null, $svc->previewFor($otherPage, '1:2'), 'Podgląd innego projektu');
		$same(null, $svc->frameFor($noFrame), 'Podstrona bez linku');
	});

	$t('C3: odrzuca stronę pliku (CANVAS), brak węzła i podmienioną warstwę — bez zapisu', function () use ($nodesUrl, $service, $otherPage, $admin, $figmaError, $same) {
		$canvas = (new FakeFigma())->on($nodesUrl, [FakeFigma::fixture('nodes-canvas.json'), 200]);
		$figmaError(FigmaException::INVALID_NODE, fn () => $service($canvas)->fetch($otherPage, $admin), 'CANVAS');

		$missing = (new FakeFigma())->on($nodesUrl, [FakeFigma::fixture('nodes-missing.json'), 200]);
		$figmaError(FigmaException::NOT_FOUND, fn () => $service($missing)->fetch($otherPage, $admin), 'null');

		$swapped = (new FakeFigma())->on($nodesUrl, [str_replace('"id": "1:2",', '"id": "1:999",', FakeFigma::fixture('nodes-frame.json')), 200]);
		$figmaError(FigmaException::INVALID_RESPONSE, fn () => $service($swapped)->fetch($otherPage, $admin), 'inna warstwa');

		$same(0, FigmaFrame::query()->where('project_id', $otherPage->project_id)->count(), 'Nic nie zapisano');
	});

	$t('C3: podstrona bez linku do frame\'u → INVALID_LINK bez żądań', function () use ($standardFake, $service, $noFrame, $admin, $figmaError, $same) {
		$fake = $standardFake();
		$figmaError(FigmaException::INVALID_LINK, fn () => $service($fake)->fetch($noFrame, $admin), 'bez linku');
		$same(0, count($fake->requests()), 'Bez żądań');
	});

	$t('C3: przycinanie drzewa respektuje głębokość i limit warstw', function () use ($same) {
		$node = ['id' => '1:1', 'name' => 'A', 'type' => 'FRAME', 'children' => [
			['id' => '1:2', 'name' => 'B', 'type' => 'FRAME', 'children' => [
				['id' => '1:3', 'name' => 'C', 'type' => 'FRAME', 'children' => [['id' => '1:4', 'name' => 'D', 'type' => 'TEXT']]],
			]],
			['id' => '1:5', 'name' => 'E', 'type' => 'TEXT'],
		]];

		$count = 0;
		$tree = FigmaFrameService::prune($node, 1, $count, 100);
		$same(true, $tree['children'][0]['truncated'] ?? null, 'Głębokość 1: dzieci dzieci obcięte');
		$same(3, $count, 'Policzone 3 warstwy');

		$count = 0;
		$tree = FigmaFrameService::prune($node, 10, $count, 2);
		$same(2, $count, 'Limit warstw');
		$same(true, $tree['truncated'] ?? null, 'Oznaczenie obcięcia');
	});

	$t('Bezpieczeństwo: dziennik bez tokenu i adresów obrazów', function () use ($project, $same, $ok) {
		$entries = Activity::query()->where('project_id', $project->id)->where('event', 'figma.frame_fetched')->get();
		$ok($entries->count() >= 3, 'Zdarzenia figma.frame_fetched zapisane');

		$json = $entries->map(fn ($a) => json_encode($a->properties))->implode("\n");
		$ok(! str_contains($json, FakeFigma::TOKEN), 'Brak tokenu');
		$ok(! str_contains($json, 'amazonaws') && ! str_contains($json, 'http'), 'Brak adresów');
		$same('Pobrano frame z Figmy', \App\Support\ActivityPresenter::label($entries->first()), 'Etykieta w dzienniku');

		$cache = json_encode(FigmaFrame::query()->where('project_id', $project->id)->get()->toArray());
		$ok(! str_contains($cache, FakeFigma::TOKEN), 'Brak tokenu w cache frame\'u');
	});
} finally {
	DB::rollBack();

	foreach ($cleanupDirs as $dir) {
		Storage::disk('fmi')->deleteDirectory($dir);
	}
}

exit(Harness::summary('ETAP 04 — Figma (fixture)'));
