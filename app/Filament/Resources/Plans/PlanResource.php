<?php

declare(strict_types=1);

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Models\Plan;
use App\Services\Platform\PlatformMetrics;
use BackedEnum;
use Database\Seeders\PlanSeeder;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Plans and limits editor over the `plans` table (spec §20/§22, D80, D87). Prices are
 * entered in paise / cents (integers, never floats); a blank limit means unlimited. The
 * four plans are fixed (no create or delete): Billing and Pricing read them by key.
 */
final class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $price = fn (string $column, string $label) => TextInput::make($column)
            ->label($label)
            ->integer()
            ->minValue(0)
            ->helperText('Smallest unit: paise or cents. 249900 = ₹2,499. Blank = not sold this way.');

        $limit = fn (string $column, string $label) => TextInput::make($column)
            ->label($label)
            ->integer()
            ->minValue(0)
            ->helperText('Blank = unlimited.');

        $features = array_values(array_unique(array_merge(...array_values(PlanSeeder::FEATURES))));

        return $schema->components([
            Section::make('Plan')->columns(3)->schema([
                TextInput::make('key')->disabled()->dehydrated(false),
                TextInput::make('name')->required()->maxLength(60),
                TextInput::make('position')->integer()->required()->minValue(0),
                TextInput::make('tagline')->maxLength(255)->columnSpan(3),
                Toggle::make('popular')->helperText('Highlighted on Pricing.'),
                Toggle::make('custom_pricing')->helperText('“Talk to us”: never sold through Checkout.'),
            ]),
            Section::make('Prices')->columns(2)->schema([
                $price('price_monthly_inr', 'Monthly, INR (paise)'),
                $price('price_yearly_inr', 'Yearly, INR (paise)'),
                $price('price_monthly_usd', 'Monthly, USD (cents)'),
                $price('price_yearly_usd', 'Yearly, USD (cents)'),
            ]),
            Section::make('Limits')->columns(4)->schema([
                $limit('max_companies', 'Companies'),
                $limit('max_users', 'Users'),
                $limit('max_vault_items', 'Vault items'),
                $limit('max_storage_mb', 'Storage (MB)'),
            ]),
            Section::make('Features')->schema([
                CheckboxList::make('features')
                    ->options(array_combine($features, array_map(fn (string $f): string => ucfirst(str_replace('_', ' ', $f)), $features)))
                    ->columns(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $money = fn (string $column, string $currency) => TextColumn::make($column)
            ->label(strtoupper($currency).' / '.(str_contains($column, 'yearly') ? 'yr' : 'mo'))
            ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : PlatformMetrics::money($state, $currency))
            ->placeholder('—');

        return $table
            ->defaultSort('position')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->weight('medium')->description(fn (Plan $record): string => $record->key),
                IconColumn::make('popular')->boolean(),
                $money('price_monthly_inr', 'inr'),
                $money('price_yearly_inr', 'inr'),
                $money('price_monthly_usd', 'usd'),
                TextColumn::make('max_companies')->label('Companies')->placeholder('∞'),
                TextColumn::make('max_users')->label('Users')->placeholder('∞'),
                TextColumn::make('max_vault_items')->label('Vault items')->placeholder('∞'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
