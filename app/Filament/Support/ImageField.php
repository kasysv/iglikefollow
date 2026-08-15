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
 * text is required unless the image is explicitly decorative.
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
            ->imageEditor()
            ->imageEditorAspectRatios([$ratio])
            ->helperText('JPEG／PNG／WebP，最大 5 MB。');
    }

    public static function alt(string $name): TextInput
    {
        return TextInput::make($name)
            ->label('圖片替代文字 (alt)')
            ->maxLength(255)
            ->helperText('上傳圖片時必填；純裝飾圖片才可留空。');
    }
}
