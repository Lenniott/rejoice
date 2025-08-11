<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NotesController;
use App\Http\Controllers\AudioController;
use App\Http\Controllers\ChunksController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\VectorizationController;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

// Notes API Routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('notes', NotesController::class);
    
    // Audio upload and processing
    Route::post('notes/{noteId}/audio', [AudioController::class, 'store']);
    
    // Chunk operations
    Route::patch('chunks/{id}', [ChunksController::class, 'update']);
    Route::post('chunks/{id}/ai-process', [ChunksController::class, 'aiProcess']);
    Route::get('chunks/{id}', [ChunksController::class, 'show']);
    
    // Search operations
    Route::post('search/semantic', [SearchController::class, 'semanticSearch']);
    Route::get('notes/{id}/similar', [SearchController::class, 'findSimilarNotes']);
    
    // Vectorization operations
    Route::post('vectorize/run', [VectorizationController::class, 'runVectorization']);
    Route::post('notes/{id}/vectorize', [VectorizationController::class, 'vectorizeNote']);
});
