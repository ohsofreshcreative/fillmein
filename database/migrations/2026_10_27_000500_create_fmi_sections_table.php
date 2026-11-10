<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sekcje podstrony wraz z podglądem pobranym z Figmy.
 *
 * - `source`: `figma` (wykryta w strukturze frame'a) albo `manual` (utworzona ręcznie;
 *   ponowny import jej nie usuwa ani nie nadpisuje),
 * - `is_hidden`: sekcja ukryta przed klientem (np. sekcja techniczna), treść zostaje.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_sections', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('page_id')->constrained('fmi_pages')->cascadeOnDelete();
			$table->string('name', 190);
			$table->string('source', 16)->default('figma');
			$table->string('figma_node_id', 64)->nullable();
			$table->string('figma_node_name', 190)->nullable();
			$table->unsignedInteger('position')->default(0);
			$table->string('preview_path', 500)->nullable();
			$table->unsignedInteger('preview_width')->nullable();
			$table->unsignedInteger('preview_height')->nullable();
			$table->timestamp('preview_updated_at')->nullable();
			$table->boolean('is_hidden')->default(false);
			$table->timestamp('archived_at')->nullable();
			$table->timestamps();

			$table->unique(['page_id', 'figma_node_id']);
			$table->index(['page_id', 'position']);
			$table->index(['project_id', 'source']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_sections');
	}
};
