<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Field;
use App\Support\Actor;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Pliki zdjęć: przyjęcie, miniatury i serwowanie z prywatnego dysku `fmi`.
 *
 * Typ pliku wynika z zawartości (finfo i getimagesize), nigdy z nazwy ani nagłówka klienta. SVG jest poza listą:
 * projekt nie ma sanitizera, a plik SVG może zawierać skrypt. Nazwa na dysku to UUID, a oryginalna służy tylko do opisu.
 * Pliki użytkownika nie są kasowane: odpięcie od pola zostawia je w magazynie.
 */
class AssetService
{
	private const DISK = 'fmi';

	/** Typy obrazów z getimagesize() odpowiadające typom MIME z konfiguracji. */
	private const IMAGE_TYPES = [
		'image/jpeg' => IMAGETYPE_JPEG,
		'image/png' => IMAGETYPE_PNG,
		'image/webp' => IMAGETYPE_WEBP,
		'image/gif' => IMAGETYPE_GIF,
	];

	public function store(UploadedFile $file, Field $field, Actor $actor): Asset
	{
		[$mime, $extension, $width, $height] = $this->inspect($file, $field);

		$checksum = hash_file('sha256', $file->getRealPath());
		$existing = Asset::query()->where('project_id', $field->project_id)->where('checksum', $checksum)->first();

		if ($existing) {
			return $existing;
		}

		$this->protectStorage();

		$uuid = (string) Str::uuid();
		$directory = sprintf('projects/%s/%s', $field->project->uuid, $uuid);
		$path = Storage::disk(self::DISK)->putFileAs($directory, $file, 'original.' . $extension);

		$asset = new Asset([
			'project_id' => $field->project_id,
			'field_id' => $field->id,
			'disk' => self::DISK,
			'path' => $path,
			'original_name' => $this->safeName($file->getClientOriginalName(), $extension),
			'mime_type' => $mime,
			'extension' => $extension,
			'size' => $file->getSize(),
			'width' => $width,
			'height' => $height,
			'checksum' => $checksum,
			'variants' => $this->variants($path, $directory, $extension, $mime),
			'uploaded_by_type' => $actor->type->value,
			'uploaded_by_user_id' => $actor->userId,
			'uploaded_by_access_id' => $actor->clientAccessId,
		]);

		$asset->uuid = $uuid;
		$asset->save();

		return $asset;
	}

	/**
	 * Odpowiedź z plikiem. Nagłówki typu pochodzą z bazy, nie z rozpoznania pliku przy serwowaniu.
	 * Wariant bez miniatury (np. nieudane generowanie) serwuje oryginał.
	 */
	public function respond(Asset $asset, string $variant): BinaryFileResponse
	{
		abort_unless(in_array($variant, ['original', 'thumb', 'preview'], true), 404);

		$disk = Storage::disk($asset->disk);
		$path = $asset->variants[$variant] ?? $asset->path;

		abort_unless($disk->exists($path), 404);

		$response = new BinaryFileResponse($disk->path($path), 200, [
			'Content-Type' => $asset->mime_type,
			'X-Content-Type-Options' => 'nosniff',
		]);

		$response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $this->headerName($asset));

		return $response;
	}

	/**
	 * @return array{0: string, 1: string, 2: int, 3: int}
	 */
	private function inspect(UploadedFile $file, Field $field): array
	{
		if (! $file->isValid()) {
			throw $this->rejected('Nie udało się przesłać pliku.');
		}

		$limit = (int) config('fillmein.assets.max_size_kb') * 1024;

		if ($file->getSize() > $limit) {
			throw $this->rejected(sprintf('Plik może mieć najwyżej %d MB.', $limit / 1024 / 1024));
		}

		$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
		$extension = config('fillmein.assets.mime_types')[$mime] ?? null;

		if ($extension === null) {
			throw $this->rejected('Dozwolone są pliki JPG, PNG, WebP i GIF.');
		}

		$image = @getimagesize($file->getRealPath());

		if ($image === false || ($image[2] ?? null) !== (self::IMAGE_TYPES[$mime] ?? null)) {
			throw $this->rejected('Ten plik nie jest poprawnym obrazem.');
		}

		[$width, $height] = $image;

		$this->assertDimensions($field, (int) $width, (int) $height);

		return [$mime, $extension, (int) $width, (int) $height];
	}

	private function assertDimensions(Field $field, int $width, int $height): void
	{
		$maximum = (int) config('fillmein.assets.max_dimension');

		if ($width < 1 || $height < 1 || $width > $maximum || $height > $maximum || $width * $height > (int) config('fillmein.assets.max_pixels')) {
			throw $this->rejected('Obraz ma zbyt duże wymiary.');
		}

		$minWidth = (int) ($field->settings['min_width'] ?? 0);
		$minHeight = (int) ($field->settings['min_height'] ?? 0);

		if ($width < $minWidth || $height < $minHeight) {
			throw $this->rejected(sprintf('Obraz musi mieć co najmniej %d × %d px.', $minWidth, $minHeight));
		}
	}

	/**
	 * Obraz mniejszy od wariantu nie jest powiększany, a nieudana miniatura nie blokuje przesłania:
	 * w obu przypadkach wariantu nie ma i serwowany jest oryginał.
	 *
	 * @return array<string, string>
	 */
	private function variants(string $path, string $directory, string $extension, string $mime): array
	{
		$disk = Storage::disk(self::DISK);
		$variants = [];

		foreach (config('fillmein.assets.variants') as $name => [$width, $height, $crop]) {
			$editor = wp_get_image_editor($disk->path($path));

			if (is_wp_error($editor) || is_wp_error($editor->resize($width, $height, $crop))) {
				continue;
			}

			$target = sprintf('%s/%s.%s', $directory, $name, $extension);
			$saved = $editor->save($disk->path($target), $mime);

			if (! is_wp_error($saved)) {
				chmod($disk->path($target), 0600);
				$variants[$name] = $target;
			}
		}

		return $variants;
	}

	/**
	 * Magazyn leży poza katalogiem publicznym (pilnuje tego `fmi:check`). Pliki blokujące są drugą
	 * warstwą na wypadek przeniesienia pod Apache; nginx ignoruje `.htaccess`.
	 */
	public function protectStorage(): void
	{
		$disk = Storage::disk(self::DISK);

		if (! $disk->exists('.htaccess')) {
			$disk->put('.htaccess', "Require all denied\nOptions -Indexes -ExecCGI\n");
		}

		if (! $disk->exists('index.php')) {
			$disk->put('index.php', "<?php\n// Celowo puste: magazyn nie jest listowany.\n");
		}
	}

	private function safeName(string $name, string $extension): string
	{
		$base = Str::limit(sanitize_file_name(pathinfo($name, PATHINFO_FILENAME)), 180, '');

		return ($base !== '' ? $base : 'zdjecie') . '.' . $extension;
	}

	private function headerName(Asset $asset): string
	{
		return preg_replace('/[^A-Za-z0-9._-]/', '-', Str::ascii($asset->original_name)) ?: 'zdjecie.' . $asset->extension;
	}

	private function rejected(string $message): ValidationException
	{
		return ValidationException::withMessages(['file' => $message]);
	}
}
