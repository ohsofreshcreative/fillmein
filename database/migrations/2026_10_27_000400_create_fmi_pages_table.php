<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Podstrony projektu = wybrane frame'y z Figmy.
 * `source`: `figma` (frame) albo `manual` (podstrona dodana ręcznie — import jej nie dotyka).
 * `archived_at` oznacza element usunięty z Figmy przy aktualizacji — zostaje razem z treścią.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_pages', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->string('name', 190);
			$table->string('slug', 190);
			$table->string('source', 16)->default('figma');
			$table->string('figma_node_id', 64)->nullable();
			$table->string('figma_node_name', 190)->nullable();
			$table->unsignedInteger('position')->default(0);
			$table->timestamp('archived_at')->nullable();
			$table->timestamps();

			$table->unique(['project_id', 'figma_node_id']);
			$table->index(['project_id', 'position']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_pages');
	}
};
