<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Review, etap 12 (QA) — poprawka wycofywalności migracji 2026_11_03_000400.
 *
 * MySQL usunął automatyczny indeks klucza obcego `fmi_review_comments.project_id`, gdy powstał unikalny
 * (`project_id`, `request_id`) — od tej pory klucz obcy opiera się na nim i `down()` migracji 000400 kończy się
 * błędem 1553. Zwykły indeks na `project_id` przejmuje tę rolę. Migracje wykonanej nie edytujemy — stąd nowa.
 * `down()` celowo zostawia indeks: bez niego wycofanie 000400 znów byłoby niemożliwe (indeks jest nieszkodliwy,
 * a tabela znika razem z wycofaniem 000300).
 */
return new class extends Migration
{
	public function up(): void
	{
		if (! Schema::hasIndex('fmi_review_comments', 'fmi_review_comments_project_index')) {
			Schema::table('fmi_review_comments', function (Blueprint $table) {
				$table->index('project_id', 'fmi_review_comments_project_index');
			});
		}
	}

	public function down(): void
	{
	}
};
