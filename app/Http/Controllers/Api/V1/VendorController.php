<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorResource;
use App\Models\Accounting\Vendor;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class VendorController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $vendors = Vendor::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return VendorResource::collection($vendors);
    }

    public function show(Request $request, Vendor $vendor): VendorResource
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        return new VendorResource($vendor);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'type'               => 'required|in:external,member',
            'org_number'         => 'nullable|string|max:20',
            'bankgiro'           => 'nullable|string|max:20',
            'plusgiro'           => 'nullable|string|max:20',
            'iban'               => 'nullable|string|max:34',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:30',
            'default_account_id' => 'nullable|exists:accounts,id',
            'default_vat_rate'   => 'nullable|integer|in:0,6,12,25',
            'notes'              => 'nullable|string|max:2000',
            'is_active'          => 'boolean',
        ]);

        $data['is_active'] = $data['is_active'] ?? true;
        $data['code']      = 'V' . str_pad((string) ((Vendor::max('id') ?? 0) + 1), 5, '0', STR_PAD_LEFT);

        $vendor = Vendor::create($data);

        AuditLog::record('vendor_created', 'accounting', $vendor->name, $vendor);

        return (new VendorResource($vendor))->response()->setStatusCode(201);
    }

    public function update(Request $request, Vendor $vendor): VendorResource
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'org_number'         => 'nullable|string|max:20',
            'bankgiro'           => 'nullable|string|max:20',
            'plusgiro'           => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:30',
            'default_account_id' => 'nullable|exists:accounts,id',
            'default_vat_rate'   => 'nullable|integer|in:0,6,12,25',
            'notes'              => 'nullable|string|max:2000',
            'is_active'          => 'boolean',
        ]);

        $vendor->update($data);

        AuditLog::record('vendor_updated', 'accounting', $vendor->name, $vendor);

        return new VendorResource($vendor);
    }
}
