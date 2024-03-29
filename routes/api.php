<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZktecoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('api')->get('/updateDataAttendance/{id}',[ZktecoController::class,'updateDataAttendance']);
Route::middleware('api')->post('/saveUser',[ZktecoController::class,'saveUser']);
Route::middleware('api')->post('/deleteUser',[ZktecoController::class,'deleteUser']);