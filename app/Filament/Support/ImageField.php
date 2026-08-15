<?php

namespace App\Filament\Support;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Shared image upload rules (spec §6).
 *
 * JPEG/PNG/WebP only — SVG is excluded because it can carry script.
 * Original filenames are discarded in favour of random UUID names, and alt
 * text is required whenever an image is actually present.
 */
class ImageField
{
    public const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_KB = 5120;

    public static function upload(string $name, string $ratio = '16:9'): FileUpload
    {
        return FileUpload::make($name)
            ->image()
            ->disk('public')
            ->directory('uploads')
            ->visibility('public')
            ->acceptedFileTypes(self::ACCEPTED)
            ->maxSize(self::MAX_KB)
            // ⛔ 不保留使用者原始檔名，改用不可預測的隨機名稱。
            ->getUploadedFileNameForStorageUsing(
                fn ($file) => Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension())
            )
            // 表單留空時保留既有圖片，⛔ 不因為沒重新上傳就把圖片刪掉。
            ->preserveFilenames(false)
            ->imageEditor()
            ->imageEditorAspectRatios([$ratio])
            ->helperText('JPEG／PNG／WebP，最大 5 MB。留空會保留原本的圖片。');
    }

    /**
     * Alt text paired with an image field.
     *
     * Required exactly when its image is present: a picture with no alt text is
     * unusable for screen readers and drops the image out of image search, but
     * demanding alt text on a record that has no image would block saving.
     */
    public static function alt(string $name, string $imageField): TextInput
    {
        return TextInput::make($name)
            ->label('圖片替代文字 (alt)')
            ->maxLength(255)
            ->required(fn ($get) => filled($get($imageField)))
            ->validationMessages(['required' => '有上傳圖片時，必須填寫圖片替代文字。'])
            ->helperText('描述圖片內容，例如「Instagram 粉絲服務示意圖」。有上傳圖片時必填。');
    }
}
