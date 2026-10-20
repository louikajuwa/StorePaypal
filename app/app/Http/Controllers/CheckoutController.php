<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    public function show(Product $product)
    {
        return Inertia::render('Checkout/Show', [
            'product'        => $product,
            'paypalClientId' => config('services.paypal.client_id'),
        ]);
    }

    public function store(Request $request, Product $product)
    {
        $request->validate([
            'customer_name'  => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
        ]);

        $accessToken = $this->getPayPalAccessToken();
        $baseUrl     = config('services.paypal.base_url');

        $response = Http::withToken($accessToken)
            ->withHeaders(['Prefer' => 'return=representation'])
            ->post("{$baseUrl}/v2/checkout/orders", [
                'intent'         => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => 'USD',
                        'value'         => number_format($product->price, 2, '.', ''),
                    ],
                    'description' => $product->name,
                ]],
            ]);

        if (!$response->successful()) {
            Log::error('PayPal create order failed', ['response' => $response->body()]);
            return response()->json(['message' => 'Failed to create PayPal order.'], 500);
        }

        $orderId = $response->json('id');

        $payment = Payment::create([
            'product_id'      => $product->id,
            'paypal_order_id' => $orderId,
            'amount'          => $product->price,
            'currency'        => 'usd',
            'customer_email'  => $request->customer_email,
            'customer_name'   => $request->customer_name,
            'status'          => 'pending',
        ]);

        $payment->load('product');
        $this->broadcast($payment);

        return response()->json(['orderId' => $orderId]);
    }

    public function capture(Request $request, Product $product)
    {
        $request->validate([
            'orderId' => 'required|string',
        ]);

        $payment = Payment::where('paypal_order_id', $request->orderId)->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $accessToken = $this->getPayPalAccessToken();
        $baseUrl     = config('services.paypal.base_url');

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Prefer'       => 'return=representation',
            ])
            ->withBody('{}', 'application/json')
            ->post("{$baseUrl}/v2/checkout/orders/{$request->orderId}/capture");

        if (!$response->successful()) {
            Log::error('PayPal capture failed', ['response' => $response->body()]);
            $payment->update(['status' => 'failed']);
            $payment->load('product');
            $this->broadcast($payment);

            $issue   = $response->json('details.0.issue');
            $message = match ($issue) {
                'INSTRUMENT_DECLINED'   => 'Your payment method was declined. Please try a different card or use your PayPal balance.',
                'PAYER_ACTION_REQUIRED' => 'Your bank requires additional verification. Please complete it in the PayPal window.',
                'COMPLIANCE_VIOLATION'  => 'This transaction could not be processed due to a compliance issue.',
                'DUPLICATE_TRANSACTION' => 'This appears to be a duplicate transaction. Please check if you were already charged.',
                'TRANSACTION_REFUSED'   => 'The transaction was refused by your bank. Please try a different payment method.',
                'CARD_EXPIRED'          => 'Your card has expired. Please use a different card.',
                'INVALID_SECURITY_CODE' => 'The security code you entered is incorrect. Please check your card and try again.',
                default                 => 'Payment failed. Please try again or use a different payment method.',
            };

            return response()->json(['message' => $message, 'issue' => $issue], 500);
        }

        $captureStatus = $response->json('status');

        if ($captureStatus === 'COMPLETED') {
            $payment->update(['status' => 'paid']);
            $payment->load('product');
            $this->broadcast($payment);

            return response()->json(['success' => true]);
        }

        $payment->update(['status' => 'failed']);
        $payment->load('product');
        $this->broadcast($payment);

        return response()->json(['message' => 'Payment was not completed.'], 400);
    }

    public function success(Product $product)
    {
        return Inertia::render('Checkout/Success', [
            'product' => $product,
        ]);
    }

    private function broadcast(Payment $payment): void
    {
        try {
            Http::post(config('services.realtime.url') . '/broadcast-payment', [
                'secret'  => config('services.realtime.secret'),
                'payment' => [
                    'id'              => $payment->id,
                    'product_id'      => $payment->product_id,
                    'product_name'    => $payment->product->name ?? null,
                    'paypal_order_id' => $payment->paypal_order_id,
                    'amount'          => $payment->amount,
                    'currency'        => $payment->currency,
                    'customer_email'  => $payment->customer_email,
                    'customer_name'   => $payment->customer_name,
                    'status'          => $payment->status,
                    'created_at'      => $payment->created_at->toISOString(),
                    'updated_at'      => $payment->updated_at->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to broadcast payment update: ' . $e->getMessage());
        }
    }

    private function getPayPalAccessToken(): string
    {
        return Cache::remember('paypal_access_token', 3600, function () {
            $baseUrl = config('services.paypal.base_url');

            $response = Http::asForm()
                ->withBasicAuth(
                    config('services.paypal.client_id'),
                    config('services.paypal.secret')
                )
                ->post("{$baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if (!$response->successful()) {
                Log::error('PayPal auth failed', ['response' => $response->body()]);
                throw new \RuntimeException('Failed to authenticate with PayPal.');
            }

            return $response->json('access_token');
        });
    }
}
