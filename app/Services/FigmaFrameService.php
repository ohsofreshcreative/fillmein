<?php

namespace App\Services;

use App\Integrations\Figma\FigmaClient;
use App\Integrations\Figma\FigmaException;
use App\Integrations\Figma\FigmaUrl;
use App\Models\FigmaFrame;
use App\Models\FigmaPreview;
use App\Models\Page;
use App\Models\Project;
use App\Support\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use WP_User;

/**
 * Pobranie z Figmy JEDNEGO frame'u podstrony (nigdy całego pliku), cache metadanych i podglądów.
 *
 * - Zakres: GET /v1/files/:key/nodes?ids={frame} — wyłącznie wskazany frame i jego poddrzewo
 *   do `fillmein.figma.node_depth` poziomów. Węzły spoza żądania są ignorowane.
 * - Cache: metadane z wersją pliku i czasem pobrania (`fmi_figma_frames`); w ciągu
 *   `cache_ttl_minutes` nie pytamy API ponownie (chyba że `force`).
 * - Podglądy: frame + bezpośrednie dzieci. Pliki na prywatnym dysku `fmi`; tymczasowy adres z Figmy
 *   jest tylko referencją i odświeżamy go po wygaśnięciu.
 *
 * Autoryzację (ProjectPolicy@managePages / @view) sprawdza wywołujący.
 */
class FigmaFrameService
{
	private const RENDER_MODE = '~abs';

	public function __construct(
		private FigmaClient $client,
		private ActivityLogger $activity,
	) {
	}

	public function isConfigured(): bool
	{
		return $this->client->isConfigured();
	}

	/**
	 * Zapisany w cache frame podstrony (zawsze w zakresie jej projektu).
	 */
	public function frameFor(Page $page): ?FigmaFrame
	{
		$target = FigmaUrl::frame($page->figma_frame_url);

		return $target ? $this->frameForTarget($page->project, $target) : null;
	}

	/**
	 * Frame z cache w zakresie projektu (wspólny dla podstron Treści i ekranów Design Review).
	 *
	 * @param array{file_key: string, node_id: string} $target
	 */
	public function frameForTarget(Project $project, array $target): ?FigmaFrame
	{
		return FigmaFrame::query()
			->where('project_id', $project->id)
			->where('file_key', $target['file_key'])
			->where('node_id', $target['node_id'])
			->first();
	}

	/**
	 * @return array{frame: FigmaFrame, from_cache: bool, previews: int, previews_failed: int, previews_remaining: int}
	 */
	public function fetch(Page $page, WP_User $by, bool $force = false, bool $previews = true): array
	{
		$target = FigmaUrl::frame($page->figma_frame_url);

		if (! $target) {
			throw new FigmaException(FigmaException::INVALID_LINK, 'Podstrona nie ma poprawnego linku do frame\'u Figmy (adres z parametrem node-id).');
		}

		return $this->fetchTarget($page->project, $target, $by, $force, $page, $previews);
	}

	/**
	 * Porcja podglądów dla kolejki w tle: pobiera brakujące pliki, dopóki nie minie budżet czasu (sekundy),
	 * żeby pojedyncze żądanie nie przekroczyło limitu czasu serwera. Wymaga zapisanego frame'u.
	 *
	 * @return array{downloaded: int, failed: int, remaining: int}
	 */
	public function previewBatch(Page $page, float $budget): array
	{
		$frame = $this->frameFor($page);

		if (! $frame) {
			throw new FigmaException(FigmaException::NOT_FOUND, 'Najpierw pobierz frame z Figmy.');
		}

		[$downloaded, $failed, $remaining] = $this->syncPreviews($frame, microtime(true) + $budget);

		return ['downloaded' => $downloaded, 'failed' => $failed, 'remaining' => $remaining];
	}

	/**
	 * Pobranie frame'u wskazanego kluczem pliku i ID warstwy (bez podstrony — np. ekran Design Review).
	 * Z podstroną: aktualizuje jej `figma_node_name` i loguje zdarzenie na podstronie, jak dotąd.
	 *
	 * @param array{file_key: string, node_id: string} $target
	 * @return array{frame: FigmaFrame, from_cache: bool, previews: int, previews_failed: int, previews_remaining: int}
	 */
	public function fetchTarget(Project $project, array $target, WP_User $by, bool $force = false, ?Page $page = null, bool $previews = true): array
	{
		$cached = $this->frameForTarget($project, $target);

		if (! $force && $cached && $cached->isFresh() && (! $previews || $this->previewsComplete($cached))) {
			return ['frame' => $cached, 'from_cache' => true, 'previews' => 0, 'previews_failed' => 0, 'previews_remaining' => 0];
		}

		$depth = max(1, (int) config('fillmein.figma.node_depth', 8));
		$data = $this->client->fileNodes($target['file_key'], [$target['node_id']], $depth);
		$document = $data['nodes'][$target['node_id']]['document'] ?? null;

		if (! is_array($document)) {
			throw new FigmaException(FigmaException::NOT_FOUND, 'W pliku Figmy nie ma frame\'u wskazanego w linku (mógł zostać usunięty).');
		}

		if (($document['id'] ?? null) !== $target['node_id']) {
			throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Figma zwróciła inną warstwę niż wskazana w linku.');
		}

		$type = (string) ($document['type'] ?? '');

		if (! in_array($type, (array) config('fillmein.figma.frame_types', []), true)) {
			throw new FigmaException(FigmaException::INVALID_NODE, in_array($type, ['CANVAS', 'DOCUMENT'], true)
				? 'Link wskazuje całą stronę pliku Figmy. Wskaż konkretny frame podstrony.'
				: 'Wskazana warstwa (' . $type . ') nie jest frame\'em. Wskaż frame podstrony.');
		}

		$count = 0;
		$tree = self::prune($document, $depth, $count, max(1, (int) config('fillmein.figma.max_nodes', 5000)));
		$box = $document['absoluteBoundingBox'] ?? [];

		$frame = DB::transaction(function () use ($project, $page, $target, $data, $document, $type, $tree, $count, $box) {
			$frame = FigmaFrame::query()->updateOrCreate(
				['project_id' => $project->id, 'file_key' => $target['file_key'], 'node_id' => $target['node_id']],
				[
					'node_name' => mb_substr((string) ($document['name'] ?? ''), 0, 190) ?: null,
					'node_type' => $type,
					'file_name' => mb_substr((string) ($data['name'] ?? ''), 0, 190) ?: null,
					'file_version' => isset($data['version']) ? mb_substr((string) $data['version'], 0, 64) : null,
					'file_last_modified' => $this->date($data['lastModified'] ?? null),
					'width' => isset($box['width']) ? (int) round((float) $box['width']) : null,
					'height' => isset($box['height']) ? (int) round((float) $box['height']) : null,
					'nodes_count' => $count,
					'document' => $tree,
					'fetched_at' => now(),
				],
			);

			if ($page && $frame->node_name !== null && $page->figma_node_name !== $frame->node_name) {
				$page->forceFill(['figma_node_name' => $frame->node_name])->save();
			}

			return $frame;
		});

		// Pobranie wszystkich PNG potrafi trwać ponad minutę, a hosting przerywa takie żądanie błędem 500.
		// Pobieramy tyle, ile zmieści się w budżecie czasu; resztę dociąga pierwsze wyświetlenie (ensureLocal)
		// albo kolejka w tle (previewBatch). Bez podglądów (kolejka) — od razu tylko wpisy.
		$budget = max(1.0, (float) config('fillmein.figma.preview_budget_seconds', 12));
		[$downloaded, $failed, $remaining] = $this->syncPreviews($frame, microtime(true) + $budget, ! $previews);

		$this->activity->log('figma.frame_fetched', Actor::user($by), $project, $page, array_filter([
			'page_name' => $page?->name,
			'node_id' => $frame->node_id,
			'file_version' => $frame->file_version,
			'nodes_count' => $frame->nodes_count,
			'previews' => $downloaded,
			'previews_failed' => $failed,
		], fn ($value, $key) => $key !== 'page_name' || $value !== null, ARRAY_FILTER_USE_BOTH));

		return ['frame' => $frame->refresh(), 'from_cache' => false, 'previews' => $downloaded, 'previews_failed' => $failed, 'previews_remaining' => $remaining];
	}

	/**
	 * Podgląd warstwy należącej do frame'u tej podstrony. Inne warstwy/projekty → null.
	 */
	public function previewFor(Page $page, string $nodeId): ?FigmaPreview
	{
		$frame = $this->frameFor($page);

		return $frame?->previews()->where('node_id', $nodeId)->first();
	}

	/**
	 * Lokalna kopia podglądu; brakującą pobiera ponownie (odświeżając wygasły adres).
	 */
	public function ensureLocal(FigmaPreview $preview): bool
	{
		if ($preview->path && Storage::disk('fmi')->exists($preview->path)) {
			return true;
		}

		if (! $this->client->isConfigured() && ! $preview->remoteUrlValid()) {
			return false;
		}

		try {
			[$downloaded] = $this->downloadPreviews($preview->frame, collect([$preview]));
		} catch (FigmaException) {
			return false;
		}

		return $downloaded > 0;
	}

	/**
	 * Przycięte drzewo warstw: tylko pola potrzebne do analizy i podglądu (bez pełnych stylów, wypełnień,
	 * efektów i adresów obrazów; tekst ma wybrane `text_style` i wskazówki z textHints()).
	 * Licznik i limit chronią przed bardzo dużymi frame'ami.
	 */
	public static function prune(array $node, int $depth, int &$count, int $max): array
	{
		$count++;

		$pruned = [
			'id' => (string) ($node['id'] ?? ''),
			'name' => mb_substr((string) ($node['name'] ?? ''), 0, 190),
			'type' => (string) ($node['type'] ?? ''),
		];

		if (($node['visible'] ?? true) === false) {
			$pruned['visible'] = false;
		}

		if (isset($node['absoluteBoundingBox']) && is_array($node['absoluteBoundingBox'])) {
			$box = $node['absoluteBoundingBox'];
			$pruned['box'] = array_map(
				fn ($value) => round((float) $value, 2),
				array_intersect_key($box, array_flip(['x', 'y', 'width', 'height'])),
			);
		}

		if (($node['type'] ?? null) === 'TEXT' && isset($node['characters'])) {
			$pruned['characters'] = mb_substr((string) $node['characters'], 0, 20000);
			$pruned += self::textHints($node);
		}

		foreach ((array) ($node['fills'] ?? []) as $fill) {
			if (is_array($fill) && ($fill['type'] ?? null) === 'IMAGE' && ($fill['visible'] ?? true) !== false) {
				$pruned['image_fill'] = true;
				break;
			}
		}

		if (($node['type'] ?? null) === 'INSTANCE' && is_string($node['componentId'] ?? null)) {
			$pruned['component_id'] = mb_substr($node['componentId'], 0, 255);
		}

		if (in_array($node['layoutMode'] ?? null, ['HORIZONTAL', 'VERTICAL'], true)) {
			$pruned['layout'] = $node['layoutMode'];
		}

		$children = array_values(array_filter((array) ($node['children'] ?? []), 'is_array'));

		if ($children === []) {
			return $pruned;
		}

		if ($depth <= 0) {
			$pruned['truncated'] = true;

			return $pruned;
		}

		$pruned['children'] = [];

		foreach ($children as $child) {
			if ($count >= $max) {
				$pruned['truncated'] = true;
				break;
			}

			$pruned['children'][] = self::prune($child, $depth - 1, $count, $max);
		}

		return $pruned;
	}

	/**
	 * Wskazówki do klasyfikacji pola tekstowego (etap 05): wybrane style z białej listy, hiperłącze
	 * (tylko adresy http/https/mailto/tel), formatowanie w tekście i listy. Pełnych stylów nie zapisujemy.
	 */
	private static function textHints(array $node): array
	{
		$hints = [];
		$style = is_array($node['style'] ?? null) ? $node['style'] : [];
		$keys = ['fontFamily', 'fontWeight', 'fontSize', 'italic', 'textCase', 'textDecoration', 'textAlignHorizontal', 'lineHeightPx'];
		$textStyle = array_filter(
			array_intersect_key($style, array_flip($keys)),
			fn ($value) => is_scalar($value),
		);

		if ($textStyle !== []) {
			$hints['text_style'] = array_map(fn ($value) => is_string($value) ? mb_substr($value, 0, 64) : $value, $textStyle);
		}

		$link = $style['hyperlink'] ?? null;

		if (is_array($link)) {
			$url = is_string($link['url'] ?? null) ? trim($link['url']) : '';
			$hints['hyperlink'] = preg_match('~^(https?://|mailto:|tel:)~i', $url) === 1 && mb_strlen($url) <= 500
				? $url
				: (string) ($link['type'] ?? 'NODE');
		}

		$overrides = array_filter((array) ($node['characterStyleOverrides'] ?? []), fn ($id) => (int) $id !== 0);

		if ($overrides !== []) {
			$hints['mixed_styles'] = true;

			foreach ((array) ($node['styleOverrideTable'] ?? []) as $override) {
				if (is_array($override) && isset($override['hyperlink'])) {
					$hints['inline_links'] = true;
					break;
				}
			}
		}

		$lines = array_filter((array) ($node['lineTypes'] ?? []), fn ($type) => in_array($type, ['ORDERED', 'UNORDERED'], true));

		if ($lines !== []) {
			$hints['list'] = true;
		}

		return $hints;
	}

	/**
	 * Zgranie podglądów z aktualną strukturą frame'u: frame + widoczne bezpośrednie dzieci.
	 *
	 * @return array{0: int, 1: int} [pobrane, nieudane]
	 */
	/**
	 * @return array{0: int, 1: int, 2: int} [pobrane, nieudane, pozostałe po przekroczeniu terminu]
	 */
	private function syncPreviews(FigmaFrame $frame, ?float $deadline = null, bool $recordsOnly = false): array
	{
		$nodes = [['id' => $frame->node_id, 'name' => $frame->node_name]];

		foreach ($frame->children() as $child) {
			if (($child['visible'] ?? true) !== false && FigmaUrl::isNodeId($child['id'] ?? null)) {
				$nodes[] = ['id' => $child['id'], 'name' => $child['name'] ?? null];
			}
		}

		$nodes = array_slice($nodes, 0, max(1, (int) config('fillmein.figma.max_previews', 40)));
		$wanted = array_column($nodes, 'id');

		// Warstwy, których już nie ma we frame'ie: usuwamy wpis i plik cache (to nie jest treść klienta).
		$frame->previews()->whereNotIn('node_id', $wanted)->get()->each(function (FigmaPreview $stale) {
			$this->deleteFile($stale->path);
			$stale->delete();
		});

		$pending = collect();

		foreach ($nodes as $position => $node) {
			$preview = FigmaPreview::query()->firstOrNew(['frame_id' => $frame->id, 'node_id' => $node['id']]);
			$preview->fill([
				'project_id' => $frame->project_id,
				'file_key' => $frame->file_key,
				'node_name' => $node['name'] !== null ? mb_substr((string) $node['name'], 0, 190) : null,
				'position' => $position,
			]);

			$current = $preview->exists
				&& $preview->file_version === self::previewVersion($frame)
				&& $preview->path
				&& Storage::disk('fmi')->exists($preview->path);

			$preview->save();

			if (! $current) {
				$pending->push($preview);
			}
		}

		if ($pending->isEmpty()) {
			return [0, 0, 0];
		}

		if ($recordsOnly) {
			return [0, 0, $pending->count()];
		}

		return $this->downloadPreviews($frame, $pending, $deadline);
	}

	/**
	 * Pobranie plików. Wygasłe lub brakujące adresy odświeżamy jednym zapytaniem /v1/images;
	 * adres z cache, który przestał działać, odświeżamy raz i ponawiamy pobranie.
	 *
	 * @param \Illuminate\Support\Collection<int, FigmaPreview> $previews
	 * @return array{0: int, 1: int, 2: int}
	 */
	private function downloadPreviews(FigmaFrame $frame, $previews, ?float $deadline = null): array
	{
		$version = self::previewVersion($frame);
		$stale = $previews->filter(fn (FigmaPreview $p) => ! $p->remoteUrlValid() || $p->file_version !== $version);

		if ($stale->isNotEmpty()) {
			try {
				$this->refreshUrls($frame, $stale);
			} catch (FigmaException) {
				// Metadane frame'u są już zapisane; brak podglądów raportujemy jako nieudane.
				return [0, $previews->count(), 0];
			}
		}

		$downloaded = 0;
		$failed = 0;
		$done = 0;

		foreach ($previews as $preview) {
			if ($deadline !== null && $done > 0 && microtime(true) >= $deadline) {
				return [$downloaded, $failed, $previews->count() - $done];
			}

			$done++;
			$fresh = $stale->contains(fn (FigmaPreview $p) => $p->is($preview));

			try {
				$this->store($frame, $preview);
				$downloaded++;

				continue;
			} catch (FigmaException $e) {
				if ($fresh || ! $this->client->isConfigured()) {
					$failed++;

					continue;
				}
			}

			try {
				$this->refreshUrls($frame, collect([$preview]));
				$this->store($frame, $preview);
				$downloaded++;
			} catch (FigmaException) {
				$failed++;
			}
		}

		return [$downloaded, $failed, 0];
	}

	/**
	 * @param \Illuminate\Support\Collection<int, FigmaPreview> $previews
	 */
	private function refreshUrls(FigmaFrame $frame, $previews): void
	{
		$expires = now()->addHours(max(1, (int) config('fillmein.figma.image_url_ttl_hours', 24 * 14)));

		foreach ($previews->chunk(50) as $chunk) {
			$urls = $this->client->images(
				$frame->file_key,
				$chunk->pluck('node_id')->all(),
				(string) config('fillmein.figma.preview_format', 'png'),
				(float) config('fillmein.figma.preview_scale', 1),
			);

			foreach ($chunk as $preview) {
				$url = $urls[$preview->node_id] ?? null;

				try {
					$url !== null && $this->client->assertImageUrl($url);
				} catch (FigmaException) {
					$url = null; // adres spoza dozwolonych hostów nie jest zapisywany ani pobierany
				}

				$preview->forceFill([
					'remote_url' => $url,
					'remote_expires_at' => $url !== null ? $expires : null,
					'format' => (string) config('fillmein.figma.preview_format', 'png'),
				])->save();
			}
		}
	}

	private function store(FigmaFrame $frame, FigmaPreview $preview): void
	{
		if (! $preview->remote_url) {
			throw new FigmaException(FigmaException::NOT_FOUND, 'Figma nie wyrenderowała podglądu tej warstwy.');
		}

		$body = $this->client->download($preview->remote_url);
		$info = @getimagesizefromstring($body);
		$extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

		if (! is_array($info) || ! isset($extensions[$info['mime'] ?? ''])) {
			throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Podgląd z Figmy nie jest obrazem PNG/JPG.');
		}

		$path = sprintf(
			'figma/%s/%s/%s-%s.%s',
			$frame->project->uuid,
			$frame->file_key,
			FigmaUrl::encodeNodeId($preview->node_id),
			substr(hash('sha256', self::previewVersion($frame)), 0, 12),
			$extensions[$info['mime']],
		);

		Storage::disk('fmi')->put($path, $body);

		if ($preview->path && $preview->path !== $path) {
			$this->deleteFile($preview->path);
		}

		$preview->forceFill([
			'path' => $path,
			'mime_type' => $info['mime'],
			'width' => (int) $info[0],
			'height' => (int) $info[1],
			'bytes' => strlen($body),
			'file_version' => self::previewVersion($frame),
			'fetched_at' => now(),
		])->save();
	}

	/**
	 * Wersja podglądu = wersja pliku + sposób renderowania. Zmiana sposobu (przycięcie do granic frame'u, 2026-11-04)
	 * unieważnia wcześniejsze podglądy, więc przy kolejnym pobraniu ramki pobierają się ponownie.
	 */
	public static function previewVersion(FigmaFrame $frame): string
	{
		return mb_substr((string) $frame->file_version, 0, 58) . self::RENDER_MODE;
	}

	private function previewsComplete(FigmaFrame $frame): bool
	{
		$previews = $frame->previews()->get();

		return $previews->isNotEmpty() && $previews->every(
			fn (FigmaPreview $p) => $p->file_version === self::previewVersion($frame) && $p->path && Storage::disk('fmi')->exists($p->path),
		);
	}

	private function deleteFile(?string $path): void
	{
		if ($path && str_starts_with($path, 'figma/') && Storage::disk('fmi')->exists($path)) {
			Storage::disk('fmi')->delete($path);
		}
	}

	private function date(mixed $value): ?Carbon
	{
		if (! is_string($value) || $value === '') {
			return null;
		}

		try {
			return Carbon::parse($value)->utc();
		} catch (\Throwable) {
			return null;
		}
	}
}
