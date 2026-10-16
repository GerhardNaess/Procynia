<?php

namespace Tests\Unit\Validation;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Laravel ships its validation messages in English only, and production runs with locale and
 * fallback «no». Every rule without its own message therefore showed its raw key
 * («validation.required») to Norwegian users, or English text where the fallback was «en». These
 * tests go through the real translator and validator, not the language file's array.
 */
class NorwegianValidationMessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->setLocale(config('app.locale'));

        parent::tearDown();
    }

    public function test_standard_rules_answer_in_norwegian_with_the_fields_name_as_forms_label_it(): void
    {
        app()->setLocale('no');

        $errors = Validator::make([
            'owner_user_id' => null,
            'title' => str_repeat('x', 256),
            'email' => 'ikke-en-adresse',
            'password' => 'kort',
            'contract_value_mnok' => 'tolv',
            'due_date' => '2026-02-30',
            'status' => 'ukjent',
        ], [
            'owner_user_id' => ['required'],
            'title' => ['max:255'],
            'email' => ['email'],
            'password' => ['min:8'],
            'contract_value_mnok' => ['numeric'],
            'due_date' => ['date'],
            'status' => ['in:open,closed'],
        ])->errors();

        $this->assertSame('Ansvarlig må fylles ut.', $errors->first('owner_user_id'));
        $this->assertSame('Tittel kan ha maks 255 tegn.', $errors->first('title'));
        $this->assertSame('Skriv en gyldig e-postadresse i e-post.', $errors->first('email'));
        $this->assertSame('Passord må ha minst 8 tegn.', $errors->first('password'));
        $this->assertSame('Avtaleverdi må være et tall.', $errors->first('contract_value_mnok'));
        $this->assertSame('Frist må være en gyldig dato.', $errors->first('due_date'));
        $this->assertSame('Velg en gyldig verdi for status.', $errors->first('status'));

        foreach ($errors->all() as $message) {
            $this->assertStringNotContainsString('validation.', $message);
        }
    }

    public function test_a_message_passed_by_the_form_still_wins_over_the_language_file(): void
    {
        app()->setLocale('no');

        $errors = Validator::make(['name' => null], ['name' => ['required']], ['name.required' => 'Fagområdet må ha et navn.'])->errors();

        $this->assertSame('Fagområdet må ha et navn.', $errors->first('name'));
    }

    public function test_the_norwegian_file_covers_every_rule_laravel_has_a_message_for(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');

        foreach ($this->ruleKeys($english) as $key) {
            $line = __("validation.{$key}", [], 'no');

            $this->assertIsString($line, $key);
            $this->assertNotSame("validation.{$key}", $line, "validation.{$key} has no Norwegian message");
        }
    }

    public function test_english_keeps_laravels_messages_with_readable_field_names(): void
    {
        app()->setLocale('en');

        $errors = Validator::make(['owner_user_id' => null, 'title' => null], ['owner_user_id' => ['required'], 'title' => ['required']])->errors();

        $this->assertSame('The owner field is required.', $errors->first('owner_user_id'));
        $this->assertSame('The title field is required.', $errors->first('title'));
    }

    public function test_both_languages_name_the_same_fields(): void
    {
        $this->assertSame(
            array_keys(__('validation.attributes', [], 'no')),
            array_keys(__('validation.attributes', [], 'en')),
        );
    }

    /** @return list<string> dot paths of the rule messages, without custom and attributes */
    private function ruleKeys(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $line) {
            if ($prefix === '' && in_array($key, ['custom', 'attributes'], true)) {
                continue;
            }

            $keys = is_array($line)
                ? [...$keys, ...$this->ruleKeys($line, "{$prefix}{$key}.")]
                : [...$keys, "{$prefix}{$key}"];
        }

        return $keys;
    }
}
