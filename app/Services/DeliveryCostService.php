<?php

namespace App\Services;

use App\Models\AdditionalService;
use Illuminate\Support\Facades\Schema;

class DeliveryCostService
{
    public function current(): float
    {
        if (
            !Schema::hasTable('additional_services') ||
            !Schema::hasColumn('additional_services', 'code')
        ) {
            return 0.0;
        }

        $query = AdditionalService::query()->where('code', 'delivery');

        if (Schema::hasColumn('additional_services', 'is_active')) {
            $query->where('is_active', true);
        }

        $service = $query->first();

        return $service ? max(0, round((float) $service->price, 2)) : 0.0;
    }
}
