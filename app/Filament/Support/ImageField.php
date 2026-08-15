<?php

namespace App\Filament\Support;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\UploadedFile;
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

    /**
     * Server-detected MIME type to the extension we store it under.
     *
     * The stored extension is derived from the file's actual contents, never
     * from the uploaded filename: a real JPEG sent as "evil.pht" would
     * otherwise land in a web-served directory under a PHP-family extension.
     */
    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function upload(string $name, string $ratio = '16:9'): FileUpload
    {
        return FileUpload::make($name)
            ->image()
            ->disk('public')
            ->directory('uploads')
            ->visibility('public')
            ->acceptedFileTypes(self::ACCEPTED)
            ->maxSize(self::MAX_KB)
            // ⛔ 檔名與副檔名都不採用使用者提供的值：basename 用隨機 UUID，
            // 副檔名一律由伺服器偵測到的 MIME 反查，避免 .pht／.phtml 落盤。
            ->getUploadedFileNameForStorageUsing(
                fn ($file) => Str::uuid()->toString().'.'.self::extensionFor($file)
            )
            // 表單留空時保留既有圖片，⛔ 不因為沒重新上傳就把圖片刪掉。
            ->preserveFilenames(false)
            ->imageEditor()
            ->imageEditorAspectRatios([$ratio])
            ->helperText('JPEG／PNG／WebP，最大 5 MB。留空會保留原本的圖片。');
    }

    /**
     * The extension to store an upload under, from its detected contents.
     *
     * acceptedFileTypes() already rejects anything outside the allow-list, so
     * an unmapped type reaching this point means validation was bypassed;
     * refusing outright is the only safe answer, because guessing an extension
     * is what puts an executable name on disk in the first place.
     *
     * @throws \RuntimeException when the detected type is not an allowed image
     */
    public static function extensionFor(UploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());

        if (! array_key_exists($mime, self::EXTENSIONS)) {
            throw new \RuntimeException("拒絕儲存不在允許清單中的檔案類型：{$mime}");
        }

        return self::EXTENSIONS[$mime];
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
