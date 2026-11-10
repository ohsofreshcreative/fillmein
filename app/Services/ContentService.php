<?php

namespace App\Services;

use App\Enums\FieldType;
use App\Exceptions\ContentConflictException;
use App\Models\Asset;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\Section;
use App\Models\SectionNote;
use App\Exceptions\SectionNoteConflictException;
use App\Models\RepeaterRow;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Zapis treści pól — fundament autozapisu.
 *
 * - Optymistyczna blokada: klient wysyła wersję, na której bazował. Atomowy UPDATE
 *   `WHERE version = ?` gwarantuje, że nowsza zmiana nie zostanie cicho nadpisana.
 * - Idempotencja: ponowienie tego samego zapisu (np. po zerwanym połączeniu, gdy serwer
 *   zdążył go przyjąć) nie jest konfliktem — zwracamy aktualny stan.
 * - Blokada: wiersz wartości jest zablokowany przez całą transakcję (`FOR UPDATE`), a warunkowy UPDATE
 *   zostaje drugą linią obrony.
 * - Historia: każda zmiana to dokładnie jedna rewizja, a rewizje są niemodyfikowalne.
 *
 * Ten sam mechanizm (wersja + rewizje) obsłuży w kolejnych etapach wartości-listy:
 * kolejność elementów repeatera i zdjęć galerii (JSON z UUID) — patrz App\Enums\FieldType.
 */
class ContentService
{
	/**
	 * Ponowienia transakcji po zakleszczeniu: równoległe pierwsze zapisy mogą się zderzyć na indeksie unikalnym.
	 */
	private const ATTEMPTS = 3;

	private const HTML_TAGS = ['p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'ul' => [], 'ol' => [], 'li' => []];

	/**
	 * Zapis pola tekstowego lub linku (JSON). `$row` — element repeatera, w którym leży pole (null poza repeaterem).
	 */
	public function save(Field $field, ?string $value, int $baseVersion, Actor $actor, ?RepeaterRow $row = null): FieldValue
	{
		if (! $field->type->storesPlainValue()) {
			throw new InvalidArgumentException('Ta metoda zapisuje wyłącznie pola tekstowe i linki.');
		}

		if ($row && $row->project_id !== $field->project_id) {
			throw new InvalidArgumentException('Element repeatera nie należy do projektu pola.');
		}

		$this->assertWritable($field, $row);
		$this->assertValue($field, $value);

		return DB::transaction(function () use ($field, $value, $baseVersion, $actor, $row) {
			$current = $this->findOrCreate($field, $row);

			if ($current->value === $value) {
				return $current;
			}

			return $this->write($current, $value, $baseVersion, $actor);
		}, self::ATTEMPTS);
	}

	/**
	 * Przywrócenie wersji z historii: zwykły zapis tej wartości, więc powstaje nowa rewizja, a stare zostają.
	 */
	public function restore(FieldValue $value, FieldRevision $revision, int $baseVersion, Actor $actor): FieldValue
	{
		if ($revision->field_value_id !== $value->id) {
			throw ValidationException::withMessages(['revision' => 'Ta wersja nie należy do tego pola.']);
		}

		return $this->save($value->field, $revision->value, $baseVersion, $actor, $value->row_key === '' ? null : $value->row);
	}

	/**
	 * Postać zapisywana w bazie dla danych z formularza. Reguły wartości sprawdza save().
	 *
	 * @param array{value?: ?string, label?: ?string, url?: ?string} $input
	 */
	public function normalize(Field $field, array $input): ?string
	{
		if ($field->type === FieldType::Link) {
			$label = trim((string) ($input['label'] ?? ''));
			$url = trim((string) ($input['url'] ?? ''));

			return $label === '' && $url === ''
				? null
				: json_encode(['label' => $label, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		$value = (string) ($input['value'] ?? '');

		if ($field->type === FieldType::Wysiwyg) {
			$value = self::cleanHtml($value);
		}

		return $value === '' ? null : $value;
	}

	public static function cleanHtml(?string $html): string
	{
		return trim(wp_kses((string) $html, self::HTML_TAGS));
	}

	/**
	 * Tekst wartości do podglądu (konflikt, historia), bez znaczników.
	 */
	public static function display(Field $field, ?string $value): string
	{
		if ($field->type === FieldType::Link) {
			$link = json_decode((string) $value, true);

			return is_array($link) ? trim(($link['label'] ?? '') . ' ' . ($link['url'] ?? '')) : '';
		}

		return $field->type === FieldType::Wysiwyg ? wp_strip_all_tags((string) $value) : (string) $value;
	}

	/**
	 * Nowy wiersz repeatera na końcu listy. `$parentRow` — wiersz repeatera nadrzędnego (null na najwyższym poziomie).
	 *
	 * @return array{0: RepeaterRow, 1: FieldValue}
	 */
	public function addRow(Field $repeater, int $baseVersion, Actor $actor, ?RepeaterRow $parentRow = null): array
	{
		return DB::transaction(function () use ($repeater, $baseVersion, $actor, $parentRow) {
			$current = $this->lockedList($repeater, $baseVersion, $parentRow);
			$list = $this->rowList($current);
			$limit = (int) ($repeater->settings['max_items'] ?? config('fillmein.fields.max_items', 100));

			if (count($list) >= $limit) {
				throw ValidationException::withMessages(['row' => sprintf('Ta lista może mieć najwyżej %d elementów.', $limit)]);
			}

			$row = RepeaterRow::create([
				'project_id' => $repeater->project_id,
				'field_id' => $repeater->id,
				'parent_row_id' => $parentRow?->id,
				'position' => count($list),
				'created_by_type' => $actor->type->value,
				'created_by_user_id' => $actor->userId,
				'created_by_access_id' => $actor->clientAccessId,
			]);

			$list[] = $row->uuid;

			return [$row, $this->write($current, json_encode($list), $baseVersion, $actor)];
		}, self::ATTEMPTS);
	}

	/**
	 * Usunięcie wiersza z listy: wiersz jest usuwany miękko, a jego treść zostaje w bazie.
	 */
	public function removeRow(Field $repeater, RepeaterRow $row, int $baseVersion, Actor $actor, ?RepeaterRow $parentRow = null): FieldValue
	{
		return DB::transaction(function () use ($repeater, $row, $baseVersion, $actor, $parentRow) {
			$current = $this->lockedList($repeater, $baseVersion, $parentRow);
			$list = $this->rowList($current);
			$minimum = (int) ($repeater->settings['min_items'] ?? 0);

			if (count($list) <= $minimum) {
				throw ValidationException::withMessages(['row' => sprintf('Ta lista musi mieć co najmniej %d elementów.', $minimum)]);
			}

			$row->delete();

			return $this->write($current, json_encode(array_values(array_diff($list, [$row->uuid]))), $baseVersion, $actor);
		}, self::ATTEMPTS);
	}

	/**
	 * Nowa kolejność wierszy. Lista musi zawierać dokładnie te same wiersze, co zapisana.
	 *
	 * @param string[] $uuids
	 */
	public function reorderRows(Field $repeater, array $uuids, int $baseVersion, Actor $actor, ?RepeaterRow $parentRow = null): FieldValue
	{
		return DB::transaction(function () use ($repeater, $uuids, $baseVersion, $actor, $parentRow) {
			$current = $this->lockedList($repeater, $baseVersion, $parentRow);
			$list = $this->rowList($current);

			if (count($uuids) !== count($list) || array_diff($uuids, $list) !== []) {
				throw ValidationException::withMessages(['order' => 'Lista elementów zmieniła się. Odśwież stronę.']);
			}

			foreach (array_values($uuids) as $position => $uuid) {
				RepeaterRow::query()->where('uuid', $uuid)->update(['position' => $position]);
			}

			return $this->write($current, json_encode(array_values($uuids)), $baseVersion, $actor);
		}, self::ATTEMPTS);
	}

	/**
	 * Przypięcie pliku: zdjęcie zastępuje poprzednie, galeria dostaje go na końcu. Odpięty plik zostaje w magazynie.
	 */
	public function attachAsset(Field $field, Asset $asset, int $baseVersion, Actor $actor, ?RepeaterRow $row = null): FieldValue
	{
		return DB::transaction(function () use ($field, $asset, $baseVersion, $actor, $row) {
			$current = $this->lockedMedia($field, $asset, $baseVersion, $row);
			$list = self::rowList($current);

			if (in_array($asset->uuid, $list, true)) {
				throw ValidationException::withMessages(['file' => 'Ten plik jest już dodany do tego pola.']);
			}

			// Zdjęcie: plik albo link („lub to, lub to”) — nowy plik zastępuje i plik, i link. Galeria zachowuje link.
			$links = $field->type === FieldType::Image ? [] : self::linkEntries($current);

			if ($field->type === FieldType::Image) {
				$list = [];
			}

			if (count($list) >= self::galleryLimit($field)) {
				throw ValidationException::withMessages(['file' => sprintf('To pole może mieć najwyżej %d zdjęć.', self::galleryLimit($field))]);
			}

			$list[] = $asset->uuid;

			return $this->writeMedia($current, $list, $baseVersion, $actor, $links);
		}, self::ATTEMPTS);
	}

	/**
	 * Odpięcie pliku od pola: nowa wersja listy. Wiersz w fmi_assets i plik na dysku zostają.
	 */
	public function detachAsset(Field $field, Asset $asset, int $baseVersion, Actor $actor, ?RepeaterRow $row = null): FieldValue
	{
		return DB::transaction(function () use ($field, $asset, $baseVersion, $actor, $row) {
			$current = $this->lockedMedia($field, $asset, $baseVersion, $row);
			$list = self::rowList($current);

			if (! in_array($asset->uuid, $list, true)) {
				throw ValidationException::withMessages(['file' => 'Tego pliku nie ma już w tym polu.']);
			}

			return $this->writeMedia($current, array_values(array_diff($list, [$asset->uuid])), $baseVersion, $actor, self::linkEntries($current));
		}, self::ATTEMPTS);
	}

	/**
	 * Nowa kolejność zdjęć galerii. Lista musi zawierać dokładnie te same pliki, co zapisana.
	 *
	 * @param string[] $uuids
	 */
	public function reorderAssets(Field $field, array $uuids, int $baseVersion, Actor $actor, ?RepeaterRow $row = null): FieldValue
	{
		return DB::transaction(function () use ($field, $uuids, $baseVersion, $actor, $row) {
			$current = $this->lockedMedia($field, null, $baseVersion, $row);
			$list = self::rowList($current);

			if (count($uuids) !== count($list) || array_diff($uuids, $list) !== []) {
				throw ValidationException::withMessages(['order' => 'Lista zdjęć zmieniła się. Odśwież stronę.']);
			}

			return $this->writeMedia($current, array_values($uuids), $baseVersion, $actor, self::linkEntries($current));
		}, self::ATTEMPTS);
	}

	/**
	 * Link do zdjęcia (np. ze stocka) zamiast pliku albo obok galerii. Zapisywany w tej samej liście co pliki jako
	 * `{"link": "…"}` — `rowList()` zwraca tylko UUID plików, więc dotychczasowe odczyty go pomijają. Pusty adres
	 * usuwa link. Dla zdjęcia link zastępuje plik. Serwer nie pobiera obrazu spod adresu (zespół robi to ręcznie).
	 */
	public function saveMediaLink(Field $field, ?string $url, int $baseVersion, Actor $actor, ?RepeaterRow $row = null): FieldValue
	{
		$url = trim((string) $url);

		if ($url !== '' && (mb_strlen($url) > 2000 || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
			throw ValidationException::withMessages(['url' => 'Wklej pełny adres zaczynający się od https://']);
		}

		return DB::transaction(function () use ($field, $url, $baseVersion, $actor, $row) {
			$current = $this->lockedMedia($field, null, $baseVersion, $row);
			$list = $field->type === FieldType::Image && $url !== '' ? [] : self::rowList($current);

			return $this->writeMedia($current, $list, $baseVersion, $actor, $url !== '' ? [['link' => $url]] : []);
		}, self::ATTEMPTS);
	}

	/**
	 * Dodatkowe uwagi pod sekcją: jedna notatka na sekcję, z wersją jak wartość pola (konflikt → wyjątek z bieżącą).
	 * Pusta treść zostawia rekord z `body = null`, żeby wersja nie wracała do zera.
	 */
	public function saveSectionNote(Section $section, ?string $body, int $baseVersion, Actor $actor): SectionNote
	{
		$body = trim(str_replace("\r\n", "\n", (string) $body));

		if (mb_strlen($body) > 10000) {
			throw ValidationException::withMessages(['body' => 'Uwagi mogą mieć najwyżej 10 000 znaków.']);
		}

		return DB::transaction(function () use ($section, $body, $baseVersion, $actor) {
			$current = SectionNote::query()->where('section_id', $section->id)->lockForUpdate()->first()
				?? SectionNote::query()->create(['project_id' => $section->project_id, 'section_id' => $section->id, 'version' => 0]);

			if ($current->version !== $baseVersion) {
				throw new SectionNoteConflictException($current);
			}

			if ((string) $current->body === $body) {
				return $current;
			}

			$current->forceFill([
				'body' => $body !== '' ? $body : null,
				'version' => $current->version + 1,
				'updated_by_type' => $actor->type->value,
				'updated_by_user_id' => $actor->userId,
				'updated_by_access_id' => $actor->clientAccessId,
			])->save();

			return $current;
		}, self::ATTEMPTS);
	}

	public static function mediaLink(?FieldValue $value): ?string
	{
		return self::linkEntries($value)[0]['link'] ?? null;
	}

	/**
	 * @return list<array{link: string}>
	 */
	private static function linkEntries(?FieldValue $value): array
	{
		$list = json_decode((string) $value?->value, true);

		return is_array($list)
			? array_values(array_map(fn ($entry) => ['link' => $entry['link']], array_filter($list, fn ($entry) => is_array($entry) && is_string($entry['link'] ?? null) && $entry['link'] !== '')))
			: [];
	}

	public static function galleryLimit(Field $field): int
	{
		return $field->type === FieldType::Image
			? 1
			: (int) ($field->settings['max_items'] ?? config('fillmein.fields.max_items', 100));
	}

	/**
	 * @return string[]
	 */
	public static function rowList(?FieldValue $value): array
	{
		$list = json_decode((string) $value?->value, true);

		return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
	}

	/**
	 * Wyczyszczenie treści pola po niezgodnej zmianie typu (np. tekst → zdjęcie).
	 * Bieżąca wartość zostaje w historii rewizji; nowa wersja chroni przed nadpisaniem przez
	 * otwarty edytor ze starą wersją (konflikt zamiast cichego zapisu w złym formacie).
	 *
	 * @return int liczba wyczyszczonych wartości
	 */
	public function clearForTypeChange(Field $field, Actor $actor): int
	{
		return DB::transaction(function () use ($field, $actor) {
			$values = FieldValue::query()->where('field_id', $field->id)->lockForUpdate()->get();
			$cleared = 0;

			foreach ($values as $value) {
				if (! self::hasContent($value->value)) {
					continue;
				}

				// Wartość bez rewizji swojej wersji (np. zapisana poza ContentService) — utrwalamy ją przed wyczyszczeniem.
				if ((int) $value->revisions()->value('version') !== $value->version) {
					$this->createRevision($value, $actor);
				}

				$value->forceFill([
					'value' => null,
					'version' => $value->version + 1,
					'updated_by_type' => $actor->type->value,
					'updated_by_user_id' => $actor->userId,
					'updated_by_access_id' => $actor->clientAccessId,
				])->save();

				DB::table('fmi_field_value_assets')->where('field_value_id', $value->id)->delete();

				$this->createRevision($value, $actor);
				$cleared++;
			}

			return $cleared;
		});
	}

	/**
	 * Czy wartość zawiera treść (pusta lista UUID repeatera lub galerii to brak treści).
	 */
	public static function hasContent(?string $value): bool
	{
		return $value !== null && $value !== '' && $value !== '[]';
	}

	/**
	 * Lista wierszy nie ma scalania jak tekst: inna wersja bazowa oznacza zawsze konflikt.
	 */
	private function lockedList(Field $repeater, int $baseVersion, ?RepeaterRow $parentRow): FieldValue
	{
		if ($repeater->type !== FieldType::Repeater) {
			throw new InvalidArgumentException('Wiersze ma wyłącznie repeater.');
		}

		$this->assertWritable($repeater, $parentRow);

		$current = $this->findOrCreate($repeater, $parentRow);

		if ($current->version !== $baseVersion) {
			throw new ContentConflictException($current);
		}

		return $current;
	}

	private function lockedMedia(Field $field, ?Asset $asset, int $baseVersion, ?RepeaterRow $row): FieldValue
	{
		if (! $field->type->isMedia()) {
			throw new InvalidArgumentException('Pliki mają wyłącznie pola zdjęcia i galerii.');
		}

		if ($asset && $asset->project_id !== $field->project_id) {
			throw new InvalidArgumentException('Plik nie należy do projektu pola.');
		}

		$this->assertWritable($field, $row);

		$current = $this->findOrCreate($field, $row);

		if ($current->version !== $baseVersion) {
			throw new ContentConflictException($current);
		}

		return $current;
	}

	/**
	 * Lista UUID w wartości i `position` w tabeli powiązań zmieniają się w tej samej transakcji.
	 *
	 * @param string[] $list
	 */
	/**
	 * @param string[] $list UUID plików w kolejności
	 * @param list<array{link: string}> $links link do zdjęcia (najwyżej jeden), zapisywany na końcu listy
	 */
	private function writeMedia(FieldValue $current, array $list, int $baseVersion, Actor $actor, array $links = []): FieldValue
	{
		$fresh = $this->write($current, json_encode([...$list, ...array_slice($links, 0, 1)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $baseVersion, $actor);
		$ids = Asset::query()->whereIn('uuid', $list)->pluck('id', 'uuid');
		$pivot = [];

		foreach ($list as $position => $uuid) {
			$pivot[$ids[$uuid]] = ['position' => $position];
		}

		$fresh->assets()->sync($pivot);

		return $fresh;
	}

	private function write(FieldValue $current, ?string $value, int $baseVersion, Actor $actor): FieldValue
	{
		// `$current` pochodzi z odczytu blokującego, więc widzi najnowszą wersję także w REPEATABLE READ.
		if ($current->version !== $baseVersion) {
			throw new ContentConflictException($current, $value);
		}

		$updated = FieldValue::query()
			->whereKey($current->id)
			->where('version', $baseVersion)
			->update([
				'value' => $value,
				'version' => DB::raw('version + 1'),
				'updated_by_type' => $actor->type->value,
				'updated_by_user_id' => $actor->userId,
				'updated_by_access_id' => $actor->clientAccessId,
				'updated_at' => now(),
			]);

		$fresh = $current->fresh();

		if ($updated === 0) {
			throw new ContentConflictException($current, $value);
		}

		$this->createRevision($fresh, $actor);

		return $fresh;
	}

	/**
	 * Zwraca zablokowany wiersz wartości. Brak rekordu: INSERT IGNORE zamiast SELECT … FOR UPDATE na pustym
	 * zakresie, bo blokady luk dwóch transakcji nie wykluczają się i kończyłyby się zakleszczeniem przy wstawianiu.
	 */
	private function findOrCreate(Field $field, ?RepeaterRow $row): FieldValue
	{
		$attributes = ['field_id' => $field->id, 'row_key' => $row?->uuid ?? ''];

		if (! FieldValue::query()->where($attributes)->exists()) {
			FieldValue::query()->insertOrIgnore($attributes + [
				'project_id' => $field->project_id,
				'row_id' => $row?->id,
				'version' => 0,
				'created_at' => now(),
				'updated_at' => now(),
			]);
		}

		return FieldValue::query()->where($attributes)->lockForUpdate()->firstOrFail();
	}

	/**
	 * Pole musi być aktywne, także po drodze przez kontenery, sekcję i podstronę, a wiersz należeć
	 * do najbliższego repeatera pola.
	 */
	private function assertWritable(Field $field, ?RepeaterRow $row): void
	{
		$section = $field->section;
		$active = ! $section->isArchived() && ! $section->page->isArchived();

		for ($node = $field; $active && $node; $node = $node->parent) {
			$active = ! $node->isArchived();
		}

		if (! $active) {
			throw ValidationException::withMessages(['value' => 'To pole jest w archiwum.']);
		}

		$repeater = $field->closestRepeater();

		if ($row?->trashed() || (int) $repeater?->id !== (int) $row?->field_id) {
			throw ValidationException::withMessages(['row' => 'Ten element nie należy do listy tego pola.']);
		}
	}

	private function assertValue(Field $field, ?string $value): void
	{
		if ($value === null) {
			return;
		}

		if ($field->type === FieldType::Link) {
			$this->assertLink($value);

			return;
		}

		$length = mb_strlen($field->type === FieldType::Wysiwyg ? wp_strip_all_tags($value) : $value);
		$limit = (int) ($field->settings['max_length'] ?? 0);

		if ($limit > 0 && $length > $limit) {
			throw ValidationException::withMessages(['value' => sprintf('Tekst może mieć najwyżej %d znaków (teraz: %d).', $limit, $length)]);
		}
	}

	private function assertLink(string $value): void
	{
		$link = json_decode($value, true);

		if (! is_array($link) || ! array_key_exists('label', $link) || ! array_key_exists('url', $link)) {
			throw ValidationException::withMessages(['value' => 'Link ma nieprawidłową postać.']);
		}

		if ($link['url'] !== '' && ! preg_match('~^(https?://[^\s]+|mailto:[^\s]+|tel:[0-9+() -]+)$~i', (string) $link['url'])) {
			throw ValidationException::withMessages(['url' => 'Podaj pełny adres, np. https://example.com.']);
		}
	}

	private function createRevision(FieldValue $value, Actor $actor): void
	{
		FieldRevision::create([
			'project_id' => $value->project_id,
			'field_value_id' => $value->id,
			'version' => $value->version,
			'value' => $value->value,
			'actor_type' => $actor->type,
			'actor_user_id' => $actor->userId,
			'actor_access_id' => $actor->clientAccessId,
		]);
	}
}
