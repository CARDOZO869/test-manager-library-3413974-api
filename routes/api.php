<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\LibraryController as Library;
use App\Http\Controllers\Api\V1\CatalogManagementController as Catalog;
use App\Http\Controllers\Api\V1\LoanCoverController as Loans;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [Library::class, 'health']);
    Route::post('/auth/login', [Library::class, 'login']);
    Route::post('/users', [Library::class, 'users']);
    Route::get('/categories', [Library::class, 'categories']);

    Route::get('/books', [Library::class, 'books']);
    Route::get('/books/{id}', [Library::class, 'bookDetail']);
    Route::post('/books', [Catalog::class, 'createBook']);
    Route::put('/books/{id}', [Catalog::class, 'updateBook']);
    Route::delete('/books/{id}', [Catalog::class, 'deleteBook']);

    Route::get('/members', [Catalog::class, 'members']);
    Route::get('/members/{id}', [Catalog::class, 'member']);
    Route::post('/members', [Catalog::class, 'createMember']);

    Route::put('/books/{id}/cover', [Loans::class, 'uploadCover']);
    Route::delete('/books/{id}/cover', [Loans::class, 'removeCover']);
    Route::get('/books/{id}/cover', [Loans::class, 'getCover']);

    Route::post('/loans', [Library::class, 'createLoan']);
    Route::get('/loans', [Loans::class, 'loans']);
    Route::get('/loans/overdue', [Library::class, 'overdue']);
    Route::get('/loans/{id}', [Loans::class, 'detail']);
    Route::post('/loans/{loanId}/items/{itemId}/return', [Library::class, 'returnItem']);
    Route::get('/reports/most-borrowed', [Library::class, 'report']);
});
