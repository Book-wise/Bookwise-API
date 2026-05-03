<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BookingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $bookings = Booking::with('status')
            ->latest('start_time')
            ->paginate(20);

        return BookingResource::collection($bookings);
    }

    public function show(int $id): BookingResource
    {
        $booking = Booking::with('status')->findOrFail($id);

        return new BookingResource($booking);
    }

    public function store(Request $request): BookingResource
    {
        $data = $request->validate([
            'client_id'               => ['required', 'integer', 'exists:clients,id'],
            'service_id'              => ['required', 'integer', 'exists:services,id'],
            'provider_id'             => ['required', 'integer', 'exists:providers,id'],
            'location_id'             => ['required', 'integer', 'exists:locations,id'],
            'status_id'               => ['required', 'integer', 'exists:booking_statuses,id'],
            'start_time'              => ['required', 'date'],
            'end_time'                => ['required', 'date', 'after:start_time'],
            'custom_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'price'                   => ['required', 'numeric', 'min:0'],
            'notes'                   => ['nullable', 'string'],
            'wc_order_id'             => ['nullable', 'integer'],
        ]);

        $booking = Booking::create($data);
        $booking->load('status');

        return new BookingResource($booking);
    }

    public function update(Request $request, int $id): BookingResource
    {
        $booking = Booking::findOrFail($id);

        $data = $request->validate([
            'client_id'               => ['sometimes', 'integer', 'exists:clients,id'],
            'service_id'              => ['sometimes', 'integer', 'exists:services,id'],
            'provider_id'             => ['sometimes', 'integer', 'exists:providers,id'],
            'location_id'             => ['sometimes', 'integer', 'exists:locations,id'],
            'status_id'               => ['sometimes', 'integer', 'exists:booking_statuses,id'],
            'start_time'              => ['sometimes', 'date'],
            'end_time'                => ['sometimes', 'date', 'after:start_time'],
            'custom_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'price'                   => ['sometimes', 'numeric', 'min:0'],
            'notes'                   => ['nullable', 'string'],
            'wc_order_id'             => ['nullable', 'integer'],
        ]);

        $booking->update($data);
        $booking->load('status');

        return new BookingResource($booking);
    }

    public function cancel(int $id): BookingResource
    {
        $booking = Booking::findOrFail($id);

        $cancelStatus = BookingStatus::where('is_cancellation', true)->firstOrFail();

        $booking->update(['status_id' => $cancelStatus->id]);
        $booking->load('status');

        return new BookingResource($booking);
    }
}
