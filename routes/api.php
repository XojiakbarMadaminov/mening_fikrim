<?php

use App\Http\Controllers\CorpusReportingController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/corpus')->middleware(['auth:corpus-reports', 'can:corpus.read', 'throttle:60,1'])->group(function () {
    Route::get('campaigns', [CorpusReportingController::class, 'campaigns']);
    Route::get('campaigns/{id}/report', [CorpusReportingController::class, 'campaign']);
    Route::get('campaigns/{id}', [CorpusReportingController::class, 'campaign']);
    Route::get('statistics', [CorpusReportingController::class, 'statistics']);
    Route::get('quality', [CorpusReportingController::class, 'quality']);
    Route::get('discovery', [CorpusReportingController::class, 'discovery']);
    Route::get('tools', [CorpusReportingController::class, 'catalog']);
    Route::get('tools/{tool}', [CorpusReportingController::class, 'tool']);
    Route::get('assistant', [CorpusReportingController::class, 'assistant']);
});
