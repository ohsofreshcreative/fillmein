<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Review, etap 03 — wspólny model projektów. Migracja addytywna.
 *
 * - `fmi_projects.content_enabled` / `review_enabled` — moduły projektu. Istniejące projekty dostają Treści (1)
 *   i bez Design Review (0), więc ich działanie się nie zmienia. Ten sam `id`/`uuid` projektu obsługuje oba moduły.
 * - `fmi_review_revisions` — rewizje projektu do recenzji: wskazanie pliku/frame'u Figmy i wersji pliku, jeśli
 *   Figma ją podała. Klienci, użytkownicy i członkostwo są reużyte (project_id, ID kont WP bez FK — jak w całej aplikacji).
 *   `number` jest kolejnym numerem w projekcie (unikalny), `uuid` — stabilnym identyfikatorem publicznym.
 *
 * down() usuwa tylko to, co dodaje up(). Gdy tabela rewizji ma już dane, przed rollbackiem trzeba je wyeksportować
 * (procedura: docs/design-review/03-model.md).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('fmi_projects', function (Blueprint $table) {
			$table->boolean('content_enabled')->default(true)->after('status');
			$table->boolean('review_enabled')->default(false)->after('content_enabled');
		});

		Schema::create('fmi_review_revisions', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->unsignedInteger('number');
			$table->string('title', 190);
			$table->string('status', 32)->default('draft');
			$table->string('figma_file_key', 64)->nullable();
			$table->string('figma_node_id', 255)->nullable();
			$table->string('figma_url', 500)->nullable();
			$table->string('figma_version', 64)->nullable();
			$table->text('notes')->nullable();
			$table->unsignedBigInteger('created_by')->nullable();
			$table->timestamp('published_at')->nullable();
			$table->timestamps();

			$table->unique(['project_id', 'number'], 'fmi_review_revisions_number_unique');
			$table->index(['project_id', 'status'], 'fmi_review_revisions_status_index');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_review_revisions');

		Schema::table('fmi_projects', function (Blueprint $table) {
			$table->dropColumn(['content_enabled', 'review_enabled']);
		});
	}
};
