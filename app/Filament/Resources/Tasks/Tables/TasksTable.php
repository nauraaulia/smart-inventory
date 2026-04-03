<?php

namespace App\Filament\Resources\Tasks\Tables;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),

                TextColumn::make('for_role')
                    ->label('Untuk')
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->color('info')
                    ->searchable(),

                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->limit(50),

                BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'done',
                        'danger' => 'rejected',
                    ]),

                TextColumn::make('related_type')
                    ->label('Relasi'),

                TextColumn::make('related_id')
                    ->label('ID Relasi'),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make(),

                EditAction::make(),

                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'done',
                            'handled_by' => auth()->id(),
                            'handled_at' => now(),
                        ]);
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'rejected',
                            'handled_by' => auth()->id(),
                            'handled_at' => now(),
                        ]);
                    }),

                DeleteAction::make(),
            ]);
    }
}