<?php

namespace App\Services\Import;

use App\Services\ExportValidator;
use ZipArchive;

/**
 * Zweryfikowany eksport: `export.zip` albo `export.json` z paczką obok siebie. Konstrukcja kończy się sukcesem
 * tylko dla dokumentu zgodnego ze schematem i plików zgodnych z sumami, więc odmowa zapada przed zapisem.
 */
class ExportPackage
{
	public const SUPPORTED_MAJOR = 1;

	private const ZIP_NAME = 'export.zip';

	private ?ZipArchive $zip = null;

	public array $document = [];

	public function __construct(private string $path, private ExportValidator $validator)
	{
		$this->open();
		$this->validate();
		$this->verifyAssets();
	}

	public function bytes(array $asset): string
	{
		$bytes = $this->zip?->getFromName($asset['path']);

		if (! is_string($bytes)) {
			throw new ImportRefused(sprintf('Brak pliku %s w paczce.', $asset['path']));
		}

		return $bytes;
	}

	private function open(): void
	{
		$path = $this->path;
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		if (is_link($path) || ! is_file($path) || ! in_array($extension, ['json', 'zip'], true)) {
			throw new ImportRefused('Podaj istniejący plik .json albo .zip.');
		}

		$archive = $extension === 'zip' ? $path : dirname($path) . '/' . self::ZIP_NAME;

		if (is_file($archive)) {
			$zip = new ZipArchive;

			if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
				throw new ImportRefused('Nie można otworzyć paczki ZIP.');
			}

			$this->zip = $zip;
		}

		$json = $extension === 'zip' ? $this->zip?->getFromName('export.json') : file_get_contents($path);
		$document = is_string($json) ? json_decode($json, true) : null;

		if (! is_array($document)) {
			throw new ImportRefused('Plik nie zawiera poprawnego JSON.');
		}

		$this->document = $document;
	}

	private function validate(): void
	{
		$version = $this->document['schema_version'] ?? null;

		if (! is_string($version) || (int) explode('.', $version)[0] !== self::SUPPORTED_MAJOR) {
			throw new ImportRefused(sprintf('Nieobsługiwana schema_version: %s.', is_string($version) ? $version : 'brak'));
		}

		$errors = $this->validator->validate($this->document);

		if ($errors !== []) {
			throw new ImportRefused("Eksport niezgodny ze schematem:\n- " . implode("\n- ", array_slice($errors, 0, 10)));
		}
	}

	private function verifyAssets(): void
	{
		foreach ($this->document['assets'] as $asset) {
			$bytes = $this->bytes($asset);

			if (hash('sha256', $bytes) !== $asset['sha256']) {
				throw new ImportRefused(sprintf('Suma pliku %s jest niezgodna.', $asset['path']));
			}

			if ((getimagesizefromstring($bytes)['mime'] ?? null) !== $asset['mime']) {
				throw new ImportRefused(sprintf('Typ pliku %s jest niezgodny.', $asset['path']));
			}
		}
	}
}
