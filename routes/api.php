<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TargetController;
use App\Http\Controllers\PatternController;
use App\Http\Controllers\WordController;
use App\Http\Controllers\SystemController;

// Targets API (JSON only; no Blade views)
Route::get('/targets', [TargetController::class, 'apiIndex'])->name('api.targets.index');
Route::post('/targets', [TargetController::class, 'apiStore'])->name('api.targets.store');
Route::get('/targets/{target}', [TargetController::class, 'apiShow'])->name('api.targets.show');
Route::delete('/targets/{target}', [TargetController::class, 'apiDestroy'])->name('api.targets.destroy');
Route::post('/targets/bulk-destroy', [TargetController::class, 'apiBulkDestroy'])->name('api.targets.bulk-destroy');
Route::get('/targets/{target}/new-matches', [TargetController::class, 'newMatches'])->name('api.targets.new-matches');
Route::post('/targets/{target}/process-new-matches', [TargetController::class, 'processNewMatches'])->name('api.targets.process-new-matches');
Route::post('/targets/{target}/mark-matches-seen', [TargetController::class, 'markMatchesSeen'])->name('api.targets.mark-matches-seen');
Route::get('/targets/{target}/progress', [TargetController::class, 'progress'])->name('api.targets.progress');
Route::post('/targets/{target}/add-word', [TargetController::class, 'addWord'])->name('targets.add-word');
Route::post('/targets/{target}/star', [TargetController::class, 'star'])->name('api.targets.star');
Route::post('/targets/{target}/unstar', [TargetController::class, 'unstar'])->name('api.targets.unstar');
Route::post('/targets/{target}/rephrase', [TargetController::class, 'rephrase'])->name('api.targets.rephrase');

// Target Patterns API
Route::post('/target-patterns/{pattern}/search', [TargetController::class, 'searchTargetPattern'])->name('api.target-patterns.search');

// Patterns API
Route::get('/patterns', [PatternController::class, 'index'])->name('api.patterns.index');
Route::post('/patterns', [PatternController::class, 'store'])->name('api.patterns.store');
Route::get('/patterns/{pattern}/edit', [PatternController::class, 'edit'])->name('api.patterns.edit');
Route::put('/patterns/{pattern}', [PatternController::class, 'update'])->name('api.patterns.update');
Route::delete('/patterns/{pattern}', [PatternController::class, 'destroy'])->name('api.patterns.destroy');
Route::post('/patterns/{pattern}/type', [PatternController::class, 'updateType'])->name('api.patterns.update-type');
Route::post('/patterns/reorder', [PatternController::class, 'reorder'])->name('api.patterns.reorder');
Route::post('/patterns/export', [PatternController::class, 'export'])->name('api.patterns.export');

// Words API
Route::get('/words', [WordController::class, 'index'])->name('api.words.index');
Route::get('/words/create', [WordController::class, 'create'])->name('api.words.create');
Route::post('/words', [WordController::class, 'store'])->name('api.words.store');
Route::get('/words/{word}/edit', [WordController::class, 'edit'])->name('api.words.edit');
Route::put('/words/{word}', [WordController::class, 'update'])->name('api.words.update');
Route::delete('/words/{word}', [WordController::class, 'destroy'])->name('api.words.destroy');
Route::post('/words/{word}/promote', [WordController::class, 'promote'])->name('api.words.promote');
Route::post('/words/commit-resources', [WordController::class, 'commitResources'])->name('api.words.commit-resources');

// System API
Route::get('/system/heartbeat', [SystemController::class, 'heartbeat'])->name('api.system.heartbeat');
