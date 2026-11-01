<?php

use App\Http\Controllers\DispatchController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaudaController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'home'])->name('home');
Route::get('/inventory', [WorkspaceController::class, 'inventory'])->name('inventory.index');
Route::get('/inventory/products/create', [WorkspaceController::class, 'createProduct'])->name('products.create');
Route::post('/inventory/products', [WorkspaceController::class, 'storeProduct'])->name('products.store');
Route::get('/inventory/products/{id}/edit', [WorkspaceController::class, 'editProduct'])->whereNumber('id')->name('products.edit');
Route::put('/inventory/products/{id}', [WorkspaceController::class, 'updateProduct'])->whereNumber('id')->name('products.update');
Route::delete('/inventory/products/{id}', [WorkspaceController::class, 'destroyProduct'])->whereNumber('id')->name('products.destroy');
Route::resource('saudas', SaudaController::class)->except('show');
Route::get('/dispatch', [DispatchController::class, 'index'])->name('dispatch.index');
Route::get('/dispatch/print', [DispatchController::class, 'print'])->name('dispatch.print');
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reports/{report}', [ReportController::class, 'show'])->where('report', 'po|so|size-purchase|size-sale|inventory|stock-alert')->name('reports.show');
Route::get('/masters/{master}', [MasterDataController::class, 'index'])->where('master', 'group-heads|parties')->name('masters.index');
Route::post('/masters/{master}', [MasterDataController::class, 'store'])->where('master', 'group-heads|parties')->name('masters.store');
Route::put('/masters/{master}/{id}', [MasterDataController::class, 'update'])->where('master', 'group-heads|parties')->whereNumber('id')->name('masters.update');
Route::delete('/masters/{master}/{id}', [MasterDataController::class, 'destroy'])->where('master', 'group-heads|parties')->whereNumber('id')->name('masters.destroy');
Route::get('/{module}/create', [OrderController::class, 'create'])->where('module', 'purchase-orders|sale-orders')->name('orders.create');
Route::post('/{module}/orders', [OrderController::class, 'store'])->where('module', 'purchase-orders|sale-orders')->name('orders.store');
Route::get('/{module}/{id}/receipt', [OrderController::class, 'receipt'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.receipt');
Route::get('/{module}/{id}/parchi', [OrderController::class, 'parchi'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.parchi');
Route::get('/{module}/{id}', [OrderController::class, 'show'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.show');
Route::get('/{module}/{id}/edit', [OrderController::class, 'edit'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.edit');
Route::put('/{module}/{id}', [OrderController::class, 'update'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.update');
Route::patch('/{module}/{id}/move', [OrderController::class, 'move'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.move');
Route::get('/{module}', [WorkspaceController::class, 'index'])->where('module', 'purchase|sale|purchase-orders|sale-orders')->name('module');
Route::post('/{module}', [WorkspaceController::class, 'storeRecord'])->where('module', 'purchase|sale')->name('records.store');
