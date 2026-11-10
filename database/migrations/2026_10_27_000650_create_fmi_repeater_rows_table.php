<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elementy (wiersze) repeaterów — stabilne identyfikatory niezależne od kolejności.
 *
 * - `field_id` — pole typu repeater,
 * - `parent_row_id` — wiersz repeatera nadrzędnego (zagnieżdżone repeatery); NULL na najwyższym poziomie,
 * - `position` — kolejność; kanoniczna kolejność jest też w wartości repeatera (fmi_field_values),
 *   wersjonowanej jak każda inna treść,
 * - `deleted_at` — usunięcie elementu przez klienta jest miękkie (możliwe przywrócenie z historii).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_repeater_rows', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->uuid('uuid')->unique();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('field_id')->constrained('fmi_fields')->cascadeOnDelete();
			$table->foreignId('parent_row_id')->nullable()->constrained('fmi_repeater_rows')->cascadeOnDelete();
			$table->unsignedInteger('position')->default(0);
			$table->string('created_by_type', 16)->nullable();
			$table->unsignedBigInteger('created_by_user_id')->nullable();
			$table->unsignedBigInteger('created_by_access_id')->nullable();
			$table->timestamps();
			$table->softDeletes();

			$table->index(['field_id', 'parent_row_id', 'position']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_repeater_rows');
	}
};
