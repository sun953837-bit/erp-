<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('app:health', function () {
    $this->info('php-api is healthy');
})->describe('Check php-api app health');
