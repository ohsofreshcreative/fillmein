<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dziennik aktywności. Rozróżnia pracowników (user_id) i klientów (client_access_id).
 * Wpisy przeżywają usunięcie projektu (project_id → NULL); nazwa projektu zostaje w `properties`.
 * Nigdy nie zapisujemy tu tokenów ani innych sekretów.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_activity_log', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->nullable()->constrained('fmi_projects')->nullOnDelete();
			$table->string('actor_type', 16);
			$table->unsignedBigInteger('user_id')->nullable()->index();
			$table->foreignId('client_access_id')->nullable()->constrained('fmi_client_accesses')->nullOnDelete();
			$table->string('event', 64)->index();
			$table->string('subject_type', 32)->nullable();
			$table->unsignedBigInteger('subject_id')->nullable();
			$table->json('properties')->nullable();
			$table->timestamp('created_at')->useCurrent();

			$table->index(['project_id', 'created_at']);
			$table->index(['subject_type', 'subject_id']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_activity_log');
	}
};
