<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only e-invoice records, owner only.
 *
 * ⛔ No create, edit, delete, retry or void action. An issued invoice is a tax
 * document held by the authority, so editing it here would only make this
 * database disagree with the real record. A retry button would be a way to
 * issue a second invoice for an order whose first attempt was ambiguous —
 * exactly what the reconciliation state exists to prevent.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = '電子發票';

    protected static string|UnitEnum|null $navigationGroup = '訂單營運';

    protected static ?string $modelLabel = '電子發票';

    protected static ?string $pluralModelLabel = '電子發票';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
