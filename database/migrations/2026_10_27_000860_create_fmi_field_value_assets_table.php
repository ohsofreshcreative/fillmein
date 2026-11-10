<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Powiązanie wartości pola image/gallery z plikami, z kolejnością.
 * Utrzymywane w tej samej transakcji co JSON w fmi_field_values.value (lista UUID).
 * Pozwala sprawdzić, które pliki są w użyciu (kontrola dostępu, sprzątanie, eksport).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_field_value_assets', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('field_value_id')->constrained('fmi_field_values')->cascadeOnDelete();
			$table->foreignId('asset_id')->constrained('fmi_assets')->cascadeOnDelete();
			$table->unsignedInteger('position')->default(0);
			$table->timestamps();

			$table->unique(['field_value_id', 'asset_id']);
			$table->index('asset_id');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_field_value_assets');
	}
};
