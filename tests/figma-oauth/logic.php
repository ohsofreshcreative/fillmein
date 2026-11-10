<?php

/**
 * Połączenie OAuth z Figmą na atrapie HTTP (bez sieci). Opcję odkłada i przywraca run.sh.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Integrations\Figma\FigmaClient;
use App\Integrations\Figma\FigmaConnection;
use App\Integrations\Figma\FigmaException;
use App\Support\SecretBox;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

const CLIENT_SECRET = 'SEKRET_APLIKACJI_DO_NOT_LEAK';
const ACCESS = 'figu_ACCESS_DO_NOT_LEAK';
const REFRESH = 'figur_REFRESH_DO_NOT_LEAK';

putenv(FigmaClient::TOKEN_NAME);

$requests = [];
$queue = [];
$http = new Factory();
$http->fake(function (Request $request) use (&$requests, &$queue, $http) {
	$requests[] = $request;

	return $http::response(...(array_shift($queue) ?? [['error' => 'brak odpowiedzi'], 418]));
});

$fresh = function () use ($http, &$requests, &$queue) {
	delete_option(FigmaConnection::OPTION);
	$requests = [];
	$queue = [];

	return new FigmaConnection($http);
};
$connected = function (int $expiresIn = 7776000) use ($fresh, &$queue, &$requests) {
	$connection = $fresh();
	$connection->saveCredentials('client123', CLIENT_SECRET);
	$queue = [[['access_token' => ACCESS, 'token_type' => 'bearer', 'expires_in' => $expiresIn, 'refresh_token' => REFRESH], 200]];
	$connection->connect('kod', 'weryfikator', 'http://fillmein.local/cb', 7);
	$requests = [];

	return $connection;
};
$raw = fn () => (string) get_option(FigmaConnection::OPTION, '');
$thrown = function (callable $call) {
	try {
		$call();
	} catch (FigmaException $e) {
		return $e;
	}

	return null;
};

Harness::test('SecretBox: odczyt, brak jawnego tekstu, wykrycie zmiany i innego klucza', function () {
	$sealed = SecretBox::seal('tajne');

	Harness::same('tajne', SecretBox::open($sealed), 'Odczyt');
	Harness::assert(! str_contains(base64_decode($sealed), 'tajne'), 'Bez jawnego tekstu');
	Harness::assert($sealed !== SecretBox::seal('tajne'), 'Losowy nonce');
	$raw = base64_decode($sealed);
	$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
	Harness::same(null, SecretBox::open(base64_encode($raw)), 'Zmiana bajtu');
	Harness::same(null, SecretBox::open('to nie jest szyfrogram'), 'Śmieci');

	$salt = fn () => 'inna sól';
	add_filter('salt', $salt);
	wp_cache_flush_runtime();
	$other = SecretBox::open($sealed);
	remove_filter('salt', $salt);
	Harness::same(null, $other, 'Inna sól');
});

Harness::test('Dane aplikacji: szyfrowane, pusty sekret zachowuje zapisany, bez autoload', function () use ($fresh, $raw) {
	global $wpdb;

	$connection = $fresh();
	Harness::same(false, $connection->hasCredentials(), 'Pusto');
	$connection->saveCredentials('client123', CLIENT_SECRET);
	Harness::same(true, $connection->hasCredentials(), 'Zapisane');
	Harness::assert(! str_contains($raw() . base64_decode($raw()), CLIENT_SECRET), 'Bez jawnego sekretu');
	$connection->saveCredentials('client123', null);
	Harness::same(true, $connection->status()['has_secret'], 'Pusty sekret nie kasuje');
	Harness::same('off', $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", FigmaConnection::OPTION)), 'autoload');
	Harness::assert(! str_contains(json_encode($connection->status()), CLIENT_SECRET), 'Status bez sekretu');
});

Harness::test('Adres logowania: parametry i PKCE', function () use ($fresh) {
	$connection = $fresh();
	$connection->saveCredentials('client123', CLIENT_SECRET);
	$url = $connection->authorizationUrl('http://fillmein.local/cb', 'stan', 'weryfikator');
	parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

	Harness::assert(str_starts_with($url, 'https://www.figma.com/oauth?'), 'Adres');
	Harness::same('client123', $query['client_id'], 'client_id');
	Harness::same('http://fillmein.local/cb', $query['redirect_uri'], 'redirect_uri');
	Harness::same('file_content:read', $query['scope'], 'scope');
	Harness::same('stan', $query['state'], 'state');
	Harness::same('code', $query['response_type'], 'response_type');
	Harness::same('S256', $query['code_challenge_method'], 'metoda');
	Harness::same(FigmaConnection::challenge('weryfikator'), $query['code_challenge'], 'challenge');
	Harness::assert(! str_contains($url, CLIENT_SECRET), 'Bez sekretu');
	// Wektor z RFC 7636.
	Harness::same('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', FigmaConnection::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'), 'RFC 7636');
});

Harness::test('Wymiana kodu: Basic auth, parametry, tokeny zaszyfrowane', function () use ($fresh, &$queue, &$requests, $raw) {
	$connection = $fresh();
	$connection->saveCredentials('client123', CLIENT_SECRET);
	$queue = [[['access_token' => ACCESS, 'token_type' => 'bearer', 'expires_in' => 7776000, 'refresh_token' => REFRESH], 200]];
	$connection->connect('kod', 'weryfikator', 'http://fillmein.local/cb', 7);

	$request = $requests[0];
	Harness::same('https://api.figma.com/v1/oauth/token', $request->url(), 'Adres');
	Harness::same('Basic ' . base64_encode('client123:' . CLIENT_SECRET), $request->header('Authorization')[0], 'Basic');
	Harness::same(['redirect_uri' => 'http://fillmein.local/cb', 'code' => 'kod', 'grant_type' => 'authorization_code', 'code_verifier' => 'weryfikator'], $request->data(), 'Treść');
	Harness::same(true, $connection->isConnected(), 'Połączono');
	Harness::same(7, $connection->status()['connected_by'], 'Kto');
	Harness::same(ACCESS, $connection->accessToken(), 'Token');
	Harness::same(1, count($requests), 'Świeży token bez odświeżania');
	Harness::assert(! str_contains($raw() . base64_decode($raw()), ACCESS) && ! str_contains($raw() . base64_decode($raw()), REFRESH), 'Bez jawnych tokenów');
	Harness::assert(! str_contains(json_encode($connection->status()), ACCESS), 'Status bez tokenu');
});

Harness::test('Wymiana kodu: odmowa i zła odpowiedź nie zapisują tokenu ani nie ujawniają danych', function () use ($fresh, &$queue, $thrown) {
	$connection = $fresh();
	$connection->saveCredentials('client123', CLIENT_SECRET);

	$queue = [[['message' => 'zły kod ' . CLIENT_SECRET], 400]];
	$error = $thrown(fn () => $connection->connect('kod', 'w', 'http://fillmein.local/cb', 7));
	Harness::assert($error !== null && ! str_contains($error->getMessage(), CLIENT_SECRET), 'Komunikat bez treści odpowiedzi');
	Harness::same(false, $connection->isConnected(), 'Bez połączenia po 400');

	$queue = [[['access_token' => ACCESS], 200]];
	Harness::assert($thrown(fn () => $connection->connect('kod', 'w', 'http://fillmein.local/cb', 7)) !== null, 'Brak expires_in');
	$queue = [[['access_token' => ACCESS, 'expires_in' => 100], 200]];
	Harness::assert($thrown(fn () => $connection->connect('kod', 'w', 'http://fillmein.local/cb', 7)) !== null, 'Brak refresh_token');
	Harness::same(false, $connection->isConnected(), 'Nadal bez połączenia');
});

Harness::test('Odświeżanie: blisko wygaśnięcia nowy token, refresh_token zachowany', function () use ($connected, &$queue, &$requests) {
	$connection = $connected(60);
	$queue = [[['access_token' => 'figu_NOWY', 'token_type' => 'bearer', 'expires_in' => 7776000], 200]];

	Harness::same('figu_NOWY', $connection->accessToken(), 'Nowy token');
	Harness::same('https://api.figma.com/v1/oauth/refresh', $requests[0]->url(), 'Adres');
	Harness::same(['refresh_token' => REFRESH], $requests[0]->data(), 'Treść');
	Harness::same('figu_NOWY', $connection->accessToken(), 'Bez ponownego odświeżania');
	Harness::same(1, count($requests), 'Jedno żądanie');

	$later = $connected(60);
	$queue = [[['access_token' => 'figu_DRUGI', 'expires_in' => 60], 200], [['access_token' => 'figu_TRZECI', 'expires_in' => 7776000], 200]];
	$later->accessToken();
	Harness::same('figu_TRZECI', $later->accessToken(), 'Stary refresh_token nadal działa');
	Harness::same(['refresh_token' => REFRESH], $requests[1]->data(), 'Ten sam refresh_token');
});

Harness::test('Odświeżanie: awaria Figmy nie blokuje ważnego tokenu, odrzucenie prosi o połączenie', function () use ($connected, &$queue, $thrown) {
	$connection = $connected(3600);
	$queue = [[['x' => 1], 500]];
	Harness::same(ACCESS, $connection->accessToken(), 'Ważny token przy 500');

	$connection = $connected(3600);
	$queue = [[['x' => 1], 400]];
	$error = $thrown(fn () => $connection->accessToken());
	Harness::same(FigmaException::UNAUTHORIZED, $error?->kind, 'Powód');
	Harness::assert(str_contains((string) $error?->getMessage(), 'Połącz ponownie'), 'Komunikat');
});

Harness::test('FigmaClient: Bearer z połączenia, nagłówek X-Figma-Token tylko dla stałego tokenu', function () use ($connected, &$queue, &$requests, $http, $fresh) {
	$connection = $connected();
	$client = new FigmaClient($http, fn () => null, null, $connection);

	Harness::same(true, $client->isConfigured(), 'Skonfigurowany');
	$queue = [[['nodes' => []], 200]];
	$client->fileNodes('FIXTUREkey01', ['1:2']);
	Harness::same('Bearer ' . ACCESS, $requests[0]->header('Authorization')[0], 'Bearer');
	Harness::same([], $requests[0]->header('X-Figma-Token'), 'Bez X-Figma-Token');

	$requests = [];
	$static = new FigmaClient($http, fn () => null, 'figd_stały', $connection);
	$queue = [[['nodes' => []], 200]];
	$static->fileNodes('FIXTUREkey01', ['1:2']);
	Harness::same('figd_stały', $requests[0]->header('X-Figma-Token')[0], 'Stały token ma pierwszeństwo');
	Harness::same([], $requests[0]->header('Authorization'), 'Bez Bearer');

	Harness::same(false, (new FigmaClient($http, fn () => null, null, $fresh()))->isConfigured(), 'Bez połączenia');
});

Harness::test('Klient z kontenera widzi połączenie, tak jak strona podstrony', function () use ($connected, $fresh) {
	$connected();
	Harness::same(true, app(FigmaClient::class)->isConfigured(), 'FigmaClient');
	Harness::same(true, app(App\Services\FigmaFrameService::class)->isConfigured(), 'FigmaFrameService');

	$fresh();
	Harness::same(false, app(FigmaClient::class)->isConfigured(), 'Bez połączenia');
});

Harness::test('Rozłączenie: kasuje tokeny, zachowuje dane aplikacji; zmiana Client ID też kasuje tokeny', function () use ($connected) {
	$connection = $connected();
	$connection->disconnect();
	Harness::same(false, $connection->isConnected(), 'Rozłączono');
	Harness::same(true, $connection->hasCredentials(), 'Dane zostają');
	Harness::same(null, $connection->accessToken(), 'Bez tokenu');

	$connection = $connected();
	$connection->saveCredentials('client123', null);
	Harness::same(true, $connection->isConnected(), 'Ten sam Client ID');
	$connection->saveCredentials('inny', null);
	Harness::same(false, $connection->isConnected(), 'Inny Client ID');
});

Harness::test('Nieczytelna opcja: brak połączenia i wyraźny status', function () use ($fresh) {
	$connection = $fresh();
	update_option(FigmaConnection::OPTION, 'uszkodzone', false);

	Harness::same(false, $connection->isConnected(), 'Bez połączenia');
	Harness::same(true, $connection->status()['unreadable'], 'Status');
	$connection->saveCredentials('client123', CLIENT_SECRET);
	Harness::same(false, $connection->status()['unreadable'], 'Zapis naprawia');
});

delete_option(FigmaConnection::OPTION);

exit(Harness::summary('FIGMA OAUTH — logika'));
