<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dodatkowi pracownicy przypisani do projektu (autor i główny opiekun są w fmi_projects).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_project_members', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->unsignedBigInteger('user_id')->index();
			$table->unsignedBigInteger('added_by')->nullable();
			$table->timestamps();

			$table->unique(['project_id', 'user_id']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_project_members');
	}
};
