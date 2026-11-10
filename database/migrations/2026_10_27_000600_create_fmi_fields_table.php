<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Definicje pól (konfiguracja). Treść klienta jest osobno w fmi_field_values,
 * więc zmiana konfiguracji pola nie dotyka wartości.
 *
 * - `uuid` — stabilny identyfikator pola (eksport, mapowanie ACF, API klienta),
 * - `name` — nazwa techniczna (np. `header`), unikalna w obrębie rodzica,
 * - `parent_id` — pole nadrzędne typu group/repeater; `depth` — poziom zagnieżdżenia (0 = pole sekcji),
 *   limit w `fillmein.fields.max_depth`,
 * - `source` — `figma` / `manual`; import zarządza wyłącznie polami `figma`,
 * - `is_required`, `is_hidden`, `instructions` — konfiguracja widoczna dla klienta,
 * - `settings` — opcje zależne od typu (max_length, min/max elementów repeatera i galerii,
 *   dozwolone formaty i minimalne wymiary zdjęć, placeholder itd.),
 * - `figma_node_id` — powiązanie z warstwą tekstową w Figmie.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_fields', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('section_id')->constrained('fmi_sections')->cascadeOnDelete();
			$table->foreignId('parent_id')->nullable()->constrained('fmi_fields')->cascadeOnDelete();
			$table->string('type', 32);
			$table->string('name', 190);
			$table->string('label', 190);
			$table->string('source', 16)->default('figma');
			$table->unsignedTinyInteger('depth')->default(0);
			$table->boolean('is_required')->default(false);
			$table->boolean('is_hidden')->default(false);
			$table->text('instructions')->nullable();
			$table->string('figma_node_id', 64)->nullable();
			$table->text('figma_text')->nullable();
			$table->json('settings')->nullable();
			$table->unsignedInteger('position')->default(0);
			$table->timestamp('archived_at')->nullable();
			$table->timestamps();

			$table->index(['section_id', 'parent_id', 'position']);
			$table->index(['project_id', 'figma_node_id']);
			$table->index(['project_id', 'source']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_fields');
	}
};
