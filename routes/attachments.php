<?php

use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('attachments')->group(function () {
    Route::get('/{id}/download', [AttachmentController::class, 'show']);
    Route::post('/', [AttachmentController::class, 'store'])
        ->middleware(['jwt.auth', 'not_banned', 'role:maintainer|admin']);
});
