<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projekty klientów. Użytkownicy (autor, opiekun) to ID z tabeli wp_users — bez klucza obcego,
 * bo tabele WordPressa nie są zarządzane migracjami; spójność zapewnia hook `deleted_user`.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_projects', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->string('name', 190);
			$table->string('client_name', 190)->nullable();
			$table->string('status', 32)->default('draft')->index();
			$table->unsignedBigInteger('author_id')->nullable()->index();
			$table->unsignedBigInteger('manager_id')->nullable()->index();
			$table->string('figma_file_key', 64)->nullable()->index();
			$table->string('figma_file_url', 500)->nullable();
			$table->string('figma_file_name', 190)->nullable();
			$table->timestamp('figma_synced_at')->nullable();
			$table->json('settings')->nullable();
			$table->timestamps();
			$table->softDeletes();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_projects');
	}
};
