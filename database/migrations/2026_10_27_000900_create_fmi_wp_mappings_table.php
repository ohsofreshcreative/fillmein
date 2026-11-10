<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapowanie elementów projektu na cel w WordPressie. Wspólne dla eksportu JSON i synchronizacji.
 *
 * - page    → wpis (`wp_post_id`),
 * - section → blok ACF (`acf_block`, np. `acf/hero`),
 * - field   → ścieżka pola ACF (`acf_field_path`: `g_hero.header`, repeater: `g_values.r_values`,
 *             pole w repeaterze: `g_values.r_values.*.header`),
 * - asset   → załącznik w docelowym WordPressie (`wp_post_id` = ID attachmentu po imporcie),
 *             żeby ponowna synchronizacja nie duplikowała mediów.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_wp_mappings', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->string('mappable_type', 32);
			$table->unsignedBigInteger('mappable_id');
			$table->unsignedBigInteger('wp_post_id')->nullable();
			$table->string('acf_block', 190)->nullable();
			$table->string('acf_field_path', 190)->nullable();
			$table->json('settings')->nullable();
			$table->timestamps();

			$table->unique(['mappable_type', 'mappable_id']);
			$table->index('project_id');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_wp_mappings');
	}
};
