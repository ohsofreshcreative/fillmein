<?php

/**
 * Atrapa Figma API dla testów etapu 04 (bez sieci).
 *
 * Odpowiedzi podstawia `Illuminate\Http\Client\Factory::fake()`; żądanie, które nie pasuje do żadnej
 * zdefiniowanej trasy, dostaje 418 i jest widoczne w `requests()` — test to wykryje.
 * Token testowy jest fikcyjny i służy wyłącznie do sprawdzenia, że nie wycieka.
 */

use App\Integrations\Figma\FigmaClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

final class FakeFigma
{
	public const TOKEN = 'figd_FMI_TEST_TOKEN_DO_NOT_LEAK_0123456789';

	public const FILE_KEY = 'FIXTUREkey01';

	public Factory $http;

	/** @var float[] */
	public array $sleeps = [];

	/** @var array<int, array{pattern: string, queue: array}> */
	private array $routes = [];

	public function __construct()
	{
		$this->http = new Factory();
		$this->http->fake(fn (Request $request) => $this->handle($request));
	}

	/**
	 * Kolejka odpowiedzi dla adresów pasujących do wyrażenia. Ostatnia odpowiedź się powtarza.
	 * Odpowiedź: [treść, status, nagłówki] albo 'connection' (błąd połączenia).
	 */
	public function on(string $pattern, array ...$responses): self
	{
		$this->routes[] = ['pattern' => $pattern, 'queue' => $responses];

		return $this;
	}

	public function failConnection(string $pattern): self
	{
		$this->routes[] = ['pattern' => $pattern, 'queue' => [['connection']]];

		return $this;
	}

	public function client(?string $token = self::TOKEN): FigmaClient
	{
		return new FigmaClient($this->http, function (float $seconds): void {
			$this->sleeps[] = $seconds;
		}, $token);
	}

	/**
	 * @return Request[]
	 */
	public function requests(?string $pattern = null): array
	{
		return array_values(array_filter(
			array_map(fn ($pair) => $pair[0], $this->http->recorded()->all()),
			fn (Request $request) => $pattern === null || preg_match($pattern, $request->url()) === 1,
		));
	}

	private function handle(Request $request)
	{
		foreach ($this->routes as $index => $route) {
			if (preg_match($route['pattern'], $request->url()) !== 1) {
				continue;
			}

			$response = count($route['queue']) > 1 ? array_shift($this->routes[$index]['queue']) : $route['queue'][0];

			if ($response[0] === 'connection') {
				return Factory::failedConnection();
			}

			return Factory::response($response[0], $response[1] ?? 200, $response[2] ?? []);
		}

		return Factory::response('unmatched', 418);
	}

	public static function fixture(string $name): string
	{
		return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
	}

	/**
	 * Prawdziwy plik PNG o zadanych wymiarach (GD) — „wyrenderowany” podgląd w testach.
	 */
	public static function png(int $width = 8, int $height = 6): string
	{
		$image = imagecreatetruecolor($width, $height);
		imagefill($image, 0, 0, imagecolorallocate($image, 200, 230, 60));
		ob_start();
		imagepng($image);
		$png = (string) ob_get_clean();
		imagedestroy($image);

		return $png;
	}
}
