<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aktualna treść pól.
 *
 * - `row_id` — wiersz repeatera, w którym leży wartość (najbliższy repeater nadrzędny); NULL poza repeaterem,
 * - `row_key` — UUID tego wiersza albo pusty string. Duplikuje `row_id`, bo MySQL dopuszcza
 *   wiele NULL w indeksie unikalnym — unikalność (field_id, row_key) działa na każdym silniku,
 *   a klucz obcy na `row_id` pilnuje integralności,
 * - `value` — tekst lub JSON (lista UUID plików / wierszy), patrz App\Enums\FieldType,
 * - `version` — licznik do optymistycznej blokady: zapis podaje wersję bazową,
 *   a niezgodność oznacza konflikt zamiast cichego nadpisania nowszej zmiany.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_field_values', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('field_id')->constrained('fmi_fields')->cascadeOnDelete();
			$table->foreignId('row_id')->nullable()->constrained('fmi_repeater_rows')->cascadeOnDelete();
			$table->string('row_key', 36)->default('');
			$table->longText('value')->nullable();
			$table->unsignedInteger('version')->default(0);
			$table->string('updated_by_type', 16)->nullable();
			$table->unsignedBigInteger('updated_by_user_id')->nullable();
			$table->foreignId('updated_by_access_id')->nullable()->constrained('fmi_client_accesses')->nullOnDelete();
			$table->timestamps();

			$table->unique(['field_id', 'row_key']);
			$table->index('project_id');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_field_values');
	}
};
