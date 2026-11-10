<?php

namespace App\Services;

use App\Enums\ReviewRevisionStatus;
use App\Integrations\Figma\FigmaUrl;
use App\Models\FigmaFrame;
use App\Models\Project;
use App\Models\ReviewRevision;
use App\Models\ReviewScreen;
use App\Support\Actor;
use InvalidArgumentException;
use WP_User;

/**
 * Ekrany rewizji: frame'y z pliku rewizji pobrane przez FigmaFrameService (API Figmy, stały adres; obraz na dysku `fmi`).
 *
 * Powierzchnia jest potwierdzona, gdy obraz frame'u ma dokładnie wymiary jego absoluteBoundingBox × skala podglądu
 * (±1 px) — wtedy x, y (0–1) względem obrazu = względem granic frame'u, niezależnie od rozmiaru wyświetlania.
 * Frame bez przycięcia treści (obraz większy od granic) → ekran bez pinezek (uwagi tylko do ekranu).
 */
class ReviewScreenService
{
	public function __construct(private FigmaFrameService $frames, private ActivityLogger $activity)
	{
	}

	/**
	 * @param ?string $name Nazwa podstrony nadana przez zespół; pusta → nazwa frame'u z Figmy.
	 */
	public function add(Project $project, ReviewRevision $revision, string $url, WP_User $by, ?string $name = null): ReviewScreen
	{
		if ($revision->project_id !== $project->id || $revision->status === ReviewRevisionStatus::Superseded) {
			throw new InvalidArgumentException('Ekrany można dodawać tylko do bieżącej rewizji.');
		}

		$target = self::target($url);

		if ($target === null) {
			throw new InvalidArgumentException('Wklej link do konkretnego frame\'u z Figmy (adres z parametrem node-id).');
		}

		if ($revision->figma_file_key !== null && $target['file_key'] !== $revision->figma_file_key) {
			throw new InvalidArgumentException('Ekran musi pochodzić z tego samego pliku Figmy co prototyp rewizji.');
		}

		$existing = $revision->screens()->where('node_id', $target['node_id'])->first();

		if (! $existing && $revision->screens()->count() >= (int) config('fillmein.review.max_screens', 30)) {
			throw new InvalidArgumentException('Rewizja ma już maksymalną liczbę ekranów.');
		}

		$result = $this->frames->fetchTarget($project, $target, $by);
		$frame = $result['frame'];
		$confirmed = self::surfaceConfirmed($frame);

		$screen = ReviewScreen::query()->updateOrCreate(
			['revision_id' => $revision->id, 'node_id' => $target['node_id']],
			[
				'project_id' => $project->id,
				'figma_frame_id' => $frame->id,
				'file_key' => $target['file_key'],
				'name' => mb_substr(trim((string) $name) ?: ($existing?->name ?: ($frame->node_name ?: 'Ekran ' . $target['node_id'])), 0, 190),
				'width' => $frame->width,
				'height' => $frame->height,
				'surface_confirmed' => $confirmed,
				'figma_version' => $frame->file_version,
				'position' => $existing?->position ?? (int) $revision->screens()->max('position') + 1,
				'created_by' => $existing?->created_by ?? $by->ID,
			],
		);

		// Rewizja bez linku prototypu: plik pierwszej podstrony wyznacza plik rewizji (ten sam plik dla kolejnych
		// podstron, odczyt wersji przy decyzji).
		if ($revision->figma_file_key === null) {
			$revision->update(['figma_file_key' => $target['file_key'], 'figma_node_id' => $target['node_id']]);
		}

		$this->activity->log('review.screen_added', Actor::user($by), $project, $screen, [
			'revision' => $revision->number,
			'screen' => $screen->name,
			'pins' => $confirmed,
		]);

		return $screen;
	}

	/**
	 * Klucz pliku i warstwa z linku `/design/…` lub `/proto/…` z parametrem `node-id` (dokładnie ten frame).
	 *
	 * @return array{file_key: string, node_id: string}|null
	 */
	public static function target(?string $url): ?array
	{
		$link = FigmaUrl::prototype($url);

		if ($link === null) {
			return null;
		}

		parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $params);
		$node = is_string($params['node-id'] ?? null) ? FigmaUrl::decodeNodeId($params['node-id']) : null;

		return $node ? ['file_key' => $link['file_key'], 'node_id' => $node] : null;
	}

	public static function surfaceConfirmed(FigmaFrame $frame): bool
	{
		$preview = $frame->previews()->where('node_id', $frame->node_id)->first();
		$scale = (float) config('fillmein.figma.preview_scale', 1);

		if (! $preview || ! $preview->width || ! $preview->height || ! $frame->width || ! $frame->height) {
			return false;
		}

		return abs($preview->width - $frame->width * $scale) <= 1 && abs($preview->height - $frame->height * $scale) <= 1;
	}
}
