<?php

namespace App\Support;

use App\Enums\ActorType;
use App\Models\Activity;
use App\Services\RoleManager;

/**
 * Opisy zdarzeń dziennika aktywności po polsku.
 *
 * Dziennik nigdy nie zawiera tokenów ani sekretów, więc prezenter pokazuje wyłącznie
 * nazwy i identyfikatory techniczne. Nieznane zdarzenie pokazujemy jako jego klucz —
 * lepiej to niż ukrycie wpisu.
 */
final class ActivityPresenter
{
	private const LABELS = [
		'project.created' => 'Utworzono projekt',
		'project.updated' => 'Zmieniono ustawienia projektu',
		'project.status_changed' => 'Zmieniono status projektu',
		'project.deleted' => 'Usunięto projekt',
		'project.manager_changed' => 'Zmieniono opiekuna projektu',
		'project.member_added' => 'Dodano pracownika do projektu',
		'project.member_removed' => 'Usunięto pracownika z projektu',
		'project.members_changed' => 'Zmieniono listę pracowników projektu',
		'page.created' => 'Dodano podstronę',
		'page.updated' => 'Zmieniono podstronę',
		'page.archived' => 'Zarchiwizowano podstronę',
		'page.restored' => 'Przywrócono podstronę',
		'page.reordered' => 'Zmieniono kolejność podstron',
		'page.duplicated' => 'Zduplikowano podstronę',
		'page.duplicated_by_client' => 'Klient zduplikował podstronę',
		'page.archived_by_client' => 'Klient usunął dodaną przez siebie podstronę',
		'section.created' => 'Dodano sekcję',
		'section.updated' => 'Zmieniono sekcję',
		'section.archived' => 'Zarchiwizowano sekcję',
		'section.restored' => 'Przywrócono sekcję',
		'section.reordered' => 'Zmieniono kolejność sekcji',
		'field.created' => 'Dodano pole',
		'field.updated' => 'Zmieniono pole',
		'field.archived' => 'Zarchiwizowano pole',
		'field.restored' => 'Przywrócono pole',
		'field.reordered' => 'Zmieniono kolejność pól',
		'figma.frame_fetched' => 'Pobrano frame z Figmy',
		'figma.credentials_saved' => 'Zapisano dane aplikacji Figma',
		'figma.connected' => 'Połączono z Figmą',
		'figma.disconnected' => 'Rozłączono z Figmą',
		'figma.import_applied' => 'Zsynchronizowano sekcje i pola z Figmą',
		'figma.node_rule_changed' => 'Zmieniono wykluczenie warstwy Figmy',
		'export.completed' => 'Wygenerowano eksport',
		'export.failed' => 'Eksport nie powiódł się',
		'mapping.updated' => 'Zmieniono mapowanie ACF',
		'site_schema.uploaded' => 'Wgrano schemat strony WordPress',
		'site_mapping.auto' => 'Dopasowano automatycznie do schematu strony',
		'site_mapping.updated' => 'Zmieniono dopasowanie do schematu strony',
		'client_access.generated' => 'Wygenerowano link dla klienta',
		'client_access.revoked' => 'Unieważniono link klienta',
		'client_access.review_generated' => 'Utworzono link recenzji dla klienta',
		'content.updated' => 'Zapisano treść',
		'content.restored' => 'Przywrócono wersję treści',
		'content.asset_added' => 'Dodano zdjęcie',
		'content.asset_removed' => 'Odpięto zdjęcie',
		'content.assets_reordered' => 'Zmieniono kolejność zdjęć',
		'content.section_note' => 'Zapisano dodatkowe uwagi sekcji',
		'content.review_requested' => 'Oznaczono treść do poprawy',
		'review.prototype_saved' => 'Zapisano link prototypu Figmy',
		'review.revision_created' => 'Utworzono rewizję do recenzji',
		'review.screen_added' => 'Dodano ekran do rewizji',
		'review.comment_added' => 'Dodano uwagę w Design Review',
		'review.reply_added' => 'Dodano odpowiedź w Design Review',
		'review.comment_edited' => 'Edytowano wiadomość w Design Review',
		'review.thread_status' => 'Zmieniono status uwagi w Design Review',
		'review.decision' => 'Zapisano decyzję o rewizji',
		'content.review_cleared' => 'Zdjęto oznaczenie do poprawy',
		'content.row_added' => 'Dodano element listy',
		'content.row_removed' => 'Usunięto element listy',
		'content.rows_reordered' => 'Zmieniono kolejność elementów listy',
	];

	public static function label(Activity $activity): string
	{
		return self::LABELS[$activity->event] ?? $activity->event;
	}

	/**
	 * Kto wykonał operację. Klient nie ma konta, więc pokazujemy etykietę linku.
	 */
	public static function actor(Activity $activity): string
	{
		if ($activity->actor_type === ActorType::Client) {
			$name = trim((string) ($activity->properties['guest_name'] ?? ''));

			return $name !== '' ? $name . ' (gość, nazwa deklarowana)' : 'Klient (link dostępowy)';
		}

		if ($activity->actor_type === ActorType::System) {
			return 'System';
		}

		$user = $activity->user_id ? get_userdata($activity->user_id) : null;

		return $user ? Staff::fullName($user) : 'Konto usunięte';
	}

	/**
	 * Krótki kontekst zdarzenia (nazwa podstrony, nowy status itp.).
	 */
	public static function context(Activity $activity): ?string
	{
		$properties = $activity->properties ?? [];

		if ($activity->event === 'figma.import_applied' && is_array($properties['counts'] ?? null)) {
			return ($properties['page_name'] ?? '') . ': ' . self::importSummary($properties['counts']);
		}

		if (isset($properties['page_name'], $properties['field_label'])) {
			return $properties['page_name'] . ' · ' . $properties['field_label'];
		}

		if (isset($properties['page_name'])) {
			return (string) $properties['page_name'];
		}

		if (str_starts_with($activity->event, 'review.')) {
			return self::reviewContext($activity->event, $properties);
		}

		if (isset($properties['to']) && $activity->event === 'project.status_changed') {
			return \App\Enums\ProjectStatus::tryFrom((string) $properties['to'])?->label();
		}

		if (isset($properties['user_id'])) {
			$user = get_userdata((int) $properties['user_id']);

			return $user ? Staff::fullName($user) : null;
		}

		if (isset($properties['changed']) && is_array($properties['changed'])) {
			return implode(', ', array_map(fn ($field) => self::fieldLabel((string) $field), $properties['changed']));
		}

		return null;
	}

	private static function reviewContext(string $event, array $properties): ?string
	{
		$parts = [];

		if (isset($properties['revision'])) {
			$parts[] = 'Rewizja ' . (int) $properties['revision'];
		}

		if (isset($properties['number'])) {
			$parts[] = 'Uwaga ' . (int) $properties['number'];
		}

		if ($event === 'review.decision' && isset($properties['decision'])) {
			$parts[] = \App\Enums\ReviewRevisionStatus::tryFrom((string) $properties['decision'])?->label();
		}

		if ($event === 'review.thread_status' && isset($properties['to'])) {
			$parts[] = \App\Enums\ReviewThreadStatus::tryFrom((string) $properties['to'])?->label();
		}

		$parts = array_filter($parts);

		return $parts === [] ? null : implode(' · ', $parts);
	}

	private static function importSummary(array $counts): string
	{
		$moved = (int) ($counts['moved'] ?? 0);
		$parts = array_filter([
			'nowe' => (int) ($counts['create'] ?? 0),
			'zmienione' => (int) ($counts['update'] ?? 0) - $moved,
			'przeniesione' => $moved,
			'przywrócone' => (int) ($counts['restore'] ?? 0),
			'do archiwum' => (int) ($counts['archive'] ?? 0),
		]);

		return $parts === []
			? 'bez zmian'
			: implode(', ', array_map(fn ($label, $count) => "{$label} {$count}", array_keys($parts), $parts));
	}

	private static function fieldLabel(string $field): string
	{
		return match ($field) {
			'name' => 'nazwa',
			'slug' => 'slug',
			'client_name' => 'nazwa klienta',
			'parent_id' => 'podstrona nadrzędna',
			'figma_file_url', 'figma_frame_url' => 'link do Figmy',
			'client_can_duplicate' => 'zgoda na duplikowanie',
			'content_enabled' => 'moduł Treści',
			'review_enabled' => 'moduł Design Review',
			default => $field,
		};
	}

	/**
	 * Rola wykonawcy, gdy konto jeszcze istnieje (kontekst dla przeglądającego dziennik).
	 */
	public static function actorRole(Activity $activity): ?string
	{
		if ($activity->actor_type !== ActorType::User || ! $activity->user_id) {
			return null;
		}

		$user = get_userdata($activity->user_id);

		return $user ? RoleManager::label(RoleManager::roleOf($user)) : null;
	}
}
