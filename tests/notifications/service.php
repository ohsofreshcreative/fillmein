<?php

/**
 * NotificationService — odbiorcy wg członkostwa i uprawnień, deduplikacja (ponowna obsługa zdarzenia, ponowienie
 * żądania z tym samym request_id), brak sekretów w danych, historia (aktor, czas, kontekst). Transakcja wycofywana.
 */

use App\Enums\AccountStatus;
use App\Models\Activity;
use App\Models\Notification;
use App\Models\Project;
use App\Services\NotificationService;
use App\Services\ReviewCommentService;
use App\Support\ActivityPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$ids = json_decode((string) file_get_contents('/tmp/fmi-notifications.json'), true);
$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$u = fn ($k) => (int) $manifest['users'][$k];
$manager = get_user_by('id', $u('manager'));
$pass = 0;
$fail = 0;
$check = function (bool $ok, string $label) use (&$pass, &$fail) {
	$ok ? $pass++ : $fail++;
	echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
};
$n = Project::findOrFail($ids['n']);
$of = fn (string $type) => Notification::query()->where('project_id', $n->id)->where('type', $type)->orderBy('user_id')->pluck('user_id')->all();
$sorted = function (array $values) { sort($values); return $values; };

echo "== Odbiorcy (dane z fixtures) ==\n";
$comments = Notification::query()->where('project_id', $n->id)->where('type', Notification::COMMENT)->get()->groupBy('subject_id');
$guestComment = $comments->first(fn ($rows) => ($rows->first()->data['number'] ?? 0) === $ids['t1']);
$managerComment = $comments->first(fn ($rows) => ($rows->first()->data['number'] ?? 0) === $ids['t2']);
$check($sorted($guestComment->pluck('user_id')->all()) === $sorted([$u('admin'), $u('manager'), $u('member')]), 'Uwaga gościa → autor projektu, opiekun, członek (po jednym)');
$check($sorted($managerComment->pluck('user_id')->all()) === $sorted([$u('admin'), $u('member')]), 'Uwaga opiekuna → bez samego opiekuna');
$check($sorted($of(Notification::REPLY)) === $sorted([$u('admin'), $u('manager')]), 'Odpowiedź członka → autor, opiekun');
$check($sorted($of(Notification::DECISION)) === $sorted([$u('admin'), $u('member')]), 'Decyzja opiekuna → autor, członek');
$check(Notification::query()->where('user_id', $u('outsider'))->whereIn('project_id', [$ids['n'], $ids['o']])->doesntExist(), 'Osoba nieprzypisana: brak powiadomień');
$check(Notification::query()->where('project_id', $ids['o'])->doesntExist(), 'Projekt O (tylko autor = autor uwagi): brak powiadomień');
$all = Notification::query()->where('project_id', $n->id)->get();
$check($all->every(fn ($row) => $row->read_at === null && $row->created_at !== null), 'Nowe powiadomienia nieprzeczytane, z czasem');
$check($all->every(fn ($row) => ! str_contains(json_encode($row->data), $ids['token']) && ! str_contains(json_encode($row->data), hash('sha256', $ids['token']))), 'Dane powiadomień bez tokenu linku');
$guestRow = $guestComment->first();
$check(($guestRow->data['actor'] ?? '') === 'Ewa <b>Gość</b> (gość)', 'Aktor gościa jako nazwa deklarowana (gość)');
$check(str_contains($guestRow->title(), 'dodał(a) uwagę ' . $ids['t1']), 'Tytuł z numerem uwagi');

DB::beginTransaction();

try {
	echo "== Deduplikacja ==\n";
	$service = app(NotificationService::class);
	$before = Notification::query()->count();
	$again = $service->notify($n, Notification::COMMENT, $guestRow->subject_id, $guestRow->data, null);
	$check($again === 0 && Notification::query()->count() === $before, 'Ponowna obsługa tego samego zdarzenia → 0 nowych');
	$request = (string) Str::uuid();
	$t = app(ReviewCommentService::class)->create($n, ['body' => 'Raz', 'context' => 'X', 'request_id' => $request], $manager);
	$afterFirst = Notification::query()->count();
	$retry = app(ReviewCommentService::class)->create($n, ['body' => 'Raz', 'context' => 'X', 'request_id' => $request], $manager);
	$check($retry->id === $t->id && Notification::query()->count() === $afterFirst && $afterFirst === $before + 2, 'Ponowienie żądania (request_id) → bez duplikatów');

	echo "== Uprawnienia odbiorców ==\n";
	// Meta WordPressa jest poza transakcją Laravela — przywracane jawnie.
	update_user_meta($u('member'), AccountStatus::META_DEACTIVATED_AT, time());

	try {
		$recipients = array_map(fn ($user) => $user->ID, $service->recipients($n));
	} finally {
		delete_user_meta($u('member'), AccountStatus::META_DEACTIVATED_AT);
	}

	$check(! in_array($u('member'), $recipients, true), 'Konto dezaktywowane nie jest odbiorcą');
	$check(in_array($u('member'), array_map(fn ($user) => $user->ID, $service->recipients($n)), true), 'Po przywróceniu konta: znów odbiorca');
	$n->forceFill(['review_enabled' => false])->save();
	$check($service->notify($n, Notification::COMMENT, 999999, [], null) === 0, 'Moduł Design Review wyłączony → brak powiadomień');

	echo "== Historia ==\n";
	$entries = Activity::query()->where('project_id', $n->id)->where('event', 'like', 'review.%')->get();
	$guestEntry = $entries->first(fn ($a) => $a->event === 'review.comment_added' && $a->actor_type->value === 'client');
	$check($guestEntry && ActivityPresenter::actor($guestEntry) === 'Ewa <b>Gość</b> (gość, nazwa deklarowana)', 'Aktor gościa: nazwa deklarowana');
	$check($guestEntry && ActivityPresenter::context($guestEntry) === 'Rewizja 1 · Uwaga ' . $ids['t1'], 'Kontekst uwagi: rewizja i numer');
	$decisionEntry = $entries->firstWhere('event', 'review.decision');
	$check($decisionEntry && ActivityPresenter::context($decisionEntry) === 'Rewizja 1 · Do poprawy' && ActivityPresenter::label($decisionEntry) === 'Zapisano decyzję o rewizji', 'Kontekst decyzji');
	$check($entries->every(fn ($a) => $a->created_at !== null && ! str_contains(json_encode($a->properties), $ids['token'])), 'Wpisy z czasem, bez tokenu');
	$check(Activity::query()->where('project_id', $n->id)->filtered(['typ' => 'recenzja', 'aktor' => '', 'od' => '', 'do' => '', 'strona' => null])->count() === $entries->count(), 'Filtr historii „Design Review”');
} finally {
	DB::rollBack();
}

echo "POWIADOMIENIA (serwis): {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
