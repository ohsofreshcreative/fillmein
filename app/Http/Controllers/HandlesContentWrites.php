<?php

namespace App\Http\Controllers;

use App\Exceptions\ContentConflictException;
use App\Http\Requests\ClientContentRequest;
use App\Models\ClientAccess;
use App\Models\Field;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Services\ContentService;
use App\Support\ClientStructure;
use Closure;
use Illuminate\Http\JsonResponse;
use WP_User;

/**
 * Wspólne endpointy zapisu edytora klienta i panelu. Zawsze JSON, nigdy przekierowanie
 * (w edytorze klienta nagłówek Location zawierałby token).
 */
trait HandlesContentWrites
{
	abstract protected function who(ClientContentRequest $request): ClientAccess|WP_User;

	abstract protected function project(ClientContentRequest $request): Project;

	abstract protected function forStaff(): bool;

	/**
	 * Adres historii pola: panel ma trasę, a edytor klienta tylko parametry bez tokenu.
	 */
	abstract protected function historyLink(): ?Closure;

	/**
	 * Adres pliku: w edytorze klienta sam parametr, bo pełny adres zawierałby token.
	 */
	abstract protected function assetLink(): Closure;

	protected function writeValue(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $target, ?RepeaterRow $row) use ($request) {
			$value = $this->editor->saveValue($this->who($request), $target, $row, $request->validated(), $request->baseVersion());

			return $this->saved($target, $value->version, 'Zapisano.');
		});
	}

	protected function writeRestore(ClientContentRequest $request, int $field, int $revision): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $target, ?RepeaterRow $row) use ($request, $revision) {
			$value = $this->editor->restore($this->who($request), $target, $row, $revision, $request->baseVersion());

			return $this->saved($target, $value->version, 'Przywrócono wersję.');
		});
	}

	protected function writeNewRow(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $repeater, ?RepeaterRow $parentRow) use ($request) {
			[$row, $value] = $this->editor->addRow($this->who($request), $repeater, $parentRow, $request->baseVersion());

			return $this->saved($repeater, $value->version, 'Dodano element.') + ['html' => $this->rowHtml($repeater, $row)];
		});
	}

	protected function writeRowRemoval(ClientContentRequest $request, int $field, string $row): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $repeater, ?RepeaterRow $parentRow) use ($request, $row) {
			$value = $this->editor->removeRow($this->who($request), $repeater, $parentRow, $row, $request->baseVersion());

			return $this->saved($repeater, $value->version, 'Usunięto element.');
		});
	}

	protected function writeRowOrder(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $repeater, ?RepeaterRow $parentRow) use ($request) {
			$value = $this->editor->reorderRows($this->who($request), $repeater, $parentRow, (array) $request->validated('order'), $request->baseVersion());

			return $this->saved($repeater, $value->version, 'Zapisano kolejność.');
		});
	}

	protected function writeUpload(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $target, ?RepeaterRow $row) use ($request) {
			[$asset, $value] = $this->editor->uploadAsset($this->who($request), $target, $row, $request->file('file'), $request->baseVersion());

			return $this->saved($target, $value->version, 'Dodano zdjęcie.') + ['html' => view('fillmein.client.partials.asset', [
				'field' => $target,
				'row' => $row,
				'asset' => $asset,
				'assetUrl' => $this->assetLink(),
				'first' => true,
				'last' => true,
			])->render()];
		});
	}

	protected function writeDetach(ClientContentRequest $request, int $field, string $asset): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $target, ?RepeaterRow $row) use ($request, $asset) {
			$value = $this->editor->detachAsset($this->who($request), $target, $row, $asset, $request->baseVersion());

			return $this->saved($target, $value->version, 'Odpięto zdjęcie.');
		});
	}

	protected function writeAssetOrder(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $target, ?RepeaterRow $row) use ($request) {
			$value = $this->editor->reorderAssets($this->who($request), $target, $row, (array) $request->validated('order'), $request->baseVersion());

			return $this->saved($target, $value->version, 'Zapisano kolejność.');
		});
	}

	protected function writeMediaLink(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $target, ?RepeaterRow $row) use ($request) {
			$value = $this->editor->saveMediaLink($this->who($request), $target, $row, $request->validated('url'), $request->baseVersion());
			$link = \App\Services\ContentService::mediaLink($value);

			return $this->saved($target, $value->version, $link ? 'Zapisano link do zdjęcia.' : 'Usunięto link do zdjęcia.') + [
				'link' => $link,
				// Zdjęcie: link zastępuje plik, więc widok odświeża listę plików.
				'replaced' => $link !== null && $target->type === \App\Enums\FieldType::Image,
			];
		});
	}

	protected function writeSectionNote(ClientContentRequest $request, int $section): JsonResponse
	{
		try {
			$note = $this->editor->saveSectionNote($this->who($request), $this->project($request), $section, $request->validated('value'), $request->baseVersion(), $this->forStaff());
		} catch (\App\Exceptions\SectionNoteConflictException $conflict) {
			return response()->json([
				'message' => 'Ktoś inny zmienił te uwagi w międzyczasie. Twoja wersja została w polu — sprawdź i zapisz ponownie.',
				'version' => $conflict->current->version,
				'theirs' => (string) $conflict->current->body,
			], 409);
		}

		return response()->json(['version' => $note->version, 'message' => 'Zapisano uwagi.']);
	}

	protected function writeAlt(ClientContentRequest $request, int $field, string $asset): JsonResponse
	{
		return $this->guarded($request, $field, function (Field $target, ?RepeaterRow $row) use ($request, $asset) {
			$this->editor->updateAlt($this->who($request), $target, $row, $asset, $request->validated('alt'));

			return ['message' => 'Zapisano opis.', 'progress' => $this->editor->progress($target)];
		});
	}

	private function guarded(ClientContentRequest $request, int $field, Closure $operation): JsonResponse
	{
		$target = $this->editor->visibleField($this->project($request), $field, $this->forStaff());
		$row = $this->editor->contextRow($target, $request->rowUuid());

		try {
			return response()->json($operation($target, $row));
		} catch (ContentConflictException $conflict) {
			return response()->json($this->conflict($target, $conflict), 409);
		}
	}

	/**
	 * Obie wersje: nowsza zostaje w bazie, a odrzucona wraca do autora, więc żadna nie przepada.
	 */
	private function conflict(Field $field, ContentConflictException $conflict): array
	{
		$current = $conflict->current;

		return [
			'message' => 'Ta treść zmieniła się w międzyczasie. Wybierz, którą wersję zachować.',
			'conflict' => true,
			'mine' => ['value' => $conflict->attempted, 'display' => ContentService::display($field, $conflict->attempted)],
			'current' => [
				'value' => $current->value,
				'display' => ContentService::display($field, $current->value),
				'version' => $current->version,
				'updated_at' => $current->updated_at?->format('H:i'),
			],
		];
	}

	private function saved(Field $field, int $version, string $message): array
	{
		return ['version' => $version, 'message' => $message, 'progress' => $this->editor->progress($field)];
	}

	private function rowHtml(Field $repeater, RepeaterRow $row): string
	{
		/** @var ClientStructure $structure */
		$structure = $this->editor->structure($repeater->section->page, $this->forStaff());

		return view('fillmein.client.partials.row', [
			'structure' => $structure,
			'section' => $repeater->section,
			'repeater' => $repeater,
			'row' => $row,
			'number' => null,
			'historyUrl' => $this->historyLink(),
			'assetUrl' => $this->assetLink(),
		])->render();
	}
}
