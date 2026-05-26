<?php

namespace App\Http\Requests;

use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PublicRegistrationRequest extends FormRequest
{
    private const PRIVATE_EMAIL_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'yahoo.com',
        'icloud.com',
        'me.com',
        'proton.me',
        'protonmail.com',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'company_name' => Str::squish((string) $this->input('company_name', '')),
            'owner_name' => Str::squish((string) $this->input('owner_name', '')),
            'owner_email' => Str::lower(trim((string) $this->input('owner_email', ''))),
        ]);
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'min:12'],
            'language_id' => ['required', 'integer', Rule::exists(Language::class, 'id')],
            'nationality_id' => ['required', 'integer', Rule::exists(Nationality::class, 'id')],
            'privacy_accepted' => ['accepted'],
            'terms_accepted' => ['accepted'],
            'represents_business' => ['accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $email = (string) $this->input('owner_email', '');

            if ($email === '' || ! str_contains($email, '@')) {
                return;
            }

            $domain = Str::lower(Str::after($email, '@'));

            if ($domain === '' || ! in_array($domain, self::PRIVATE_EMAIL_DOMAINS, true)) {
                return;
            }

            $validator->errors()->add(
                'owner_email',
                __('procynia.public.registration.validation.owner_email_business')
            );
        });
    }

    public function messages(): array
    {
        return [
            'company_name.required' => __('procynia.public.registration.validation.company_name_required'),
            'owner_name.required' => __('procynia.public.registration.validation.owner_name_required'),
            'owner_email.required' => __('procynia.public.registration.validation.owner_email_required'),
            'owner_email.email' => __('procynia.public.registration.validation.owner_email_email'),
            'owner_email.unique' => __('procynia.public.registration.validation.owner_email_unique'),
            'password.required' => __('procynia.public.registration.validation.password_required'),
            'password.min' => __('procynia.public.registration.validation.password_min'),
            'language_id.required' => __('procynia.public.registration.validation.language_required'),
            'language_id.exists' => __('procynia.public.registration.validation.language_exists'),
            'nationality_id.required' => __('procynia.public.registration.validation.nationality_required'),
            'nationality_id.exists' => __('procynia.public.registration.validation.nationality_exists'),
            'privacy_accepted.accepted' => __('procynia.public.registration.validation.privacy_required'),
            'terms_accepted.accepted' => __('procynia.public.registration.validation.terms_required'),
            'represents_business.accepted' => __('procynia.public.registration.validation.business_required'),
        ];
    }
}
