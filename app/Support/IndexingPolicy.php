<?php

namespace App\Support;

use Illuminate\Http\Request;

final class IndexingPolicy
{
    public function allows(Request $request): bool
    {
        if (config('app.env') !== 'production' || ! config('seo.allow_indexing')) {
            return false;
        }

        $expectedHost = $this->normalizeHost((string) config('seo.indexable_host'));
        $requestHost = $this->normalizeHost($request->getHost());

        if ($expectedHost === '' || $requestHost === '') {
            return false;
        }

        return hash_equals($expectedHost, $requestHost);
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }
}
