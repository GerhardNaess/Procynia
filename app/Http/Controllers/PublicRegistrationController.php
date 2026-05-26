<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicRegistrationRequest;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PublicRegistrationController extends Controller
{
    public function store(PublicRegistrationRequest $request): RedirectResponse
    {
        try {
            $result = DB::transaction(function () use ($request): array {
                $validated = $request->validated();

                $companyName = Str::squish((string) $validated['company_name']);
                $ownerName = Str::squish((string) $validated['owner_name']);
                $ownerEmail = Str::lower(trim((string) $validated['owner_email']));
                $languageId = (int) $validated['language_id'];
                $nationalityId = (int) $validated['nationality_id'];

                $customer = Customer::query()->create([
                    'name' => $companyName,
                    'slug' => $this->generateUniqueCustomerSlug($companyName),
                    'nationality_id' => $nationalityId,
                    'language_id' => $languageId,
                    'is_active' => true,
                    'permission_settings' => Customer::DEFAULT_PERMISSION_SETTINGS,
                ]);

                $user = User::query()->create([
                    'name' => $ownerName,
                    'email' => $ownerEmail,
                    'password' => (string) $validated['password'],
                    'role' => User::customerRoleForBidRole(User::BID_ROLE_SYSTEM_OWNER),
                    'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
                    'bid_manager_scope' => null,
                    'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_COMPANY,
                    'primary_department_id' => null,
                    'department_id' => null,
                    'nationality_id' => $nationalityId,
                    'preferred_language_id' => $languageId,
                    'is_active' => true,
                    'customer_id' => $customer->id,
                ]);

                return [
                    'customer' => $customer,
                    'user' => $user,
                ];
            });
        } catch (Throwable $throwable) {
            Log::error('Public registration failed.', [
                'exception' => $throwable::class,
            ]);

            return back()
                ->withInput($request->except('password'))
                ->with('error', __('procynia.public.registration.failure'));
        }

        Auth::login($result['user']);
        $request->session()->regenerate();

        return redirect()
            ->route('app.notices.index', ['mode' => 'saved'])
            ->with('success', __('procynia.public.registration.success'));
    }

    private function generateUniqueCustomerSlug(string $companyName): string
    {
        $baseSlug = Str::slug($companyName) ?: 'customer';
        $slug = $baseSlug;
        $suffix = 2;

        while (Customer::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
