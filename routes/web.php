<?php

use App\Http\Controllers\DbBrowserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['status' => 'ok', 'message' => 'Chat API ready']);
});

Route::view('/login', 'api_login')->name('api.login');
Route::view('/test', 'api_tester')->name('api.tester');
Route::view('/chat', 'chat_tester')->name('chat.tester');
Route::redirect('/api', '/test');
Route::redirect('/db', '/test#db-browser');

Route::get('/db/tables', [DbBrowserController::class, 'tables'])->name('db.tables');
Route::get('/db/tables/{table}', [DbBrowserController::class, 'showTable'])->name('db.table');