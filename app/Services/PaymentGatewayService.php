<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Gateways\MockPaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentGatewayService
{
    protected PaymentGatewayInterface $gateway;

    public function __construct(?PaymentGatewayInterface $gateway = null)
    {
        // Default to mock gateway if none provided
        // In production, this would be resolved from config or dependency injection
        $this->gateway = $gateway ?? new MockPaymentGateway();
    }

    /**
     * Set the gateway to use
     */
    public function setGateway(PaymentGatewayInterface $gateway): self
    {
        $this->gateway = $gateway;
        return $this;
    }

    /**
     * Get the current gateway
     */
    public function getGateway(): PaymentGatewayInterface
    {
        return $this->gateway;
    }

    /**
     * Resolve gateway based on payment method or gateway type
     */
    protected function resolveGateway(Payment $payment): PaymentGatewayInterface
    {
        // For now, always use mock gateway
        // In production, this would check payment->gateway or paymentMethod->type
        // and return appropriate gateway instance (Stripe, PayPal, etc.)
        return new MockPaymentGateway();
    }

    /**
     * Process a payment through the gateway
     * This handles the full payment flow including balance updates
     */
    public function processPayment(Payment $payment, array $options = []): array
    {
        $gateway = $this->resolveGateway($payment);

        try {
            DB::beginTransaction();

            // Update payment status to processing
            $payment->update([
                'status' => 'processing',
            ]);

            // Process payment through gateway
            $result = $gateway->processPayment($payment, $options);

            if ($result['success']) {
                // Update payment with gateway transaction ID
                $payment->update([
                    'gateway_transaction_id' => $result['transaction_id'],
                ]);

                // Mark payment as completed (this also updates invoice status)
                $payment->markAsCompleted();

                // Reload payment to get fresh user relationship
                $payment->refresh();
                $payment->load('user');

                // Update user balance ONLY after successful payment
                $this->updateUserBalance($payment->user, $payment->amount, 'debit');

                DB::commit();

                Log::info('Payment processed successfully through gateway', [
                    'payment_id' => $payment->id,
                    'gateway' => $gateway->getName(),
                    'transaction_id' => $result['transaction_id'],
                    'amount' => $payment->amount,
                ]);

                return [
                    'success' => true,
                    'transaction_id' => $result['transaction_id'],
                    'message' => $result['message'],
                ];
            } else {
                // Payment failed
                $payment->markAsFailed($result['message']);

                DB::commit(); // Commit failure state

                Log::warning('Payment processing failed through gateway', [
                    'payment_id' => $payment->id,
                    'gateway' => $gateway->getName(),
                    'error' => $result['message'],
                ]);

                return $result;
            }
        } catch (\Exception $e) {
            DB::rollBack();

            try {
                $payment->markAsFailed($e->getMessage());
            } catch (\Exception $markFailedException) {
                Log::error('Failed to mark payment as failed', [
                    'payment_id' => $payment->id ?? null,
                    'error' => $markFailedException->getMessage(),
                ]);
            }

            Log::error('Payment gateway service error', [
                'payment_id' => $payment->id ?? null,
                'gateway' => $gateway->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'transaction_id' => null,
                'message' => 'Payment processing error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Process a refund through the gateway
     * This handles the full refund flow including balance updates
     */
    public function processRefund(Refund $refund, array $options = []): array
    {
        $payment = $refund->payment;
        $gateway = $this->resolveGateway($payment);

        try {
            DB::beginTransaction();

            // Update refund status to processing
            $refund->update([
                'status' => 'processing',
            ]);

            // Process refund through gateway
            $result = $gateway->processRefund($refund, $options);

            if ($result['success']) {
                // Update refund with gateway refund ID
                $refund->update([
                    'gateway_refund_id' => $result['refund_id'],
                ]);

                // Mark refund as completed (this also updates invoice status)
                $refund->markAsCompleted();

                // Reload refund to get fresh user relationship
                $refund->refresh();
                $refund->load('user');

                // Update user balance ONLY after successful refund
                $this->updateUserBalance($refund->user, $refund->amount, 'credit');

                DB::commit();

                Log::info('Refund processed successfully through gateway', [
                    'refund_id' => $refund->id,
                    'gateway' => $gateway->getName(),
                    'refund_id_gateway' => $result['refund_id'],
                    'amount' => $refund->amount,
                ]);

                return [
                    'success' => true,
                    'refund_id' => $result['refund_id'],
                    'message' => $result['message'],
                ];
            } else {
                // Refund failed
                $refund->markAsFailed($result['message']);

                DB::commit(); // Commit failure state

                Log::warning('Refund processing failed through gateway', [
                    'refund_id' => $refund->id,
                    'gateway' => $gateway->getName(),
                    'error' => $result['message'],
                ]);

                return $result;
            }
        } catch (\Exception $e) {
            DB::rollBack();

            try {
                $refund->markAsFailed($e->getMessage());
            } catch (\Exception $markFailedException) {
                Log::error('Failed to mark refund as failed', [
                    'refund_id' => $refund->id ?? null,
                    'error' => $markFailedException->getMessage(),
                ]);
            }

            Log::error('Refund gateway service error', [
                'refund_id' => $refund->id ?? null,
                'gateway' => $gateway->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'refund_id' => null,
                'message' => 'Refund processing error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Update user account balance
     * Only called after successful transactions
     *
     * @param User $user
     * @param float $amount
     * @param string $type 'debit' for payments, 'credit' for refunds
     */
    /**
     * Update user account balance
     * Only called after successful transactions
     * 
     * NOTE: For debit (payments), we decrease balance (user is paying)
     *       For credit (refunds), we increase balance (user receives money back)
     *
     * @param User|null $user
     * @param float $amount
     * @param string $type 'debit' for payments, 'credit' for refunds
     */
    protected function updateUserBalance(?User $user, float $amount, string $type): void
    {
        if (!$user) {
            Log::warning('Cannot update balance: user is null');
            return;
        }

        if (!in_array($type, ['debit', 'credit'])) {
            throw new \InvalidArgumentException("Balance update type must be 'debit' or 'credit'");
        }

        try {
            $user->refresh(); // Get fresh balance

            $currentBalance = (float) ($user->balance ?? 0.0);
            
            if ($type === 'debit') {
                // Payment: decrease balance (user is paying out)
                $newBalance = $currentBalance - $amount;
            } else {
                // Refund: increase balance (user receives money back)
                $newBalance = $currentBalance + $amount;
            }

            // Ensure balance doesn't go negative (for payments)
            $newBalance = max(0, $newBalance);

            $user->update([
                'balance' => $newBalance,
            ]);

            Log::info('User balance updated', [
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'old_balance' => $currentBalance,
                'new_balance' => $newBalance,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update user balance', [
                'user_id' => $user->id ?? null,
                'amount' => $amount,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - balance update failure shouldn't fail the transaction
            // But log it for manual reconciliation
        }
    }

    /**
     * Verify a transaction with the gateway
     */
    public function verifyTransaction(string $transactionId, string $gateway = 'mock'): array
    {
        $gatewayInstance = $this->gateway;
        // In production, resolve gateway by name
        return $gatewayInstance->verifyTransaction($transactionId);
    }

    /**
     * Get redirect URL for payment gateway
     */
    public function getRedirectUrl(Payment $payment, array $options = []): array
    {
        $gateway = $this->resolveGateway($payment);
        return $gateway->getRedirectUrl($payment, $options);
    }
}

