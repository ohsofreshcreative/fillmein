<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Review, etap 11 — powiadomienia w panelu (uwagi, odpowiedzi, decyzje). Tabela addytywna.
 *
 * - Deduplikacja: unikalne (`user_id`, `event_key`) — ponowna obsługa tego samego zdarzenia nie tworzy kopii.
 * - Bez zapisanych adresów: link do kontekstu wylicza serwer z `type` + `subject_id`, po ponownym sprawdzeniu dostępu.
 * - `data`: tylko dane do wyświetlenia (numer uwagi / rewizji, decyzja, aktor, krótki fragment) — bez tokenów.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_notifications', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->unsignedBigInteger('user_id');
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->string('type', 32);
			$table->string('event_key', 100);
			$table->unsignedBigInteger('subject_id');
			$table->json('data')->nullable();
			$table->timestamp('read_at')->nullable();
			$table->timestamp('created_at')->nullable();

			$table->unique(['user_id', 'event_key'], 'fmi_notifications_event_unique');
			$table->index(['user_id', 'read_at'], 'fmi_notifications_unread_index');
		});
	}

	public function down(): void
	{
		// Powiadomienia to dane pochodne (historia zostaje w fmi_activity_log) — można je usunąć przy wycofaniu.
		Schema::dropIfExists('fmi_notifications');
	}
};
