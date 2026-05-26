<?php

namespace App\Providers;

use App\Models\Customer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Cashier::useCustomerModel(Customer::class);

        RateLimiter::for('public-registration', function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('owner_email', '')));
            $key = sprintf('%s|%s', $request->ip() ?? 'unknown', $email !== '' ? $email : 'anonymous');

            return Limit::perMinute(5)->by($key);
        });
    }
}
