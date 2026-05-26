<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_shows_the_login_page_for_guests(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertViewHas('page', fn (array $page): bool => data_get($page, 'component') === 'Auth/Login');
    }
}
