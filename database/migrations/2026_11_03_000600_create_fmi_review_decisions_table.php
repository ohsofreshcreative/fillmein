<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design Review, etap 10 — decyzje o rewizji (akceptacja / poprawki). Tabela tylko dopisywana.
 *
 * - Jedna decyzja na rewizję: unikalny `revision_id` rozstrzyga równoległe żądania (pierwszy zapis wygrywa),
 *   kolejna decyzja wymaga nowej rewizji — historia nie jest nadpisywana.
 * - Aktor i sposób identyfikacji: konto WP (uwierzytelnione) albo link recenzji (+ kod) z nazwą deklarowaną.
 * - Kontekst rewizji utrwalony w wierszu: link, plik, węzeł, wersja pliku Figmy z chwili decyzji (jeśli API ją podało).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_review_decisions', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('revision_id')->constrained('fmi_review_revisions')->cascadeOnDelete();
			$table->uuid('request_id')->nullable();
			$table->string('decision', 32);
			$table->text('note')->nullable();
			$table->string('actor_type', 16);
			$table->unsignedBigInteger('actor_user_id')->nullable();
			$table->foreignId('actor_access_id')->nullable()->constrained('fmi_client_accesses')->nullOnDelete();
			$table->string('actor_name', 190)->nullable();
			$table->string('identification', 32);
			$table->unsignedInteger('revision_number');
			$table->string('figma_url', 500)->nullable();
			$table->string('figma_file_key', 64)->nullable();
			$table->string('figma_node_id', 255)->nullable();
			$table->string('figma_version', 64)->nullable();
			$table->timestamp('figma_last_modified')->nullable();
			$table->string('version_status', 16);
			$table->timestamp('created_at')->nullable();

			$table->unique('revision_id', 'fmi_review_decisions_revision_unique');
			$table->unique(['project_id', 'request_id'], 'fmi_review_decisions_request_unique');
		});
	}

	public function down(): void
	{
		// Decyzje są dowodem akceptacji — wycofanie nie może ich po cichu usunąć.
		if (Schema::hasTable('fmi_review_decisions') && DB::table('fmi_review_decisions')->exists()) {
			throw new RuntimeException('fmi_review_decisions zawiera decyzje. Najpierw wyeksportuj je (kopia bazy), potem usuń wiersze ręcznie i powtórz wycofanie.');
		}

		Schema::dropIfExists('fmi_review_decisions');
	}
};
