<?php

use App\Http\Controllers\Campaigns\CampaignController;
use Illuminate\Support\Facades\Route;

/* Campaigns. Loaded inside the auth group in routes/web.php. */

Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
Route::get('/campaigns/audience', [CampaignController::class, 'audience'])->name('campaigns.audience');
Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
Route::post('/campaigns/{campaign}/send', [CampaignController::class, 'send'])->name('campaigns.send');
Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
