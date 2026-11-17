<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactRequests;

use App\Enums\ContactTopic;
use App\Filament\Resources\ContactRequests\Pages\ListContactRequests;
use App\Filament\Resources\ContactRequests\Pages\ViewContactRequest;
use App\Models\ContactRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Leads from the marketing Contact / Book a demo form (D84): read and mark handled. */
final class ContactRequestResource extends Resource
{
    protected static ?string $model = ContactRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'Leads';

    protected static ?string $modelLabel = 'lead';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime('j M Y, g:i a')->sortable(),
                TextColumn::make('topic')->badge()->formatStateUsing(fn (ContactTopic $state): string => $state->label()),
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('company')->searchable()->placeholder('—'),
                TextColumn::make('team_size')->label('Team')->placeholder('—'),
                IconColumn::make('handled_at')->label('Handled')->boolean()->state(fn (ContactRequest $record): bool => $record->handled_at !== null),
            ])
            ->filters([
                SelectFilter::make('topic')->options(collect(ContactTopic::cases())->mapWithKeys(fn (ContactTopic $t) => [$t->value => $t->label()])->all()),
                TernaryFilter::make('handled_at')->label('Handled')->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
                self::handledAction(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('topic')->formatStateUsing(fn (ContactTopic $state): string => $state->label()),
                TextEntry::make('name'),
                TextEntry::make('email')->copyable(),
                TextEntry::make('company')->placeholder('—'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('team_size')->placeholder('—'),
                TextEntry::make('message')->placeholder('—')->columnSpanFull(),
                TextEntry::make('created_at')->label('Received')->dateTime('j M Y, g:i a'),
                TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                TextEntry::make('handled_at')->label('Handled')->dateTime('j M Y, g:i a')->placeholder('Not yet'),
            ]),
        ]);
    }

    public static function handledAction(): Action
    {
        return Action::make('handled')
            ->label('Mark handled')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->visible(fn (ContactRequest $record): bool => $record->handled_at === null)
            ->action(fn (ContactRequest $record) => $record->forceFill(['handled_at' => now()])->save());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactRequests::route('/'),
            'view' => ViewContactRequest::route('/{record}'),
        ];
    }
}
