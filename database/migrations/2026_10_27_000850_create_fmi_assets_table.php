<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pliki (zdjęcia) przesłane do projektu.
 *
 * - pliki leżą na prywatnym dysku `fmi` (poza katalogiem publicznym, patrz config/fillmein.php),
 *   pod losową ścieżką `projects/{project_uuid}/{asset_uuid}/…` — nigdy pod przewidywalnym URL,
 * - dostęp wyłącznie przez trasy aplikacji z kontrolą uprawnień (pracownik → polityka projektu,
 *   klient → token + przynależność pliku do projektu linku),
 * - `uuid` — publiczny identyfikator w adresach i w wartościach pól,
 * - `variants` — podglądy generowane po przesłaniu (np. thumb, preview) jako ścieżki na dysku,
 * - `alt`, `caption`, `metadata` — metadane edytowane przez klienta / zespół,
 * - `checksum` — SHA-256 zawartości (deduplikacja przy eksporcie do WordPressa),
 * - `deleted_at` — usunięcie / podmiana jest miękka; fizyczne pliki usuwa późniejsze sprzątanie
 *   nieużywanych zasobów.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_assets', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('field_id')->nullable()->constrained('fmi_fields')->nullOnDelete();
			$table->string('disk', 32)->default('fmi');
			$table->string('path', 500);
			$table->string('original_name', 255);
			$table->string('mime_type', 100);
			$table->string('extension', 16);
			$table->unsignedBigInteger('size');
			$table->unsignedInteger('width')->nullable();
			$table->unsignedInteger('height')->nullable();
			$table->char('checksum', 64);
			$table->string('alt', 500)->nullable();
			$table->text('caption')->nullable();
			$table->json('variants')->nullable();
			$table->json('metadata')->nullable();
			$table->string('uploaded_by_type', 16);
			$table->unsignedBigInteger('uploaded_by_user_id')->nullable();
			$table->foreignId('uploaded_by_access_id')->nullable()->constrained('fmi_client_accesses')->nullOnDelete();
			$table->timestamps();
			$table->softDeletes();

			$table->index(['project_id', 'checksum']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_assets');
	}
};
