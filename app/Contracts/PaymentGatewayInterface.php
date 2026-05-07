<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\Refund;

interface PaymentGatewayInterface
{
    /**
     * Process a payment through the gateway
     *
     * @param Payment $payment
     * @param array $options Additional options for payment processing
     * @return array ['success' => bool, 'transaction_id' => string|null, 'message' => string]
     */
    public function processPayment(Payment $payment, array $options = []): array;

    /**
     * Process a refund through the gateway
     *
     * @param Refund $refund
     * @param array $options Additional options for refund processing
     * @return array ['success' => bool, 'refund_id' => string|null, 'message' => string]
     */
    public function processRefund(Refund $refund, array $options = []): array;

    /**
     * Get the gateway name/identifier
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if the gateway supports a specific payment method type
     *
     * @param string $paymentMethodType
     * @return bool
     */
    public function supportsPaymentMethod(string $paymentMethodType): bool;

    /**
     * Verify a transaction status with the gateway
     *
     * @param string $transactionId
     * @return array ['status' => string, 'amount' => float|null, 'currency' => string|null]
     */
    public function verifyTransaction(string $transactionId): array;

    /**
     * Get the redirect URL for payment confirmation
     * This URL should be opened in a new tab for the user to complete payment
     *
     * @param Payment $payment
     * @param array $options Additional options (e.g., return_url, cancel_url)
     * @return array ['success' => bool, 'redirect_url' => string|null, 'message' => string]
     */
    public function getRedirectUrl(Payment $payment, array $options = []): array;
}

