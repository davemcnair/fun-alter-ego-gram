<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TargetController;
use App\Http\Controllers\PatternController;
use App\Http\Controllers\WordController;

Route::resource('targets', TargetController::class)->only(['index','store','show','destroy']);
Route::post('/source-names/bulk-destroy', [TargetController::class, 'bulkDestroy'])->name('target.bulk-destroy');

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

// Search control endpoints (pause/resume/progress/run-step/start)
//Route::post('/source-names/{source_name}/pause', [TargetNameController::class, 'pause'])->name('source-names.pause');
//Route::post('/source-names/{source_name}/resume', [TargetNameController::class, 'resume'])->name('source-names.resume');
Route::get('/source-names/{source_name}/progress', [TargetNameController::class, 'progress'])->name('source-names.progress');
//Route::post('/source-names/{source_name}/start', [TargetNameController::class, 'start'])->name('source-names.start');
// Star / Unstar phrase for this source
Route::post('/source-names/{source_name}/star', [TargetNameController::class, 'star'])->name('source-names.star');
Route::post('/source-names/{source_name}/unstar', [TargetNameController::class, 'unstar'])->name('source-names.unstar');
// Persist a reordered phrase variant
Route::post('/source-names/{source_name}/rephrase', [TargetNameController::class, 'rephrase'])->name('source-names.rephrase');

// Docs: progress choices (static view)
Route::view('/docs/progress_choices', 'docs.progress_choices')->name('docs.progress_choices');
