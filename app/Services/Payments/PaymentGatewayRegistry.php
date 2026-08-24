<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\IntegrationProvider;
use App\Services\Integrations\LiveIntegration;
use Illuminate\Contracts\Container\Container;

/**
 * Which adapter handles which provider, and whether it may run at all.
 *
 * ⛔ M4C:每一個 provider 各自判斷,不再有一個「付款總開關」。Owner 只開了
 * LINE Pay 時,綠界必須不可用——共用一個布林值就會讓「開了其中一個」變成
 * 「兩個都開了」,而另一個沒有 credential,客人按下去只會得到失敗。
 *
 * ⛔ 回 null 時呼叫端 fail closed:永遠不退回 Fake gateway。一個默默假裝成功
 * 的結帳,比一個明白拒絕的結帳糟得多。
 */
class PaymentGatewayRegistry
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const ADAPTERS = [
        'ecpay' => EcpayPaymentGateway::class,
        'line-pay' => LinePayGateway::class,
    ];

    /**
     * 前台 provider 代號 → credential row 的 provider。
     *
     * ⛔ 公開代號刻意與 enum value 分離:公開網址與表單不透露我們用了哪一家
     * 的哪一個產品線,換 provider 也不必改已經分享出去的網址。
     *
     * @var array<string, IntegrationProvider>
     */
    private const CHANNELS = [
        'ecpay' => IntegrationProvider::EcpayPayment,
        'line-pay' => IntegrationProvider::LinePay,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * 這個環境可不可以對外送出付款請求。
     *
     * ⛔ 單獨成立不代表可以收款:還要 Owner 開了對應通道。要問「客人能不能
     * 用這個方式付款」,問 `availableToCustomer()`。
     */
    public function outboundAllowed(): bool
    {
        return LiveIntegration::outboundAllowed();
    }

    /** 這個前台付款方式現在可以顯示、也可以送出嗎? */
    public function availableToCustomer(string $provider): bool
    {
        $channel = self::CHANNELS[$provider] ?? null;

        return $channel !== null && LiveIntegration::availableToCustomer($channel);
    }

    /**
     * 目前 Owner 已開啟且 credential 齊全的前台付款方式。
     *
     * ⛔ 結帳頁與後端驗證都讀這一個方法:兩邊各自算一次,就會出現畫面上可以
     * 選、送出後被拒的情況——而客人已經填完整張表單了。
     *
     * @return list<string>
     */
    public function availableProviders(): array
    {
        return array_values(array_filter(
            array_keys(self::CHANNELS),
            fn (string $provider) => $this->availableToCustomer($provider),
        ));
    }

    /**
     * The adapter for this provider, or null if it cannot be used.
     *
     * ⛔ This is one of several entry points, not the only one — the adapters
     * and the callback each enforce the same guard themselves, because a public
     * callback route never passes through here.
     */
    public function for(string $provider): ?PaymentGateway
    {
        if (! $this->availableToCustomer($provider)) {
            return null;
        }

        $class = self::ADAPTERS[$provider] ?? null;

        return $class === null ? null : $this->container->make($class);
    }
}
