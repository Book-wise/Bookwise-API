<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Location;
use App\Models\Provider;

class SchedulingLockService
{
    public function lock(int $locationId, ?int $clientId = null, ?int $providerId = null): void
    {
        Location::whereKey($locationId)->lockForUpdate()->firstOrFail();

        if ($clientId !== null) {
            Client::whereKey($clientId)->lockForUpdate()->firstOrFail();
        }

        if ($providerId !== null) {
            Provider::whereKey($providerId)->lockForUpdate()->firstOrFail();
        }
    }
}
