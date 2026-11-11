<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    // GET /api/v1/admin/settings
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('system.settings'), 403);

        $settings = SystemSetting::orderBy('category')->orderBy('key')->get()
            ->map(fn ($s) => [
                'key'         => $s->key,
                'category'    => $s->category,
                'label'       => $s->label,
                'description' => $s->description,
                'value'       => $s->type === SystemSetting::TYPE_ENCRYPTED ? '***' : SystemSetting::get($s->key),
                'type'        => $s->type,
            ]);

        return response()->json(['data' => $settings]);
    }

    // PATCH /api/v1/admin/settings/{key}
    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('system.settings'), 403);

        $setting = SystemSetting::findOrFail($key);

        $data = $request->validate([
            'value' => 'required',
        ]);

        SystemSetting::set($key, $data['value'], $setting->type);

        AuditLog::record('system_setting_updated', 'system', $key, null);

        return response()->json(['key' => $key]);
    }
}
