<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Review, etap 09 — linki recenzji dla klienta (zmiany addytywne, wspólna tabela linków klientów).
 *
 * - `scope`: `content` (dotychczasowe linki edytora Treści — wartość domyślna dla istniejących wierszy) albo `review`.
 *   Link jednego zakresu nie otwiera tras drugiego.
 * - `can_comment`, `can_approve`: jawne możliwości linku recenzji (odczyt jest zawsze).
 * - `code_hash`: opcjonalny kod dostępu (password_hash) — drugi czynnik obok tokenu.
 * - `fmi_review_comments.author_name`: nazwa podana przez gościa (deklarowana, niepotwierdzona).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('fmi_client_accesses', function (Blueprint $table) {
			$table->string('scope', 16)->default('content')->after('project_id');
			$table->boolean('can_comment')->default(false)->after('label');
			$table->boolean('can_approve')->default(false)->after('can_comment');
			$table->string('code_hash', 255)->nullable()->after('can_approve');
			$table->index(['project_id', 'scope'], 'fmi_client_accesses_scope_index');
		});

		Schema::table('fmi_review_comments', function (Blueprint $table) {
			$table->string('author_name', 80)->nullable()->after('author_access_id');
		});
	}

	public function down(): void
	{
		Schema::table('fmi_review_comments', function (Blueprint $table) {
			$table->dropColumn('author_name');
		});

		Schema::table('fmi_client_accesses', function (Blueprint $table) {
			$table->dropIndex('fmi_client_accesses_scope_index');
			$table->dropColumn(['scope', 'can_comment', 'can_approve', 'code_hash']);
		});
	}
};
