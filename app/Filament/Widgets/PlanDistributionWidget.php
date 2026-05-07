<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PlanDistributionWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Planfordeling')
            ->query(
                Customer::query()
                    ->selectRaw('subscription_plan, billing_interval, count(*) as customer_count')
                    ->groupBy('subscription_plan', 'billing_interval')
                    ->orderByRaw("CASE subscription_plan WHEN 'enterprise' THEN 0 WHEN 'ultra' THEN 1 WHEN 'max' THEN 2 WHEN 'pro' THEN 3 ELSE 4 END"),
            )
            ->columns([
                TextColumn::make('subscription_plan')
                    ->label('Plan')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'pro' => 'Pro',
                        'max' => 'Max',
                        'ultra' => 'Ultra',
                        'enterprise' => 'Enterprise',
                        default => 'Gratis',
                    })
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'enterprise' => 'warning',
                        'ultra' => 'danger',
                        'max' => 'info',
                        'pro' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('billing_interval')
                    ->label('Intervall')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'yearly' => 'Årlig',
                        default => 'Månedlig',
                    }),

                TextColumn::make('customer_count')
                    ->label('Kunder')
                    ->numeric()
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
