<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResourceResource;
use App\Models\Booking\BookingResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BookingResourceController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        $resources = BookingResource::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('active', true))
            ->with('category')
            ->orderBy('name')
            ->get();

        return BookingResourceResource::collection($resources);
    }

    public function show(Request $request, BookingResource $bookingResource): BookingResourceResource
    {
        $bookingResource->load('category');

        return new BookingResourceResource($bookingResource);
    }
}
