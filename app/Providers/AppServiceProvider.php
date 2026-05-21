<?php

namespace App\Providers;

use App\Services\Ai\Contracts\AiTextGenerationClient;
use App\Services\Ai\Providers\OpenAiTextGenerationClient;
use App\Models\Customer;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiTextGenerationClient::class, OpenAiTextGenerationClient::class);
    }

    public function boot(): void
    {
        Cashier::useCustomerModel(Customer::class);
    }
}
