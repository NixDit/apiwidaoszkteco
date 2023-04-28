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

// Checador 1
Route::middleware('api')->get('/updateDataAttendance/{id}',[ZktecoController::class,'updateDataAttendance']);
Route::middleware('api')->post('/saveUser',[ZktecoController::class,'saveUser']);
Route::middleware('api')->post('/deleteUser',[ZktecoController::class,'deleteUser']);
// Checador 2
Route::middleware('api')->get('/updateDataAttendance_2/{id}',[ZktecoController::class,'updateDataAttendance2']);
Route::middleware('api')->post('/saveUser_2',[ZktecoController::class,'saveUser2']);
Route::middleware('api')->post('/deleteUser_2',[ZktecoController::class,'deleteUser2']);