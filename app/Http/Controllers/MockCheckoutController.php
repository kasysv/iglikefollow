<?php

namespace App\Http\Controllers;

use App\Support\CatalogRepository;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MockCheckoutController extends Controller
{
    public function __construct(private readonly CatalogRepository $catalog) {}

    public function store(Request $request): View
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        // 白名單改由資料庫的 published variants 重建；⛔ draft／archived 不可購買。
        $purchasable = $this->catalog->purchasableVariants();

        $validated = $request->validate([
            'variant' => ['required', Rule::in($purchasable->keys()->all())],
            'quantity' => ['required', 'integer'],
            'target' => ['required', 'string', 'max:255'],
            'payment' => ['required', Rule::in(['line-pay', 'ecpay'])],
        ]);

        $variant = $purchasable->get((int) $validated['variant']);
        $quantity = (int) $validated['quantity'];

        // 數量邊界永遠由伺服器重新驗證，不信任前端送出的值。
        if (! $variant->quantityIsValid($quantity)) {
            throw ValidationException::withMessages([
                'quantity' => "數量必須介於 {$variant->min_quantity} 至 {$variant->max_quantity} 之間，且為 {$variant->step_quantity} 的倍數。",
            ]);
        }

        return view('storefront.mock-success', [
            'variantLabel' => $variant->label,
            'serviceName' => $variant->service->name,
            'platformName' => $variant->service->platform->name,
            'quantity' => $quantity,
            'quantityUnit' => $variant->quantity_unit,
            // 金額一律由伺服器依單價重算，⛔ 忽略前端傳來的任何價格欄位。
            'mockAmount' => $variant->amountFor($quantity),
            'target' => $validated['target'],
            'paymentLabel' => $validated['payment'] === 'line-pay' ? 'LINE Pay' : '綠界付款',
        ]);
    }
}
