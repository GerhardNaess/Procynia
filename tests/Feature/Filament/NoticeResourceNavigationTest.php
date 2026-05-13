<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\NoticeResource;
use Tests\TestCase;

class NoticeResourceNavigationTest extends TestCase
{
    public function test_notice_resource_is_hidden_from_filament_navigation(): void
    {
        $this->assertFalse(NoticeResource::shouldRegisterNavigation());
    }
}
