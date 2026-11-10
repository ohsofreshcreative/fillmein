<?php

/**
 * Zapis linku prototypu bez sieci: każde żądanie HTTP kończy się błędem połączenia, a zapis i tak przechodzi
 * i nie wysyła żadnego żądania (serwer nie pobiera adresów Figmy). Transakcja wycofywana.
 */

use App\Enums\ReviewRevisionStatus;
use App\Models\Project;
use App\Services\ProjectService;
use App\Services\ReviewService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
wp_set_current_user($admin->ID);
$pass = 0;
$fail = 0;
$check = function (bool $ok, string $label) use (&$pass, &$fail) {
	$ok ? $pass++ : $fail++;
	echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
};

Http::fake(fn () => throw new ConnectionException('Sieć niedostępna (test).'));
DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST sieć ' . wp_generate_password(6, false), 'modules' => ['review']], $admin);
	$service = app(ReviewService::class);

	$first = $service->savePrototype($project, 'https://www.figma.com/proto/abcdefghij12/Sie%C4%87?node-id=1-2', $admin);
	$check($first->number === 1 && $first->status === ReviewRevisionStatus::Draft, 'Bez sieci: pierwsza rewizja (szkic) zapisana');
	$check($first->figma_file_key === 'abcdefghij12' && $first->figma_node_id === '1:2', 'Klucz pliku i warstwa z linku');

	$second = $service->savePrototype($project, 'https://www.figma.com/design/abcdefghij12/X', $admin);
	$check($second->id === $first->id && $second->figma_url === 'https://www.figma.com/design/abcdefghij12/X', 'Szkic zaktualizowany, bez nowej rewizji');

	$first->update(['status' => ReviewRevisionStatus::InReview]);
	$third = $service->savePrototype($project, 'https://www.figma.com/proto/abcdefghij12/Y', $admin);
	$check($third->number === 2 && $first->fresh()->figma_url === 'https://www.figma.com/design/abcdefghij12/X', 'Po rewizji w recenzji: nowa rewizja 2, poprzednia nietknięta');

	try {
		$service->savePrototype($project, 'https://evil.example/proto/abcdefghij12', $admin);
		$check(false, 'Serwis odrzuca obcą domenę');
	} catch (InvalidArgumentException $e) {
		$check(str_contains($e->getMessage(), 'figma.com'), 'Serwis odrzuca obcą domenę: ' . $e->getMessage());
	}

	$check(Http::recorded()->isEmpty(), 'Żadnego żądania HTTP (0 wysłanych)');
} finally {
	DB::rollBack();
}

echo "SIEĆ: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
