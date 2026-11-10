<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Review, etap 07 — ekrany rewizji, pinezki i komentarze. Migracja addytywna.
 *
 * - `fmi_review_screens`: frame Figmy wskazany dla rewizji (klucz pliku, ID warstwy, wymiary z absoluteBoundingBox,
 *   wersja pliku). `surface_confirmed` = obraz frame'u ma dokładnie jego wymiary (clipsContent), więc współrzędne 0–1
 *   względem obrazu są współrzędnymi względem granic frame'u. Obraz: cache `fmi_figma_frames` / `fmi_figma_previews`.
 * - `fmi_review_threads` (+ kolumny): numer w projekcie, ekran albo opis kontekstu, pinezka x/y (0–1) z układem odniesienia,
 *   wersja pliku przy wskazaniu. Pinezka tylko przy ekranie z potwierdzoną powierzchnią (walidacja w serwisie).
 * - `fmi_review_comments`: treść (niezaufana), autor; pierwsza wiadomość wątku (odpowiedzi — etap 08).
 *
 * down() usuwa tylko to, co dodaje up(). Gdy tabele mają dane — najpierw eksport (docs/design-review/07-komentarze.md).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_review_screens', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('revision_id')->constrained('fmi_review_revisions')->cascadeOnDelete();
			$table->foreignId('figma_frame_id')->nullable()->constrained('fmi_figma_frames')->nullOnDelete();
			$table->string('file_key', 64);
			$table->string('node_id', 255);
			$table->string('name', 190);
			$table->unsignedInteger('width')->nullable();
			$table->unsignedInteger('height')->nullable();
			$table->boolean('surface_confirmed')->default(false);
			$table->string('figma_version', 64)->nullable();
			$table->unsignedInteger('position')->default(0);
			$table->unsignedBigInteger('created_by')->nullable();
			$table->timestamps();

			$table->unique(['revision_id', 'node_id'], 'fmi_review_screens_node_unique');
		});

		Schema::table('fmi_review_threads', function (Blueprint $table) {
			$table->unsignedInteger('number')->nullable()->after('revision_id');
			$table->foreignId('screen_id')->nullable()->after('number')->constrained('fmi_review_screens')->nullOnDelete();
			$table->string('context', 190)->nullable()->after('screen_id');
			$table->decimal('pin_x', 8, 6)->nullable()->after('context');
			$table->decimal('pin_y', 8, 6)->nullable()->after('pin_x');
			$table->string('pin_reference', 32)->nullable()->after('pin_y');
			$table->string('figma_version', 64)->nullable()->after('pin_reference');

			$table->unique(['project_id', 'number'], 'fmi_review_threads_number_unique');
		});

		Schema::create('fmi_review_comments', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('thread_id')->constrained('fmi_review_threads')->cascadeOnDelete();
			$table->text('body');
			$table->string('author_type', 16);
			$table->unsignedBigInteger('author_user_id')->nullable();
			$table->foreignId('author_access_id')->nullable()->constrained('fmi_client_accesses')->nullOnDelete();
			$table->timestamps();

			$table->index(['thread_id', 'created_at'], 'fmi_review_comments_thread_index');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_review_comments');

		Schema::table('fmi_review_threads', function (Blueprint $table) {
			$table->dropUnique('fmi_review_threads_number_unique');
			$table->dropForeign(['screen_id']);
			$table->dropColumn(['number', 'screen_id', 'context', 'pin_x', 'pin_y', 'pin_reference', 'figma_version']);
		});

		Schema::dropIfExists('fmi_review_screens');
	}
};
