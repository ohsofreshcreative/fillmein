<?php

/**
 * FigmaEmbed::forLink — adres osadzenia tylko z udokumentowanych części (Embed Kit 2.0).
 */

use App\Integrations\Figma\FigmaEmbed;

$pass = 0;
$fail = 0;
$check = function (bool $ok, string $label) use (&$pass, &$fail) {
	$ok ? $pass++ : $fail++;
	echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
};
$parts = function (?array $embed): array {
	if (! $embed) {
		return [];
	}
	parse_str((string) parse_url($embed['src'], PHP_URL_QUERY), $q);

	return ['host' => parse_url($embed['src'], PHP_URL_HOST), 'path' => parse_url($embed['src'], PHP_URL_PATH), 'q' => $q];
};

$p = $parts($e = FigmaEmbed::forLink('https://www.figma.com/proto/abcdefghij12/Nazwa?node-id=1-2&starting-point-node-id=3%3A4&scaling=free&embed-host=evil&client-id=zzz#x', null));
$check($p['host'] === 'embed.figma.com' && $p['path'] === '/proto/abcdefghij12', 'Prototyp → https://embed.figma.com/proto/{klucz}');
$check($p['q'] === ['embed-host' => 'fillmein', 'node-id' => '1-2', 'starting-point-node-id' => '3:4'], 'Tylko embed-host, node-id, starting-point-node-id (parametry z linku nie przechodzą)');
$check($e['interactive'] === true && $e['events'] === false, 'Bez client-id: interaktywny, bez zdarzeń');
$check(! str_contains($e['src'], '#'), 'Bez fragmentu #');

$p = $parts($e = FigmaEmbed::forLink('https://www.figma.com/proto/abcdefghij12/X', 'AbCdEf1234567890'));
$check(($p['q']['client-id'] ?? null) === 'AbCdEf1234567890' && $e['events'] === true, 'Poprawny client-id → Embed API włączone');
$p = $parts($e = FigmaEmbed::forLink('https://www.figma.com/proto/abcdefghij12/X', 'zły id"><script>'));
$check(! isset($p['q']['client-id']) && $e['events'] === false, 'Niepoprawny client-id pominięty');

$p = $parts($e = FigmaEmbed::forLink('https://www.figma.com/design/abcdefghij12/X?node-id=5-6', 'AbCdEf1234567890'));
$check($p['path'] === '/design/abcdefghij12' && $e['interactive'] === false && $e['events'] === false && ! isset($p['q']['client-id']), 'Link projektu → /design, bez przejść i bez Embed API');

$p = $parts(FigmaEmbed::forLink('https://www.figma.com/proto/abcdefghij12/branch/BRANCHKEY12345/X', null));
$check($p['path'] === '/proto/BRANCHKEY12345', 'Gałąź → klucz gałęzi');

foreach (['', 'https://evil.example/proto/abcdefghij12/x', 'http://www.figma.com/proto/abcdefghij12/x', 'javascript:alert(1)', 'https://www.figma.com/board/abcdefghij12/x'] as $bad) {
	$check(FigmaEmbed::forLink($bad, null) === null, 'Odrzucony: ' . ($bad === '' ? '(pusty)' : $bad));
}

config(['fillmein.figma.embed.embed_api' => false]);
$check(FigmaEmbed::forLink('https://www.figma.com/proto/abcdefghij12/X', 'AbCdEf1234567890')['events'] === false, 'embed_api=false → bez client-id');

echo "OSADZENIE (jednostkowe): {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
