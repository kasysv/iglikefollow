<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\View\View;

/**
 * What the customer sees after coming back from a provider.
 *
 * ⛔ Read-only, and it never changes an order. The page reports whatever the
 * verified server-side result already says — which may still be "confirming",
 * because a browser arriving back does not mean the provider has told us
 * anything yet.
 */
class PaymentStatusController extends Controller
{
    public function __invoke(string $reference): View
    {
        // reference 是不可猜測的訂單編號；⛔ URL 不含任何個資。
        $order = Order::where('reference', $reference)
            ->with(['items', 'paymentAttempts'])
            ->firstOrFail();

        return view('payments.status', ['order' => $order]);
    }
}
