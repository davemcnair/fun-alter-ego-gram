<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TargetController;
use App\Http\Controllers\PatternController;
use App\Http\Controllers\WordController;

Route::resource('targets', TargetController::class)->only(['index','store','show','destroy']);
Route::post('/targets/bulk-destroy', [TargetController::class, 'bulkDestroy'])->name('targets.bulk-destroy');

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
//Route::post('/targets/{target}/pause', [TargetController::class, 'pause'])->name('targets.pause');
//Route::post('/targets/{target}/resume', [TargetController::class, 'resume'])->name('targets.resume');
// New matches endpoints
Route::get('/targets/{target}/new-matches', [TargetController::class, 'newMatches'])->name('targets.new-matches');
Route::post('/targets/{target}/process-new-matches', [TargetController::class, 'processNewMatches'])->name('targets.process-new-matches');
//Route::post('/targets/{target}/start', [TargetController::class, 'start'])->name('targets.start');
// Star / Unstar phrase for this target
Route::post('/targets/{target}/star', [TargetController::class, 'star'])->name('targets.star');
Route::post('/targets/{target}/unstar', [TargetController::class, 'unstar'])->name('targets.unstar');
// Persist a reordered phrase variant
Route::post('/targets/{target}/rephrase', [TargetController::class, 'rephrase'])->name('targets.rephrase');
