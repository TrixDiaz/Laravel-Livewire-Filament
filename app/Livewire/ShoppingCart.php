<?php

namespace App\Livewire;

use App\Models\Coupon;
use Carbon\Carbon;
use Livewire\Component;
use Ixudra\Curl\Facades\Curl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderInvoice;

class ShoppingCart extends Component
{
    public $cartItems = [];
    public $total = 0;
    public $subtotal = 0;
    public $tax = 0;
    public $deliveryFee = 0;
    public $relatedProducts = [];
    public $paymentMethod = 'cod';
    public $shippingOption = 'normal';
    public $couponCode;
    public $discount = 0;
    public $addresses = [];
    public $selectedAddressId = null;
    public $newAddress = [
        'user_id' => '',
        'address_line_1' => '',
        'address_line_2' => '',
        'city' => '',
        'state' => '',
        'postal_code' => '',
        'country' => '',
    ];

    public function mount()
    {
        $this->getUpdatedCart();
        $this->fetchRelatedProducts();
        $this->loadUserAddresses();
    }

    public function loadUserAddresses()
    {
        if (auth()->check()) {
            $this->addresses = auth()->user()->addresses()->get();
            if ($this->addresses->isNotEmpty()) {
                $this->selectedAddressId = $this->addresses->first()->id;
            }
        }
    }

    public function applyCoupon($couponCode)
    {
        $coupon = Coupon::where('code', $couponCode)->first();

        if (!$coupon) {
            $this->addError('coupon', 'Invalid coupon code.');
            return;
        }

        $now = Carbon::now();
        if ($now->lt($coupon->start_date) || $now->gt($coupon->end_date)) {
            $this->addError('coupon', 'This coupon is not valid at this time.');
            return;
        }

        if ($coupon->usage_limit <= $coupon->used_count) {
            $this->addError('coupon', 'This coupon has reached its usage limit.');
            return;
        }

        // Apply the discount
        if ($coupon->type === 'fixed') {
            $this->discount = $coupon->value;
        } else { // percentage
            $this->discount = $this->subtotal * ($coupon->value / 100);
        }

        // Update the total
        $this->calculateTotal();

        // Increment the used count
        $coupon->increment('used_count');

        session()->flash('coupon_message', 'Coupon applied successfully!');
        session()->flash('coupon_success', true);
    }

    public function getUpdatedCart()
    {
        $this->cartItems = session('cart', []);
        $this->calculateTotal();
        $this->total = $this->subtotal + $this->tax + $this->deliveryFee - $this->discount;
    }

    public function render()
    {
        return view('livewire.shopping-cart');
    }

    public function calculateTotal()
    {
        $this->subtotal = array_reduce($this->cartItems, function ($carry, $item) {
            return $carry + ($item['price'] * $item['quantity']);
        }, 0);

        $this->tax = $this->subtotal * 0.12; // Assuming 12% tax
        $this->deliveryFee = $this->shippingOption === 'rush' ? 100 : 0;
        $this->total = $this->subtotal + $this->tax + $this->deliveryFee - $this->discount;
    }

    public function fetchRelatedProducts()
    {
        if (empty($this->cartItems)) {
            $this->relatedProducts = [];
            return;
        }

        $categoryIds = array_unique(array_column($this->cartItems, 'category_id'));
        $brandId = array_unique(array_column($this->cartItems, 'brand_id'));
        // Fetch related products from the database
        $this->relatedProducts = \App\Models\Product::whereIn('category_id', $categoryIds)
            ->orWhere('brand_id', $brandId)
            ->whereNotIn('id', array_keys($this->cartItems))
            ->inRandomOrder()
            ->limit(9)
            ->get()
            ->toArray();
    }

    public function updateQuantity($productId, $quantity)
    {
        if (isset($this->cartItems[$productId])) {
            $this->cartItems[$productId]['quantity'] = max(1, $quantity);
            session(['cart' => $this->cartItems]);
            $this->calculateTotal();
        }
    }

    public function removeItem($productId)
    {
        unset($this->cartItems[$productId]);
        session(['cart' => $this->cartItems]);
        $this->calculateTotal();
        $this->dispatch('swal:success', [
            'title' => 'Success!',
            'text' => 'Item removed from cart successfully!',
            'icon' => 'success',
            'timer' => 3000,
        ]);
    }

    public function proceedToCheckout()
    {
        if (!$this->selectedAddressId) {
            $this->dispatch('swal:error', [
                'title' => 'Error!',
                'text' => 'Please select or add a shipping address.',
                'icon' => 'error',
            ]);
            return;
        }

        if ($this->paymentMethod === 'cod') {
            // Handle Cash on Delivery checkout
            $this->handleCashOnDeliveryCheckout();
        } else {
            // Handle GCash checkout
            $this->handleGCashCheckout();
        }
    }

    private function handleCashOnDeliveryCheckout()
    {
        // Implement Cash on Delivery logic here
        // For example, save the order to the database and redirect to a confirmation page
        $this->dispatch('swal:success', [
            'title' => 'Order Placed!',
            'text' => 'Your Cash on Delivery order has been placed successfully.',
            'icon' => 'success',
        ]);
        // Clear the cart or perform any other necessary actions
        $this->cartItems = [];
        $this->total = 0;
        // Redirect to a confirmation page
        return redirect()->route('home');
    }

    private function handleGCashCheckout()
    {
        $total = $this->total;

        $data = [
            'data' => [
                'attributes' => [
                    'line_items' => [
                        [
                            'currency' => 'PHP',
                            'amount' => (int)($total * 100),
                            'description' => 'Payment for your order',
                            'name' => 'Order Payment',
                            'quantity' => 1,
                        ],
                    ],
                    'payment_method_types' => ['gcash'],
                    'success_url' => route('payment.success'),
                    'cancel_url' => route('payment.failed'),
                    'description' => 'Payment for your order',
                ],
            ],
        ];

        $response = Curl::to('https://api.paymongo.com/v1/checkout_sessions')
            ->withHeader('Content-Type: application/json')
            ->withHeader('accept: application/json')
            ->withHeader('Authorization: Basic c2tfdGVzdF9ZS1lMMnhaZWVRRDZjZ1dYWkJYZ1dHVU46' . base64_encode(config('services.paymongo.secret_key')))
            ->withData($data)
            ->asJson()
            ->post();

        if (isset($response->data->attributes->checkout_url)) {
            Session::put('session_id', $response->data->id);
            Session::put('checkout_url', $response->data->attributes->checkout_url);
            return redirect()->to($response->data->attributes->checkout_url);
        } else {
            // Handle error
            $this->dispatch('swal:error', [
                'title' => 'Error!',
                'text' => 'Unable to process payment. Please try again later.',
                'icon' => 'error',
            ]);
            return redirect()->route('payment.failed');
        }
    }

    public function updatePaymentMethod($method)
    {
        $this->paymentMethod = $method;
    }

    public function updateShippingOption($option)
    {
        $this->shippingOption = $option;
        $this->calculateTotal();
    }

    private function formatLineItems()
    {
        return array_map(function ($item) {
            return [
                'currency' => 'PHP',
                'amount' => (int)($item['price'] * 100),
                'name' => $item['name'],
                'quantity' => $item['quantity'],
            ];
        }, $this->cartItems);
    }

    public function handlePaymentSuccess()
    {
        // Handle successful payment
        $this->dispatch('swal:success', [
            'title' => 'Success!',
            'text' => 'Your payment was successful.',
            'icon' => 'success',
        ]);

        // Send invoice email
        $this->sendInvoiceEmail();

        // Clear the cart or perform any other necessary actions
        $this->cartItems = [];
        $this->total = 0;

        return redirect()->back();
    }

    private function sendInvoiceEmail()
    {
        $user = auth()->user();
        $selectedAddress = collect($this->addresses)->firstWhere('id', $this->selectedAddressId);

        if (!$selectedAddress) {
            Log::error('No shipping address found for order', [
                'user_id' => $user->id,
                'selected_address_id' => $this->selectedAddressId,
            ]);
            return;
        }

        $orderDetails = [
            'items' => $this->cartItems,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'deliveryFee' => $this->deliveryFee,
            'discount' => $this->discount,
            'total' => $this->total,
            'shippingAddress' => $selectedAddress,
            'paymentMethod' => $this->paymentMethod,
            'shippingOption' => $this->shippingOption,
        ];

        Mail::to($user->email)->send(new OrderInvoice($user, $orderDetails));
    }

    public function handlePaymentFailed()
    {
        // Handle failed payment
        $this->dispatch('swal:error', [
            'title' => 'Payment Failed',
            'text' => 'Your payment was not successful. Please try again.',
            'icon' => 'error',
        ]);
    }

    public function selectAddress($addressId)
    {
        $this->selectedAddressId = $addressId;
    }

    public function addNewAddress()
    {
        $this->validate([
            'newAddress.address_line_1' => 'required',
            'newAddress.city' => 'required',
            'newAddress.state' => 'required',
            'newAddress.postal_code' => 'required',
            'newAddress.country' => 'required',
        ]);

        $this->newAddress['user_id'] = auth()->id();

        $address = auth()->user()->addresses()->create($this->newAddress);
        $this->addresses->push($address);
        $this->selectedAddressId = $address->id;
        $this->newAddress = [
            'user_id' => '',
            'address_line_1' => '',
            'address_line_2' => '',
            'city' => '',
            'state' => '',
            'postal_code' => '',
            'country' => '',
        ];

        $this->dispatch('swal:success', [
            'title' => 'Success!',
            'text' => 'New address added successfully!',
            'icon' => 'success',
        ]);
    }
}
