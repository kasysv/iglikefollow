<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MockCheckoutController extends Controller
{
    public function store(Request $request): View
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $plans = config('catalog.instagram_followers.plans');

        $validated = $request->validate([
            'plan' => ['required', Rule::in(array_keys($plans))],
            'target' => ['required', 'string', 'max:255'],
            'payment' => ['required', Rule::in(['line-pay', 'ecpay'])],
        ]);

        $plan = $plans[$validated['plan']];

        return view('storefront.mock-success', [
            'plan' => $plan,
            'target' => $validated['target'],
            'paymentLabel' => $validated['payment'] === 'line-pay' ? 'LINE Pay' : '綠界付款',
        ]);
    }
}
