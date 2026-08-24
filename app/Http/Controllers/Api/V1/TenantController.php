<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\LogoProcessingUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LogoUploadRequest;
use App\Http\Requests\V1\TenantSettingsRequest;
use App\Models\Tenant;
use App\Services\LogoService;
use Illuminate\Http\JsonResponse;

class TenantController extends Controller
{
    public function __construct(
        private readonly LogoService $logoService,
    ) {}

    // ── GET /v1/tenant/settings ─────────────────────────────────────
    public function show(): JsonResponse
    {
        $tenant = auth()->user()->tenant;

        return response()->json($this->settingsPayload($tenant));
    }

    // ── PATCH /v1/tenant/settings ───────────────────────────────────
    public function update(TenantSettingsRequest $request): JsonResponse
    {
        $tenant = $this->resolveTenant();

        $tenant->update($request->validated());
        $tenant->refresh();

        return response()->json($this->settingsPayload($tenant));
    }

    // ── POST /v1/tenant/settings/logo ───────────────────────────────
    public function uploadLogo(LogoUploadRequest $request): JsonResponse
    {
        $tenant = $this->resolveTenant();

        try {
            $url = $this->logoService->store($request->file('logo'), $tenant);
        } catch (LogoProcessingUnavailable $e) {
            return response()->json([
                'error' => 'logo_processing_unavailable',
                'detail' => $e->getMessage(),
            ], 501);
        }

        $tenant->update(['business_logo_url' => $url]);

        return response()->json(['business_logo_url' => $url]);
    }

    private function resolveTenant(): Tenant
    {
        $user = auth()->user();

        if ($user->tenant) {
            return $user->tenant;
        }

        $tenant = Tenant::create([]);

        $user->tenant()->associate($tenant)->save();

        return $tenant;
    }

    /**
     * @return array{business_name: ?string, business_rut: ?string, business_logo_url: ?string}
     */
    private function settingsPayload(?Tenant $tenant): array
    {
        return [
            'business_name' => $tenant?->business_name,
            'business_rut' => $tenant?->business_rut,
            'business_logo_url' => $tenant?->business_logo_url,
        ];
    }
}
