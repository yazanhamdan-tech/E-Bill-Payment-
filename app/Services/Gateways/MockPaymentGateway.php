<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\Log;

class MockPaymentGateway implements PaymentGatewayInterface
{
    /**
     * Process a payment through the mock gateway
     */
    public function processPayment(Payment $payment, array $options = []): array
    {
        try {
            // Simulate payment processing delay
            usleep(100000); // 0.1 seconds

            // Mock payment processing - always succeeds for now
            // In a real implementation, this would call the actual gateway API
            $transactionId = 'MOCK-' . time() . '-' . $payment->id;

            Log::info('Mock payment gateway processing payment', [
                'payment_id' => $payment->id,
                'payment_reference' => $payment->payment_reference,
                'amount' => $payment->amount,
                'transaction_id' => $transactionId,
            ]);

            return [
                'success' => true,
                'transaction_id' => $transactionId,
                'message' => 'Payment processed successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Mock payment gateway error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'transaction_id' => null,
                'message' => 'Payment processing failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Process a refund through the mock gateway
     */
    public function processRefund(Refund $refund, array $options = []): array
    {
        try {
            // Simulate refund processing delay
            usleep(100000); // 0.1 seconds

            // Mock refund processing - always succeeds for now
            $refundId = 'REFUND-' . time() . '-' . $refund->id;

            Log::info('Mock payment gateway processing refund', [
                'refund_id' => $refund->id,
                'refund_reference' => $refund->refund_reference,
                'amount' => $refund->amount,
                'refund_id' => $refundId,
            ]);

            return [
                'success' => true,
                'refund_id' => $refundId,
                'message' => 'Refund processed successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Mock payment gateway refund error', [
                'refund_id' => $refund->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'refund_id' => null,
                'message' => 'Refund processing failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get the gateway name
     */
    public function getName(): string
    {
        return 'mock';
    }

    /**
     * Check if gateway supports a payment method type
     */
    public function supportsPaymentMethod(string $paymentMethodType): bool
    {
        // Mock gateway supports all payment method types
        return in_array($paymentMethodType, [
            'credit_card',
            'debit_card',
            'bank_account',
            'digital_wallet',
            'manual',
        ]);
    }

    /**
     * Verify a transaction status
     */
    public function verifyTransaction(string $transactionId): array
    {
        // Mock implementation - always returns success
        return [
            'status' => 'completed',
            'amount' => null,
            'currency' => 'USD',
        ];
    }

    /**
     * Get the redirect URL for payment confirmation
     * For mock gateway, returns a URL to a mock payment confirmation page
     */
    public function getRedirectUrl(Payment $payment, array $options = []): array
    {
        try {
            $baseUrl = $options['base_url'] ?? config('app.url', 'http://localhost:8000');
            $returnUrl = $options['return_url'] ?? $baseUrl . '/api/payments/' . $payment->id . '/callback?status=success';
            $cancelUrl = $options['cancel_url'] ?? $baseUrl . '/api/payments/' . $payment->id . '/callback?status=cancelled';

            // Generate a mock redirect URL that simulates a payment gateway
            // In production, this would be the actual gateway's checkout URL
            // Extract frontend URL from return_url for callback redirect
            $frontendUrl = parse_url($returnUrl, PHP_URL_SCHEME) . '://' . parse_url($returnUrl, PHP_URL_HOST);
            $port = parse_url($returnUrl, PHP_URL_PORT);
            if ($port) {
                $frontendUrl .= ':' . $port;
            }
            
            $redirectUrl = $baseUrl . '/api/payments/' . $payment->id . '/gateway/confirm?' . http_build_query([
                'payment_id' => $payment->id,
                'payment_reference' => $payment->payment_reference,
                'amount' => $payment->amount,
                'return_url' => $baseUrl . '/api/payments/' . $payment->id . '/callback?status=success&frontend_url=' . urlencode($frontendUrl),
                'cancel_url' => $baseUrl . '/api/payments/' . $payment->id . '/callback?status=cancelled&frontend_url=' . urlencode($frontendUrl),
            ]);

            Log::info('Mock payment gateway redirect URL generated', [
                'payment_id' => $payment->id,
                'payment_reference' => $payment->payment_reference,
                'redirect_url' => $redirectUrl,
            ]);

            return [
                'success' => true,
                'redirect_url' => $redirectUrl,
                'message' => 'Redirect URL generated successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Mock payment gateway redirect URL error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'redirect_url' => null,
                'message' => 'Failed to generate redirect URL: ' . $e->getMessage(),
            ];
        }
    }
}

