<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function publicPagesProvider(): array
    {
        return [
            'home' => ['/', 'Public/Home'],
            'features' => ['/funksjoner', 'Public/Features'],
            'pricing' => ['/priser', 'Public/Pricing'],
            'security' => ['/sikkerhet', 'Public/Security'],
            'contact' => ['/kontakt', 'Public/Contact'],
            'terms' => ['/betingelser', 'Public/Terms'],
            'privacy' => ['/personvern', 'Public/Privacy'],
            'faq' => ['/faq', 'Public/Faq'],
            'register' => ['/registrer', 'Public/Register'],
        ];
    }

    #[DataProvider('publicPagesProvider')]
    public function test_public_pages_are_accessible_without_login(string $path, string $component): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($component): bool {
            return data_get($page, 'component') === $component;
        });
    }

    public function test_app_root_still_redirects_guests_to_login(): void
    {
        $response = $this->get('/app');

        $response->assertRedirect(route('login'));
    }
}
