<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TargetController;
use App\Http\Controllers\PatternController;
use App\Http\Controllers\WordController;

// Web: UI pages only. All actions are under /api/*
Route::resource('targets', TargetController::class)->only(['index','show','store']);
Route::post('/targets/bulk-destroy', [TargetController::class, 'bulkDestroy'])->name('targets.bulk-destroy');

// Admin UI pages
Route::get('/patterns', [PatternController::class, 'index'])->name('patterns.index');
Route::get('/words', [WordController::class, 'index'])->name('words.index');
Route::get('/words/create', [WordController::class, 'create'])->name('words.create');
Route::post('/words', [WordController::class, 'store'])->name('words.store');
