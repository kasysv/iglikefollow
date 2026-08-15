<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use HasFactory;

    /** CTA 只能指向這些既有內部 route，⛔ 不接受任意 URL。 */
    public const CTA_ROUTES = ['home', 'platform', 'service'];

    protected $guarded = [];

    /** 單例：永遠只有一筆。 */
    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }
}
