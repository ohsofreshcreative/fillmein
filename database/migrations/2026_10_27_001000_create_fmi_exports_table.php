<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eksporty JSON i synchronizacje z WordPressem (historia i status operacji).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_exports', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->string('type', 32);
			$table->string('status', 32)->default('pending');
			$table->unsignedBigInteger('user_id')->nullable();
			$table->string('target', 500)->nullable();
			$table->string('file_path', 500)->nullable();
			$table->char('checksum', 64)->nullable();
			$table->json('summary')->nullable();
			$table->text('error_message')->nullable();
			$table->timestamp('started_at')->nullable();
			$table->timestamp('finished_at')->nullable();
			$table->timestamps();

			$table->index(['project_id', 'created_at']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_exports');
	}
};
