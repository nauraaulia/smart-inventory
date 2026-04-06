<?php

use App\Http\Controllers\PurchaseOrderWorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Fallback safety: beberapa browser/tab lama kadang menembak endpoint Livewire update dengan GET.
// Endpoint aslinya POST-only, jadi kita arahkan balik agar user tidak mendapat error page.
Route::get('livewire-{token}/update', function () {
    $fallback = url('/');
    $previous = url()->previous();

    if (is_string($previous) && !str_ends_with($previous, '/update')) {
        return redirect()->to($previous);
    }

    return redirect()->to($fallback);
})->where('token', '[A-Za-z0-9]+');

Route::middleware(['auth'])->group(function () {
    Route::get('/purchase-orders/review', [PurchaseOrderWorkflowController::class, 'review'])->name('po.review');
    Route::post('/purchase-orders/publish', [PurchaseOrderWorkflowController::class, 'publish'])->name('po.publish');
    Route::get('/purchase-orders', [PurchaseOrderWorkflowController::class, 'index'])->name('po.index');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderWorkflowController::class, 'show'])->name('po.show');
    Route::get('/purchase-orders/{purchaseOrder}/print', [PurchaseOrderWorkflowController::class, 'print'])->name('po.print');
    Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderWorkflowController::class, 'receive'])->name('po.receive');

    Route::get('/inbounds/receive-po', [PurchaseOrderWorkflowController::class, 'inboundLookup'])->name('inbound.receive-po');
});
