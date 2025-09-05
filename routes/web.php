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
// Reorder patterns
Route::post('/patterns/reorder', [PatternController::class, 'reorder'])->name('patterns.reorder');
// Export patterns to resources
Route::post('/patterns/export', [PatternController::class, 'export'])->name('patterns.export');

// Word CRUD
Route::resource('words', WordController::class)->except(['show']);
// Promote a word to fun (AJAX)
Route::post('/words/{word}/promote', [WordController::class, 'promote'])->name('words.promote');
// Toggle use_for_search for a word within its anagram set (AJAX)
Route::post('/words/{word}/toggle-search', [WordController::class, 'toggleSearch'])->name('words.toggle-search');

// Pattern preview for create page
Route::get('/patterns/preview', [SourceNameController::class, 'previewPatterns'])->name('patterns.preview');

// Search control endpoints (pause/resume/progress/run-step/start)
Route::post('/source-names/{source_name}/pause', [SourceNameController::class, 'pause'])->name('source-names.pause');
Route::post('/source-names/{source_name}/resume', [SourceNameController::class, 'resume'])->name('source-names.resume');
Route::get('/source-names/{source_name}/progress', [SourceNameController::class, 'progress'])->name('source-names.progress');
Route::post('/source-names/{source_name}/run-step', [SourceNameController::class, 'runStep'])->name('source-names.run-step');
Route::post('/source-names/{source_name}/start', [SourceNameController::class, 'start'])->name('source-names.start');
// Star / Unstar phrase for this source
Route::post('/source-names/{source_name}/star', [SourceNameController::class, 'star'])->name('source-names.star');
Route::post('/source-names/{source_name}/unstar', [SourceNameController::class, 'unstar'])->name('source-names.unstar');
// Persist a reordered phrase variant
Route::post('/source-names/{source_name}/rephrase', [SourceNameController::class, 'rephrase'])->name('source-names.rephrase');
// Enable (re-enable) a deselected pattern
Route::post('/source-names/{source_name}/patterns/{pattern}/enable', [SourceNameController::class, 'enablePattern'])->name('source-names.patterns.enable');

// Docs: progress choices (static view)
Route::view('/docs/progress_choices', 'docs.progress_choices')->name('docs.progress_choices');
