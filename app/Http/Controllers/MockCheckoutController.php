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

        $plans = $this->availablePlans();

        $validated = $request->validate([
            'plan' => ['required', Rule::in(array_keys($plans))],
            'target' => ['required', 'string', 'max:255'],
            'payment' => ['required', Rule::in(['line-pay', 'ecpay'])],
        ]);

        $selected = $plans[$validated['plan']];

        return view('storefront.mock-success', [
            'plan' => $selected['plan'],
            'serviceName' => $selected['service_name'],
            'platformName' => $selected['platform_name'],
            'target' => $validated['target'],
            'paymentLabel' => $validated['payment'] === 'line-pay' ? 'LINE Pay' : '綠界付款',
        ]);
    }

    /**
     * Flatten every mock plan across all platforms and services.
     *
     * Server-side validation must never trust the plan key submitted by the
     * browser, so the allow-list is rebuilt from config on each request.
     *
     * @return array<string, array{plan: array, service_name: string, platform_name: string}>
     */
    private function availablePlans(): array
    {
        $plans = [];

        foreach (config('catalog.platforms') as $platform) {
            foreach ($platform['services'] as $service) {
                foreach ($service['plans'] as $key => $plan) {
                    $plans[$key] = [
                        'plan' => $plan,
                        'service_name' => $service['name'],
                        'platform_name' => $platform['name'],
                    ];
                }
            }
        }

        return $plans;
    }
}
