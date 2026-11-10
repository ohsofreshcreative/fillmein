<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historia zmian treści (podstawowe wersjonowanie). Kolejne autozapisy tej samej osoby
 * w krótkim oknie czasu łączą się w jedną rewizję (patrz ContentService).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_field_revisions', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('field_value_id')->constrained('fmi_field_values')->cascadeOnDelete();
			$table->unsignedInteger('version');
			$table->longText('value')->nullable();
			$table->string('actor_type', 16);
			$table->unsignedBigInteger('actor_user_id')->nullable();
			$table->foreignId('actor_access_id')->nullable()->constrained('fmi_client_accesses')->nullOnDelete();
			$table->timestamps();

			$table->index(['field_value_id', 'version']);
			$table->index(['project_id', 'created_at']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_field_revisions');
	}
};
