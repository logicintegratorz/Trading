<?php

use App\Http\Controllers\DispatchController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GateController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaudaController;
use App\Http\Controllers\SaleVoucherController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('session.security')->group(function () {
Route::post('/session/keep-alive', [AuthController::class, 'keepAlive'])->name('session.keep-alive');
Route::get('/', [WorkspaceController::class, 'home'])->name('home');
Route::get('/inventory', [WorkspaceController::class, 'inventory'])->name('inventory.index');
Route::get('/inventory/products/create', [WorkspaceController::class, 'createProduct'])->name('products.create');
Route::post('/inventory/products', [WorkspaceController::class, 'storeProduct'])->name('products.store');
Route::get('/inventory/products/{id}/quick-created', [WorkspaceController::class, 'quickCreatedProduct'])->whereNumber('id')->name('products.quick-created');
Route::get('/inventory/products/{id}/edit', [WorkspaceController::class, 'editProduct'])->whereNumber('id')->name('products.edit');
Route::put('/inventory/products/{id}', [WorkspaceController::class, 'updateProduct'])->whereNumber('id')->name('products.update');
Route::delete('/inventory/products/{id}', [WorkspaceController::class, 'destroyProduct'])->whereNumber('id')->name('products.destroy');
Route::get('/inventory/stock-items', [WorkspaceController::class, 'stockItems'])->name('products.size-rates.index');
Route::get('/inventory/size-rates', [WorkspaceController::class, 'sizeRates'])->name('products.size-rates');
Route::get('/inventory/size-rates/new', [WorkspaceController::class, 'createSizeRate'])->name('products.size-rates.create');
Route::post('/inventory/size-rates', [WorkspaceController::class, 'storeSizeRate'])->name('products.size-rates.store');
Route::put('/inventory/size-rates/{id}', [WorkspaceController::class, 'updateSizeRate'])->whereNumber('id')->name('products.size-rates.update');
Route::get('/sauda-bundle', [SaudaController::class, 'showBundle'])->name('saudas.bundle.show');
Route::delete('/sauda-bundle', [SaudaController::class, 'destroyBundle'])->name('saudas.bundle.destroy');
Route::resource('saudas', SaudaController::class)->except('show');
Route::get('/sales/create', [SaleVoucherController::class, 'create'])->name('sales.create');
Route::resource('sales', SaleVoucherController::class)->except(['create'])->parameters(['sales' => 'id']);
Route::post('/sales/{id}/approve', [SaleVoucherController::class, 'approve'])->whereNumber('id')->name('sales.approve');
Route::get('/dispatch', [DispatchController::class, 'index'])->name('dispatch.index');
Route::get('/dispatch/pending', [DispatchController::class, 'pending'])->name('dispatch.pending');
Route::get('/dispatch/print', [DispatchController::class, 'print'])->name('dispatch.print');
Route::get('/gate', [GateController::class, 'index'])->name('gate.index');
Route::get('/gate/create', [GateController::class, 'create'])->name('gate.create');
Route::post('/gate', [GateController::class, 'store'])->name('gate.store');
Route::get('/gate/{id}/photo', [GateController::class, 'photo'])->whereNumber('id')->name('gate.photo');
Route::get('/gate/{id}/edit', [GateController::class, 'edit'])->whereNumber('id')->name('gate.edit');
Route::put('/gate/{id}', [GateController::class, 'update'])->whereNumber('id')->name('gate.update');
Route::delete('/gate/{id}', [GateController::class, 'destroy'])->whereNumber('id')->name('gate.destroy');
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reports/{report}', [ReportController::class, 'show'])->where('report', 'po|so|size-purchase|size-sale|inventory|stock-alert')->name('reports.show');
Route::get('/masters/{master}', [MasterDataController::class, 'index'])->where('master', 'group-heads|parties')->name('masters.index');
Route::post('/masters/{master}', [MasterDataController::class, 'store'])->where('master', 'group-heads|parties')->name('masters.store');
Route::put('/masters/{master}/{id}', [MasterDataController::class, 'update'])->where('master', 'group-heads|parties')->whereNumber('id')->name('masters.update');
Route::delete('/masters/{master}/{id}', [MasterDataController::class, 'destroy'])->where('master', 'group-heads|parties')->whereNumber('id')->name('masters.destroy');
// Keep the top-bar destination explicit. Orders are sale orders by default;
// using a dedicated endpoint prevents this navigation from ever resolving to
// the separate Sale Voucher register (/sales).
Route::redirect('/orders', '/sale-orders')->name('orders.index');
Route::get('/{module}/create', [OrderController::class, 'create'])->where('module', 'purchase-orders|sale-orders')->name('orders.create');
Route::post('/{module}/orders', [OrderController::class, 'store'])->where('module', 'purchase-orders|sale-orders')->name('orders.store');
Route::get('/{module}/{id}/receipt', [OrderController::class, 'receipt'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.receipt');
Route::get('/{module}/{id}/parchi', [OrderController::class, 'parchi'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.parchi');
Route::get('/{module}/{id}', [OrderController::class, 'show'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.show');
Route::get('/{module}/{id}/edit', [OrderController::class, 'edit'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.edit');
Route::put('/{module}/{id}', [OrderController::class, 'update'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.update');
Route::patch('/{module}/{id}/move', [OrderController::class, 'move'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.move');
Route::post('/{module}/{id}/create-sale', [OrderController::class, 'createSale'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.create-sale');
Route::delete('/{module}/{id}', [OrderController::class, 'destroy'])->where('module', 'purchase-orders|sale-orders')->whereNumber('id')->name('orders.destroy');
Route::get('/{module}/entry/create', [WorkspaceController::class, 'createRecord'])->where('module', 'purchase|sale')->name('stock-entry.create');
Route::get('/{module}/{id}/print', [WorkspaceController::class, 'printRecord'])->where('module', 'purchase|sale')->whereNumber('id')->name('records.print');
Route::get('/{module}/{id}/edit-entry', [WorkspaceController::class, 'editRecord'])->where('module', 'purchase|sale')->whereNumber('id')->name('records.edit');
Route::put('/{module}/{id}/entry', [WorkspaceController::class, 'updateRecord'])->where('module', 'purchase|sale')->whereNumber('id')->name('records.update');
Route::delete('/{module}/{id}/entry', [WorkspaceController::class, 'destroyRecord'])->where('module', 'purchase|sale')->whereNumber('id')->name('records.destroy');
Route::get('/{module}', [WorkspaceController::class, 'index'])->where('module', 'purchase|sale|purchase-orders|sale-orders')->name('module');
Route::post('/{module}', [WorkspaceController::class, 'storeRecord'])->where('module', 'purchase|sale')->name('records.store');
});
