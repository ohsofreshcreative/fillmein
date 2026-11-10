<?php

/**
 * ReviewDiscussionService i ProjectPolicy — cykl statusów, historia, konflikty, idempotencja, uprawnienia.
 * W transakcji wycofywanej (dane z fixtures.php zostają nietknięte).
 */

use App\Enums\ReviewThreadStatus as S;
use App\Exceptions\ReviewConflictException;
use App\Models\Project;
use App\Models\ReviewComment;
use App\Models\ReviewThread;
use App\Models\ReviewThreadEvent;
use App\Services\ReviewCommentService;
use App\Services\ReviewDiscussionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$ids = json_decode((string) file_get_contents('/tmp/fmi-review-discussions.json'), true);
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
$can = fn ($user, string $ability, array $args) => Gate::forUser($user)->allows($ability, $args);

DB::beginTransaction();
try {
	$d = Project::query()->findOrFail($ids['d']);
	$e = Project::query()->findOrFail($ids['e']);
	$s = app(ReviewDiscussionService::class);
	$t1 = ReviewThread::query()->findOrFail($ids['t1']);
	$t2 = ReviewThread::query()->findOrFail($ids['t2']);

	echo "== Cykl statusów ==\n";
	$check($t1->status === S::Open && S::Open->label() === 'Nowy', 'Nowa uwaga: status Nowy (wartość open z etapu 04)');
	$t1 = $s->changeStatus($d, $t1, S::InProgress, 0, $manager);
	$check($t1->status === S::InProgress && $t1->lock_version === 1, 'Nowy → W trakcie (wersja 1)');
	$t1 = $s->changeStatus($d, $t1, S::Resolved, 1, $manager);
	$check($t1->status === S::Resolved && $t1->resolved_by === $manager->ID && $t1->resolved_at !== null, 'W trakcie → Rozwiązany (kto, kiedy)');
	$t1 = $s->changeStatus($d, $t1, S::Open, 2, $manager);
	$check($t1->status === S::Open && $t1->resolved_at === null && $t1->resolved_by === null, 'Ponowne otwarcie: Rozwiązany → Nowy, znacznik rozwiązania wyczyszczony');
	$events = ReviewThreadEvent::query()->where('thread_id', $t1->id)->orderBy('id')->get();
	$check($events->pluck('type')->all() === ['status_changed', 'status_changed', 'reopened'], 'Historia: 2 zmiany statusu + ponowne otwarcie');
	$check($events->every(fn ($event) => $event->actor_user_id === $manager->ID && $event->created_at !== null), 'Historia: autor i czas każdego wpisu');
	$check($events->last()->from_status === S::Resolved && $events->last()->to_status === S::Open, 'Historia: z → do przy ponownym otwarciu');

	echo "== Konflikty i ponowienia statusu ==\n";
	$check($throws(fn () => $s->changeStatus($d, $t1, S::Resolved, 0, $manager), ReviewConflictException::class), 'Nieaktualna wersja → konflikt (nic nie zapisano)');
	$check(ReviewThread::query()->find($t1->id)->status === S::Open, 'Po konflikcie status bez zmian');
	$before = ReviewThreadEvent::query()->count();
	$s->changeStatus($d, $t1, S::InProgress, 3, $manager);
	$s->changeStatus($d, $t1, S::InProgress, 3, $manager);
	$check(ReviewThreadEvent::query()->count() === $before + 1, 'Ponowienie tej samej zmiany (po timeoucie) → bez drugiego wpisu');

	echo "== Odpowiedzi ==\n";
	$rid = (string) Str::uuid();
	$r1 = $s->reply($d, $t2, 'Odpowiedź', $rid, $manager);
	$r2 = $s->reply($d, $t2, 'Odpowiedź', $rid, $manager);
	$check($r1->id === $r2->id && ReviewComment::query()->where('request_id', $rid)->count() === 1, 'Ponowienie odpowiedzi z tym samym request_id → jeden wpis');
	$check($r1->thread_id === $t2->id && $r1->project_id === $d->id, 'Odpowiedź należy do wątku i projektu');
	$s->reply($d, $t2, 'Druga sesja', (string) Str::uuid(), $member);
	$check($t2->comments()->count() === 3, 'Dwie różne odpowiedzi → obie zapisane');
	$check($throws(fn () => $s->reply($d, ReviewThread::query()->findOrFail($ids['t3']), 'x', $rid, $manager), ValidationException::class), 'Ten sam request_id w innym wątku → odrzucony');
	$check($throws(fn () => $s->reply($d, ReviewThread::query()->findOrFail($ids['te']), 'x', (string) Str::uuid(), $manager), Symfony\Component\HttpKernel\Exception\HttpException::class), 'Wątek obcego projektu → 404');
	$t3 = $s->changeStatus($d, ReviewThread::query()->findOrFail($ids['t3']), S::Resolved, 0, $manager);
	$check($throws(fn () => $s->reply($d, $t3, 'x', (string) Str::uuid(), $manager), ValidationException::class), 'Odpowiedź w rozwiązanym wątku → odrzucona (najpierw ponowne otwarcie)');

	echo "== Edycja ==\n";
	$c2 = ReviewComment::query()->findOrFail($ids['c2']);
	$edited = $s->edit($d, $c2, 'Uwaga członka (poprawiona)', 0, $member);
	$event = ReviewThreadEvent::query()->where('comment_id', $c2->id)->first();
	$check($edited->body === 'Uwaga członka (poprawiona)' && $edited->lock_version === 1 && $edited->edited_at !== null, 'Edycja: nowa treść, wersja, znacznik czasu');
	$check($event && $event->type === 'comment_edited' && $event->previous_body === 'Uwaga członka' && $event->actor_user_id === $member->ID, 'Historia: poprzednia treść, autor edycji');
	$check($throws(fn () => $s->edit($d, $c2, 'Inna wersja', 0, $member), ReviewConflictException::class), 'Jednoczesna edycja (stara wersja) → konflikt');
	$check(ReviewComment::query()->find($c2->id)->body === 'Uwaga członka (poprawiona)', 'Po konflikcie treść nie nadpisana');
	$s->edit($d, $c2, 'Uwaga członka (poprawiona)', 0, $member);
	$check(ReviewThreadEvent::query()->where('comment_id', $c2->id)->count() === 1, 'Ponowienie tej samej edycji → bez drugiego wpisu');

	echo "== Tworzenie uwagi: ponowienie ==\n";
	$rid2 = (string) Str::uuid();
	$a = app(ReviewCommentService::class)->create($d, ['body' => 'Retry', 'context' => 'Menu', 'request_id' => $rid2], $member);
	$b = app(ReviewCommentService::class)->create($d, ['body' => 'Retry', 'context' => 'Menu', 'request_id' => $rid2], $member);
	$check($a->id === $b->id && ReviewThread::query()->where('project_id', $d->id)->where('context', 'Menu')->count() === 1, 'Ponowienie utworzenia uwagi → jeden wątek, jeden numer');

	echo "== Uprawnienia (ProjectPolicy) ==\n";
	$t1 = ReviewThread::query()->findOrFail($ids['t1']);
	$t2 = ReviewThread::query()->findOrFail($ids['t2']);
	$check($can($manager, 'changeReviewStatus', [$d, $t1, S::Resolved]), 'Opiekun: zmiana statusu');
	$check(! $can($member, 'changeReviewStatus', [$d, $t2, S::InProgress]) && ! $can($member, 'changeReviewStatus', [$d, $t2, S::Resolved]), 'Pracownik bez fmi_manage_reviews: bez zmiany statusu (także własnej uwagi)');
	$s->changeStatus($d, $t2, S::Resolved, 0, $manager);
	$t2 = $t2->fresh();
	$check($can($member, 'changeReviewStatus', [$d, $t2, S::Open]), 'Autor (pracownik): ponowne otwarcie własnej rozwiązanej uwagi');
	$check(! $can($member, 'changeReviewStatus', [$d, $t3->fresh(), S::Open]), 'Pracownik: bez ponownego otwarcia cudzej uwagi');
	$check(! $can($outsider, 'changeReviewStatus', [$d, $t1, S::Resolved]), 'Spoza projektu: odmowa');
	$check($can($member, 'editReviewComment', [$d, $c2->fresh()]) && ! $can($manager, 'editReviewComment', [$d, $c2->fresh()]), 'Edycja tylko własnych wiadomości (opiekun nie edytuje cudzych)');
	$check(! $can($manager, 'editReviewComment', [$d, ReviewComment::query()->findOrFail($ids['ce'])]), 'Wiadomość obcego projektu: odmowa');
} finally {
	DB::rollBack();
}

echo "SERWIS: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
