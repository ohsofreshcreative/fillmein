<?php

/**
 * Etap 14 — UUID ostatniego wiersza repeatera lub pliku, które klient utworzył w E2E.
 */

$model = ($args[1] ?? 'row') === 'asset' ? App\Models\Asset::class : App\Models\RepeaterRow::class;

echo $model::query()->where('project_id', (int) $args[0])->latest('id')->value('uuid');
