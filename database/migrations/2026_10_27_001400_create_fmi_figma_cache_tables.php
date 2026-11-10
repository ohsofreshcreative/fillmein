<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache integracji z Figmą (etap 04). Migracja addytywna — tworzy wyłącznie nowe tabele.
 *
 * - `fmi_figma_frames` — metadane JEDNEGO frame'u (nie całego pliku) z wersją pliku i czasem pobrania.
 *   Klucz: projekt + plik + warstwa, bo wiele podstron może wskazywać ten sam frame.
 *   `document` to przycięte drzewo warstw frame'u (bez tokenów i adresów obrazów).
 * - `fmi_figma_previews` — podglądy frame'u i jego bezpośrednich dzieci: referencja do tymczasowego
 *   adresu z Figmy (`remote_url` + `remote_expires_at`) oraz kopia na prywatnym dysku `fmi` (`path`).
 *   Adres zdalny nigdy nie trafia do przeglądarki ani dziennika — pliki serwuje trasa z kontrolą dostępu.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_figma_frames', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->string('file_key', 64);
			$table->string('node_id', 64);
			$table->string('node_name', 190)->nullable();
			$table->string('node_type', 32)->nullable();
			$table->string('file_name', 190)->nullable();
			$table->string('file_version', 64)->nullable();
			$table->timestamp('file_last_modified')->nullable();
			$table->unsignedInteger('width')->nullable();
			$table->unsignedInteger('height')->nullable();
			$table->unsignedInteger('nodes_count')->default(0);
			$table->json('document')->nullable();
			$table->timestamp('fetched_at')->nullable();
			$table->timestamps();

			$table->unique(['project_id', 'file_key', 'node_id'], 'fmi_figma_frames_node_unique');
		});

		Schema::create('fmi_figma_previews', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('frame_id')->constrained('fmi_figma_frames')->cascadeOnDelete();
			$table->string('file_key', 64);
			$table->string('node_id', 64);
			$table->string('node_name', 190)->nullable();
			$table->unsignedInteger('position')->default(0);
			$table->string('format', 8)->default('png');
			$table->string('file_version', 64)->nullable();
			$table->text('remote_url')->nullable();
			$table->timestamp('remote_expires_at')->nullable();
			$table->string('path', 500)->nullable();
			$table->string('mime_type', 64)->nullable();
			$table->unsignedInteger('width')->nullable();
			$table->unsignedInteger('height')->nullable();
			$table->unsignedInteger('bytes')->nullable();
			$table->timestamp('fetched_at')->nullable();
			$table->timestamps();

			$table->unique(['frame_id', 'node_id'], 'fmi_figma_previews_node_unique');
			$table->index(['project_id', 'file_key', 'node_id'], 'fmi_figma_previews_lookup');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_figma_previews');
		Schema::dropIfExists('fmi_figma_frames');
	}
};
