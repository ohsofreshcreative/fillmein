<?php

/**
 * ReviewDecisionService — rozdzielenie stanów rewizji A/B, historia, konflikty, idempotencja, uprawnienia,
 * utrwalenie wersji Figmy i wykrycie zmiany zawartości pod tym samym URL (FigmaClient zastąpiony atrapą —
 * bez realnego API). W transakcji wycofywanej (dane z fixtures.php zostają nietknięte).
 */

use App\Enums\ReviewRevisionStatus as S;
use App\Exceptions\ReviewConflictException;
use App\Integrations\Figma\FigmaClient;
use App\Models\ClientAccess;
use App\Models\Project;
use App\Models\ReviewDecision;
use App\Services\ReviewDecisionService;
use App\Services\ReviewService;
use App\Support\ReviewGuest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

$ids = json_decode((string) file_get_contents('/tmp/fmi-review-decisions.json'), true);
$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$manager = get_user_by('id', (int) $manifest['users']['manager']);
$member = get_user_by('id', (int) $manifest['users']['member']);
$outsider = get_user_by('id', (int) $manifest['users']['outsider']);
$pass = 0;
$fail = 0;
$check = function (bool $ok, string $label) use (&$pass, &$fail) {
	$ok ? $pass++ : $fail++;
	echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
};
$throws = function (callable $fn, string $class) {
	try {
		$fn();

		return false;
	} catch (Throwable $e) {
		return $e instanceof $class;
	}
};

// Atrapa Figmy: plik pod tym samym URL zmienia zawartość → inna `version`.
$fake = new class extends FigmaClient {
	public ?string $version = null;

	public int $calls = 0;

	public function __construct()
	{
	}

	public function isConfigured(): bool
	{
		return true;
	}

	public function fileNodes(string $fileKey, array $nodeIds, ?int $depth = null): array
	{
		$this->calls++;

		if ($this->version === null) {
			throw new RuntimeException('Figma niedostępna');
		}

		return ['version' => $this->version, 'lastModified' => '2026-11-03T10:00:00Z', 'nodes' => []];
	}
};
app()->instance(FigmaClient::class, $fake);
app()->forgetInstance(ReviewDecisionService::class);
$decisions = app(ReviewDecisionService::class);
$reviews = app(ReviewService::class);

DB::beginTransaction();

try {
	$d = Project::findOrFail($ids['d']);
	$e = Project::findOrFail($ids['e']);
	$a = $d->reviewRevisions()->where('number', 1)->firstOrFail();

	echo "== Uprawnienia ==\n";
	$check(Gate::forUser($manager)->allows('decideReview', $d), 'Opiekun (fmi_manage_reviews, przypisany) może decydować');
	$check(Gate::forUser($member)->denies('decideReview', $d), 'Członek bez fmi_manage_reviews nie może decydować');
	$check(Gate::forUser($outsider)->denies('decideReview', $d), 'Osoba nieprzypisana nie może decydować');
	$check(Gate::forUser($manager)->denies('decideReview', $e), 'Opiekun D nie decyduje o obcym projekcie E');
	$check($throws(fn () => $decisions->decide($d, $e->reviewRevisions()->first(), S::Approved, null, (string) Str::uuid(), $manager), Symfony\Component\HttpKernel\Exception\HttpException::class), 'Rewizja obcego projektu → 404');
	$check($throws(fn () => $decisions->decide($d, $a, S::Superseded, null, (string) Str::uuid(), $manager), Symfony\Component\HttpKernel\Exception\HttpException::class), 'Niedozwolona decyzja → 422');

	echo "== Rewizja A: akceptacja, utrwalona wersja Figmy ==\n";
	$fake->version = '1111111111';
	$req = (string) Str::uuid();
	$first = $decisions->decide($d, $a, S::Approved, 'OK', $req, $manager);
	$check($first->decision === S::Approved && $a->fresh()->status === S::Approved, 'A: zaakceptowana');
	$check($first->revision_id === $a->id && $first->revision_number === 1, 'Decyzja wskazuje rewizję A (id i numer)');
	$check($first->actor_type->value === 'user' && $first->actor_user_id === $manager->ID && $first->identification === 'wp_account', 'Aktor: konto opiekuna, identyfikacja wp_account');
	$check($first->created_at !== null && abs($first->created_at->diffInSeconds(now())) < 60, 'Czas serwerowy');
	$check($first->figma_url === $a->figma_url && $first->figma_node_id === '1:2', 'Utrwalony link i węzeł Figmy');
	$check($first->version_status === ReviewDecision::PINNED && $first->figma_version === '1111111111' && $first->figma_last_modified !== null, 'Utrwalona wersja pliku Figmy (pinned)');
	$check($decisions->decide($d, $a, S::Approved, 'OK', $req, $manager)->id === $first->id, 'Ponowienie z tym samym request_id → ta sama decyzja');
	$check($throws(fn () => $decisions->decide($d, $a, S::ChangesRequested, 'Zmień', (string) Str::uuid(), $manager), ReviewConflictException::class), 'Druga decyzja dla A → konflikt (bez nadpisania)');
	$check(ReviewDecision::query()->where('revision_id', $a->id)->count() === 1 && $a->fresh()->status === S::Approved, 'A: jedna decyzja, stan bez zmian');

	echo "== Ten sam URL, zmieniona zawartość w Figmie ==\n";
	$check($decisions->compare($first)['status'] === 'same', 'Ta sama wersja → same');
	$fake->version = '2222222222';
	$cmp = $decisions->compare($first);
	$check($cmp['status'] === 'changed' && $cmp['current'] === '2222222222', 'Inna wersja pod tym samym URL → changed');
	$check($first->fresh()->figma_version === '1111111111' && $a->fresh()->status === S::Approved, 'Decyzja nadal wskazuje wersję z chwili akceptacji');

	echo "== Rewizja B: jawne utworzenie, oddzielny stan ==\n";
	$b = $reviews->createRevision($d, $manager);
	$check($b->number === 2 && $b->status === S::Draft && $b->status->isPending() && $b->figma_url === $a->figma_url, 'B: nowa, oczekująca, ten sam link');
	$check($b->decision()->doesntExist(), 'B: bez decyzji (akceptacja A nie przechodzi)');
	$check($a->fresh()->status === S::Approved && $a->decision()->exists(), 'A: decyzja w historii, status zachowany');
	$check($throws(fn () => $decisions->decide($d, $a, S::ChangesRequested, 'x', (string) Str::uuid(), $manager), ReviewConflictException::class), 'Decyzja dla starej A po utworzeniu B → konflikt');
	$second = $decisions->decide($d, $b, S::ChangesRequested, 'Popraw stopkę', (string) Str::uuid(), $manager);
	$check($second->revision_number === 2 && $second->figma_version === '2222222222' && $b->fresh()->status === S::ChangesRequested, 'B: poprawki, wersja 2222222222');
	$check($first->fresh()->decision === S::Approved && ReviewDecision::query()->where('project_id', $d->id)->count() === 2, 'Historia: A zaakceptowana, B do poprawy');

	echo "== Rewizja C: brak wersji z Figmy, gość z linkiem ==\n";
	$fake->version = null;
	$c = $reviews->createRevision($d, $manager);
	$access = ClientAccess::findOrFail($ids['access']['full']);
	$guest = new ReviewGuest($access, 'Anna z firmy');
	$third = $decisions->decide($d, $c, S::Approved, null, (string) Str::uuid(), $guest);
	$check($third->version_status === ReviewDecision::UNVERIFIED && $third->figma_version === null, 'Brak odpowiedzi Figmy → unverified (jawne ograniczenie)');
	$check($third->actor_type->value === 'client' && $third->actor_access_id === $access->id && $third->actor_user_id === null, 'Gość: typ klienta, link, bez konta');
	$check($third->actor_name === 'Anna z firmy' && $third->identification === 'review_link', 'Nazwa deklarowana, identyfikacja review_link');
	$check($decisions->compare($third)['status'] === 'unknown', 'Porównanie bez wersji → unknown');
	$codeGuest = new ReviewGuest(ClientAccess::findOrFail($ids['access']['code']), 'Ktoś');
	$dd = $reviews->createRevision($d, $manager);
	$check($decisions->decide($d, $dd, S::Approved, null, (string) Str::uuid(), $codeGuest)->identification === 'review_link_code', 'Link z kodem → review_link_code');

	echo "== Nowa rewizja bez decyzji poprzedniej ==\n";
	$e1 = $reviews->createRevision($d, $manager);
	$e2 = $reviews->createRevision($d, $manager);
	$check($e1->fresh()->status === S::Superseded && $e2->status === S::Draft, 'Rewizja bez decyzji → Zastąpiona, nowa oczekuje');
	$check($throws(fn () => $decisions->decide($d, $e1->fresh(), S::Approved, null, (string) Str::uuid(), $manager), ReviewConflictException::class), 'Decyzja dla zastąpionej → konflikt');
} finally {
	DB::rollBack();
}

echo "DECYZJE (serwis): {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
