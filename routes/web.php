<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SourceNameController;
use App\Http\Controllers\PatternController;
use App\Http\Controllers\WordController;

Route::get('/', [SourceNameController::class, 'index'])->name('source-names.index');
Route::resource('source-names', SourceNameController::class)->only(['index','store','show','destroy']);
Route::post('/source-names/bulk-destroy', [SourceNameController::class, 'bulkDestroy'])->name('source-names.bulk-destroy');

// Pattern CRUD
Route::resource('patterns', PatternController::class)->except(['show']);
// Inline update for pattern type
Route::post('/patterns/{pattern}/type', [PatternController::class, 'updateType'])->name('patterns.update-type');

// Word CRUD
Route::resource('words', WordController::class)->except(['show']);
// Promote a word to fun (AJAX)
Route::post('/words/{word}/promote', [WordController::class, 'promote'])->name('words.promote');

// Pattern preview for create page
Route::get('/patterns/preview', [SourceNameController::class, 'previewPatterns'])->name('patterns.preview');

// Search control endpoints (pause/resume/progress/run-step/start)
Route::post('/source-names/{source_name}/pause', [SourceNameController::class, 'pause'])->name('source-names.pause');
Route::post('/source-names/{source_name}/resume', [SourceNameController::class, 'resume'])->name('source-names.resume');
Route::get('/source-names/{source_name}/progress', [SourceNameController::class, 'progress'])->name('source-names.progress');
Route::post('/source-names/{source_name}/run-step', [SourceNameController::class, 'runStep'])->name('source-names.run-step');
Route::post('/source-names/{source_name}/start', [SourceNameController::class, 'start'])->name('source-names.start');
// Enable (re-enable) a deselected pattern
Route::post('/source-names/{source_name}/patterns/{pattern}/enable', [SourceNameController::class, 'enablePattern'])->name('source-names.patterns.enable');
