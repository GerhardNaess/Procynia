<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\DoffinHarvest;
use App\Filament\Pages\DoffinSupplierHarvest;
use App\Filament\Pages\Incidents;
use App\Filament\Pages\Monitoring;
use App\Filament\Pages\QueueScheduler;
use App\Filament\Pages\SystemStatus;
use App\Filament\Resources\DepartmentResource;
use App\Filament\Resources\NoticeAttentionResource;
use App\Filament\Resources\WatchProfileResource;
use Tests\TestCase;

class HiddenAdminNavigationTest extends TestCase
{
    public function test_legacy_and_placeholder_pages_are_hidden_from_navigation(): void
    {
        $this->assertFalse(DoffinHarvest::shouldRegisterNavigation());
        $this->assertFalse(Incidents::shouldRegisterNavigation());
        $this->assertFalse(Monitoring::shouldRegisterNavigation());
    }

    public function test_active_operational_pages_remain_registered_for_navigation(): void
    {
        $this->assertTrue(DoffinSupplierHarvest::shouldRegisterNavigation());
        $this->assertTrue(SystemStatus::shouldRegisterNavigation());
    }

    public function test_queue_scheduler_is_hidden_from_navigation(): void
    {
        $this->assertFalse(QueueScheduler::shouldRegisterNavigation());
    }

    public function test_unrelated_admin_resources_are_hidden_from_navigation(): void
    {
        $this->assertFalse(DepartmentResource::shouldRegisterNavigation());
        $this->assertFalse(NoticeAttentionResource::shouldRegisterNavigation());
        $this->assertFalse(WatchProfileResource::shouldRegisterNavigation());
    }
}
