<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmation - {{ $payment->payment_reference ?? 'Payment' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-lg p-8 max-w-md w-full">
            @if(isset($error))
                <div class="text-center">
                    <div class="text-red-500 text-5xl mb-4">✕</div>
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">Payment Error</h1>
                    <p class="text-gray-600 mb-6">{{ $error }}</p>
                    <button onclick="window.close()" class="w-full bg-gray-500 text-white py-2 px-4 rounded hover:bg-gray-600">
                        Close
                    </button>
                </div>
            @else
                <div class="text-center mb-6">
                    <div class="text-green-500 text-5xl mb-4">✓</div>
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">Confirm Payment</h1>
                    <p class="text-gray-600">Please review and confirm your payment</p>
                </div>

                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <div class="flex justify-between mb-2">
                        <span class="text-gray-600">Payment Reference:</span>
                        <span class="font-semibold">{{ $payment->payment_reference }}</span>
                    </div>
                    <div class="flex justify-between mb-2">
                        <span class="text-gray-600">Amount:</span>
                        <span class="font-semibold">${{ number_format($payment->amount, 2) }}</span>
                    </div>
                    @if($payment->invoice)
                    <div class="flex justify-between">
                        <span class="text-gray-600">Invoice:</span>
                        <span class="font-semibold">{{ $payment->invoice->invoice_number }}</span>
                    </div>
                    @endif
                </div>

                <div class="space-y-3">
                    <button 
                        onclick="confirmPayment()" 
                        class="w-full bg-green-600 text-white py-3 px-4 rounded-lg hover:bg-green-700 font-semibold transition-colors"
                    >
                        Confirm Payment
                    </button>
                    <button 
                        onclick="cancelPayment()" 
                        class="w-full bg-gray-300 text-gray-700 py-3 px-4 rounded-lg hover:bg-gray-400 font-semibold transition-colors"
                    >
                        Cancel
                    </button>
                </div>
            @endif
        </div>
    </div>

    <script>
        let isProcessing = false;

        async function confirmPayment() {
            if (isProcessing) return;
            isProcessing = true;

            const returnUrl = '{{ $return_url ?? "" }}';
            if (!returnUrl) {
                alert('Return URL not configured');
                isProcessing = false;
                return;
            }

            // Show processing message
            const button = event.target;
            button.disabled = true;
            button.textContent = 'Processing...';

            try {
                // Call the API callback endpoint to process payment
                const url = new URL(returnUrl);
                url.searchParams.set('status', 'success');
                url.searchParams.set('transaction_id', 'MOCK-' + Date.now() + '-{{ $payment->id }}');
                url.searchParams.set('payment_reference', '{{ $payment->payment_reference }}');

                const response = await fetch(url.toString(), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (response.ok) {
                    // Show success message briefly
                    button.textContent = 'Payment Successful!';
                    button.classList.remove('bg-green-600', 'hover:bg-green-700');
                    button.classList.add('bg-green-500');
                    
                    // Notify parent window if it exists
                    if (window.opener) {
                        window.opener.postMessage({
                            type: 'payment-completed',
                            paymentId: {{ $payment->id }},
                            status: 'success'
                        }, '*');
                    }

                    // Close the tab after a short delay
                    setTimeout(() => {
                        window.close();
                    }, 1000);
                } else {
                    throw new Error('Payment processing failed');
                }
            } catch (error) {
                console.error('Payment processing error:', error);
                alert('Failed to process payment. Please try again.');
                button.disabled = false;
                button.textContent = 'Confirm Payment';
                isProcessing = false;
            }
        }

        async function cancelPayment() {
            if (isProcessing) return;
            isProcessing = true;

            const cancelUrl = '{{ $cancel_url ?? "" }}';
            
            // Show processing message
            const button = event.target;
            button.disabled = true;
            button.textContent = 'Cancelling...';

            try {
                if (cancelUrl) {
                    // Call the API callback endpoint to cancel payment
                    const url = new URL(cancelUrl);
                    url.searchParams.set('status', 'cancelled');

                    await fetch(url.toString(), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                }

                // Notify parent window if it exists
                if (window.opener) {
                    window.opener.postMessage({
                        type: 'payment-cancelled',
                        paymentId: {{ $payment->id }},
                        status: 'cancelled'
                    }, '*');
                }

                // Close the tab after a short delay
                setTimeout(() => {
                    window.close();
                }, 500);
            } catch (error) {
                console.error('Cancellation error:', error);
                // Close anyway
                window.close();
            }
        }
    </script>
</body>
</html>

