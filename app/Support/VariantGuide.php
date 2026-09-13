<?php

namespace App\Support;

final class VariantGuide
{
    public const LABELS = [
        'real' => '真人',
        'premium' => '頂級',
        'advanced' => '高級',
        'standard' => '普通',
    ];

    public static function defaults(): array
    {
        return [
            'heading' => '真人、頂級、高級、普通有什麼差別？',
            'real' => '台灣本地真實用戶，以學生及上班族為主，真實度最高。',
            'premium' => '人手註冊的高仿真帳號，帳號資料、粉絲及貼文完整，外觀接近真人帳號，並可選擇全男或全女。',
            'advanced' => '人手註冊的仿真帳號，帳號資料、粉絲及貼文齊全，穩定度高，整體素質優於一般級別。',
            'standard' => '人手註冊的仿真帳號，帳號資料、粉絲及貼文齊全，屬於市面常見級別。',
            'choice_heading' => '怎麼選？',
            'choice_real' => '重視真實度',
            'choice_premium' => '重視性價比',
            'choice_standard' => '重視數量',
        ];
    }

    /** Only known text fields can reach the escaped Blade template. */
    public static function resolve(mixed $saved): array
    {
        $guide = self::defaults();

        foreach ($guide as $key => $default) {
            if (is_array($saved) && isset($saved[$key]) && is_string($saved[$key])) {
                $guide[$key] = $saved[$key];
            }
        }

        return $guide;
    }
}
