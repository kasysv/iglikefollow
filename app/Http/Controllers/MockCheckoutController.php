<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MockCheckoutController extends Controller
{
    public function store(Request $request): View
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $variants = $this->availableVariants();

        $validated = $request->validate([
            'variant' => ['required', Rule::in(array_keys($variants))],
            'quantity' => ['required', 'integer'],
            'target' => ['required', 'string', 'max:255'],
            'payment' => ['required', Rule::in(['line-pay', 'ecpay'])],
        ]);

        $selected = $variants[$validated['variant']];
        $bounds = $selected['variant']['quantity'];

        // 數量邊界永遠由伺服器重新驗證，不信任前端送出的值。
        $this->assertQuantityWithinBounds((int) $validated['quantity'], $bounds);

        return view('storefront.mock-success', [
            'variantLabel' => $selected['variant']['label'],
            'serviceName' => $selected['service_name'],
            'platformName' => $selected['platform_name'],
            'quantity' => (int) $validated['quantity'],
            'quantityUnit' => $selected['quantity_unit'],
            // 金額一律由伺服器依單價重算，不接受前端傳來的價格。
            'mockAmount' => (int) round($validated['quantity'] * $bounds['unit_price']),
            'target' => $validated['target'],
            'paymentLabel' => $validated['payment'] === 'line-pay' ? 'LINE Pay' : '綠界付款',
        ]);
    }

    /**
     * @param  array{min: int, max: int, step: int}  $bounds
     */
    private function assertQuantityWithinBounds(int $quantity, array $bounds): void
    {
        if ($quantity < $bounds['min'] || $quantity > $bounds['max']) {
            throw ValidationException::withMessages([
                'quantity' => "數量必須介於 {$bounds['min']} 至 {$bounds['max']} 之間。",
            ]);
        }

        if ($quantity % $bounds['step'] !== 0) {
            throw ValidationException::withMessages([
                'quantity' => "數量必須為 {$bounds['step']} 的倍數。",
            ]);
        }
    }

    /**
     * Flatten every mock variant across all platforms and services.
     *
     * The allow-list is rebuilt from config on each request so a submitted
     * variant key can never widen what the server accepts.
     *
     * @return array<string, array{variant: array, service_name: string, platform_name: string, quantity_unit: string}>
     */
    private function availableVariants(): array
    {
        $variants = [];

        foreach (config('catalog.platforms') as $platform) {
            foreach ($platform['services'] as $service) {
                foreach ($service['variants'] as $key => $variant) {
                    $variants[$key] = [
                        'variant' => $variant,
                        'service_name' => $service['name'],
                        'platform_name' => $platform['name'],
                        'quantity_unit' => $service['quantity_unit'] ?? '個',
                    ];
                }
            }
        }

        return $variants;
    }
}
