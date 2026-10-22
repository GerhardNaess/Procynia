<?php

namespace App\Services\Security;

/** The web application, a queue job or a console command — never the external API. */
final class InteractiveActorChannel implements ActorChannel
{
    public function isApi(): bool
    {
        return false;
    }
}
