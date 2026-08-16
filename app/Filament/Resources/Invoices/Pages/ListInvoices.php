<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    /** ⛔ 不提供任何建立發票的入口：發票只能由已付款訂單自動產生。 */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
