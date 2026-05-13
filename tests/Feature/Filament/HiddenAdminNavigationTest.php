<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\DepartmentResource;
use App\Filament\Resources\NoticeAttentionResource;
use App\Filament\Resources\WatchProfileResource;
use Tests\TestCase;

class HiddenAdminNavigationTest extends TestCase
{
    public function test_unrelated_admin_resources_are_hidden_from_navigation(): void
    {
        $this->assertFalse(DepartmentResource::shouldRegisterNavigation());
        $this->assertFalse(NoticeAttentionResource::shouldRegisterNavigation());
        $this->assertFalse(WatchProfileResource::shouldRegisterNavigation());
    }
}
