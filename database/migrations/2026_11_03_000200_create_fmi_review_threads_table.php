<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Review, etap 04 — wątki uwag (minimalny model pod liczniki panelu). Migracja addytywna.
 *
 * Wątek należy do projektu i (opcjonalnie) rewizji; status `open` / `resolved`. Autor: konto WP albo link klienta
 * (bez FK do wp_users, jak w całej aplikacji). Treść wiadomości, pinezki i odpowiedzi dochodzą w etapach 07–08
 * kolejnymi migracjami addytywnymi.
 *
 * down() usuwa tylko tę tabelę. Gdy ma dane — najpierw eksport tabeli (docs/design-review/04-panel.md).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_review_threads', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('revision_id')->nullable()->constrained('fmi_review_revisions')->nullOnDelete();
			$table->string('status', 16)->default('open');
			$table->string('created_by_type', 16);
			$table->unsignedBigInteger('created_by_user_id')->nullable();
			$table->foreignId('created_by_access_id')->nullable()->constrained('fmi_client_accesses')->nullOnDelete();
			$table->timestamp('resolved_at')->nullable();
			$table->unsignedBigInteger('resolved_by')->nullable();
			$table->timestamps();

			$table->index(['project_id', 'status'], 'fmi_review_threads_status_index');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_review_threads');
	}
};
