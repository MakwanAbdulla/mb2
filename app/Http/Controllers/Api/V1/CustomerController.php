<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Accounting\Customer;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CustomerController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $customers = Customer::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return CustomerResource::collection($customers);
    }

    public function show(Request $request, Customer $customer): CustomerResource
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        return new CustomerResource($customer);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'org_number'         => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:255',
            'member_id'          => 'nullable|exists:members,id',
            'default_account_id' => 'nullable|exists:accounts,id',
            'is_active'          => 'boolean',
        ]);

        $data['is_active'] = $data['is_active'] ?? true;

        $customer = Customer::create($data);

        AuditLog::record('customer_created', 'accounting', $customer->name, $customer);

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function update(Request $request, Customer $customer): CustomerResource
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'org_number'         => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:255',
            'default_account_id' => 'nullable|exists:accounts,id',
            'is_active'          => 'boolean',
        ]);

        $customer->update($data);

        AuditLog::record('customer_updated', 'accounting', $customer->name, $customer);

        return new CustomerResource($customer);
    }
}
