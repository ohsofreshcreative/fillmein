<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linki dostępowe klientów. Przechowujemy wyłącznie hash SHA-256 tokenu — jawny token
 * jest pokazywany tylko raz, w momencie wygenerowania.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_client_accesses', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->char('token_hash', 64)->unique();
			$table->string('label', 190)->nullable();
			$table->unsignedBigInteger('created_by')->nullable();
			$table->timestamp('expires_at')->nullable();
			$table->timestamp('revoked_at')->nullable();
			$table->unsignedBigInteger('revoked_by')->nullable();
			$table->timestamp('last_used_at')->nullable();
			$table->timestamps();

			$table->index(['project_id', 'revoked_at']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_client_accesses');
	}
};
