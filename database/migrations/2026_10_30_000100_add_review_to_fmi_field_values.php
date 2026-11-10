<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oznaczenie treści „do poprawy” w przeglądzie zespołu (etap 10). Migracja addytywna: nowe kolumny
 * (nullable) i indeks; istniejące dane bez zmian. To stan przeglądu, nie treść: nie zmienia wersji pola.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('fmi_field_values', function (Blueprint $table) {
			$table->text('review_note')->nullable()->after('updated_by_access_id');
			$table->timestamp('review_requested_at')->nullable()->after('review_note');
			$table->unsignedBigInteger('review_requested_by')->nullable()->after('review_requested_at');

			$table->index(['project_id', 'review_requested_at'], 'fmi_field_values_review_index');
		});
	}

	public function down(): void
	{
		Schema::table('fmi_field_values', function (Blueprint $table) {
			$table->dropIndex('fmi_field_values_review_index');
			$table->dropColumn(['review_note', 'review_requested_at', 'review_requested_by']);
		});
	}
};
