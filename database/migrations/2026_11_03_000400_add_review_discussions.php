<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Review, etap 08 — dyskusje i statusy uwag (zmiany wyłącznie addytywne).
 *
 * - Statusy wątku: dotychczasowe `open` (etykieta „Nowy”), nowe `in_progress`, `resolved` — kolumna bez zmian.
 * - `lock_version` (wątek, wiadomość): optymistyczna kontrola jednoczesnych zmian statusu i edycji.
 * - `request_id` wiadomości: identyfikator żądania od przeglądarki; ponowienie po timeoucie zwraca istniejący wpis.
 * - `fmi_review_thread_events`: historia zmian statusu i edycji (poprzednia treść), z autorem i czasem.
 *
 * Wycofanie usuwa historię i nowe kolumny — przed nim zrób kopię bazy (dane rozmów zostają w wątkach i wiadomościach).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('fmi_review_threads', function (Blueprint $table) {
			$table->unsignedInteger('lock_version')->default(0)->after('status');
			$table->timestamp('status_changed_at')->nullable()->after('lock_version');
		});

		Schema::table('fmi_review_comments', function (Blueprint $table) {
			$table->uuid('request_id')->nullable()->after('uuid');
			$table->unsignedInteger('lock_version')->default(0)->after('author_access_id');
			$table->timestamp('edited_at')->nullable()->after('lock_version');
			$table->unique(['project_id', 'request_id'], 'fmi_review_comments_request_unique');
		});

		Schema::create('fmi_review_thread_events', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('thread_id')->constrained('fmi_review_threads')->cascadeOnDelete();
			$table->foreignId('comment_id')->nullable()->constrained('fmi_review_comments')->nullOnDelete();
			$table->string('type', 32);
			$table->string('from_status', 16)->nullable();
			$table->string('to_status', 16)->nullable();
			$table->text('previous_body')->nullable();
			$table->string('actor_type', 16);
			$table->unsignedBigInteger('actor_user_id')->nullable();
			$table->foreignId('actor_access_id')->nullable()->constrained('fmi_client_accesses')->nullOnDelete();
			$table->timestamp('created_at')->nullable();

			$table->index(['thread_id', 'id'], 'fmi_review_thread_events_thread_index');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_review_thread_events');

		Schema::table('fmi_review_comments', function (Blueprint $table) {
			$table->dropUnique('fmi_review_comments_request_unique');
			$table->dropColumn(['request_id', 'lock_version', 'edited_at']);
		});

		Schema::table('fmi_review_threads', function (Blueprint $table) {
			$table->dropColumn(['lock_version', 'status_changed_at']);
		});
	}
};
