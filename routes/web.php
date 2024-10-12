<?php

use App\Http\Controllers\BotManController;
use Illuminate\Support\Facades\Route;
use App\Livewire\ShoppingCart;
use App\Http\Controllers\PaymentController;

Route::view('/', 'welcome')->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::view('catalog', 'catalog')
    ->name('catalog');

Route::view('cart', 'cart')
    ->name('cart');

Route::view('payment', 'payment')
    ->middleware(['auth', 'verified'])
    ->name('payment');

Route::view('orders', 'orders')
    ->middleware(['auth', 'verified'])
    ->name('orders');

Route::get('product-profile/{slug}', function ($slug) {
    return view('product-profile', ['slug' => $slug]);
})->name('product-profile');

Route::get('/payment/success', [PaymentController::class, 'handlePaymentSuccess'])->name('payment.success');
Route::get('/payment/failed', [PaymentController::class, 'handlePaymentFailed'])->name('payment.failed');

Route::match(['get', 'post'], '/botman', BotManController::class . '@handle')->name('botman.index');

Route::get('/send-test-email', [App\Http\Controllers\PaymentController::class, 'sendTestEmail'])->name('send.test.email');

require __DIR__ . '/auth.php';
