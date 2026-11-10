<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dodatkowe uwagi / treści pod sekcją („+ Dodaj uwagi” w edytorze, decyzja właściciela z 2026-11-05).
 * Jedna notatka na sekcję, z wersją do wykrywania konfliktów jak `fmi_field_values`. Nie trafia do ACF.
 */
return new class extends Migration
{
	public function up(): void
	{
		if (Schema::hasTable('fmi_section_notes')) {
			return;
		}

		Schema::create('fmi_section_notes', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('section_id')->constrained('fmi_sections')->cascadeOnDelete();
			$table->text('body')->nullable();
			$table->unsignedInteger('version')->default(0);
			$table->string('updated_by_type', 16)->nullable();
			$table->unsignedBigInteger('updated_by_user_id')->nullable();
			$table->foreignId('updated_by_access_id')->nullable()->constrained('fmi_client_accesses')->nullOnDelete();
			$table->timestamps();

			$table->unique('section_id', 'fmi_section_notes_section_unique');
			$table->index('project_id');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_section_notes');
	}
};
