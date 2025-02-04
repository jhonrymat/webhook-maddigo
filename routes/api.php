<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\AwsSesController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NumerosController;

use App\Http\Controllers\ContactoController;
use App\Http\Controllers\AplicacionesController;

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

// whatsapp webhook
Route::get('/whatsapp-webhook', [MessageController::class, 'verifyWebhook']);
Route::post('/whatsapp-webhook', [MessageController::class, 'processWebhook']);

// aws ses webhook
Route::post('/aws-ses-webhook', [AwsSesController::class, 'handleNotification']);

