<?php

namespace App\Filament\Resources\Inbounds;

use App\Filament\Resources\Inbounds\Pages\CreateInbound;
use App\Filament\Resources\Inbounds\Pages\EditInbound;
use App\Filament\Resources\Inbounds\Pages\ListInbounds;
use App\Filament\Resources\Inbounds\Schemas\InboundForm;
use App\Filament\Resources\Inbounds\Tables\InboundsTable;
use App\Models\Inbound;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InboundResource extends Resource
{
    protected static ?string $model = Inbound::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Barang Masuk';

    public static function form(Schema $schema): Schema
    {
        return InboundForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InboundsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInbounds::route('/'),
            'create' => CreateInbound::route('/create'),
            'edit' => EditInbound::route('/{record}/edit'),
        ];
    }
}
