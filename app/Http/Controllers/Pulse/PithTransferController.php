<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Services\Pulse\PithStockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pith stock transfers between locations, from the mobile app.
 *
 * A transfer is an outward at one location and an inward at another, so each action takes the
 * matching capability on both sides: create to make one, edit to change its destination,
 * quantity, date or comment, and delete to reverse it. The source of a transfer never changes.
 */
class PithTransferController extends Controller
{
    public function __construct(private readonly PithStockTransferService $transfers) {}

    public function store(Request $request): JsonResponse
    {
        if (! Gate::allows('pith_inventory_outward.create') || ! Gate::allows('pith_inventory_inward.create')) {
            return response()->json(['message' => 'You are not authorized to transfer pith stock.'], 403);
        }

        $validated = $request->validate([
            'from_location_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer'],
            'cft' => ['required', 'numeric', 'gt:0', 'max:'.PithStockTransferService::MAX_CFT],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'from_location_id.required' => 'Source location is required.',
            'to_location_id.required' => 'Destination location is required.',
            'cft.required' => 'Transfer quantity is required.',
            'cft.numeric' => 'Transfer quantity must be a number.',
            'cft.gt' => 'Transfer quantity must be greater than 0 cft.',
            'cft.max' => 'Transfer quantity cannot exceed '.PithStockTransferService::MAX_CFT.' cft.',
            'date.date_format' => 'Date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'Date cannot be in the future.',
            'note.max' => 'Comments cannot exceed 255 characters.',
        ]);

        $transfer = $this->transfers->transfer(
            teamId: $request->user()->activeTeam()->id,
            fromLocationId: (int) $validated['from_location_id'],
            toLocationId: (int) $validated['to_location_id'],
            cft: (float) $validated['cft'],
            date: $validated['date'] ?? now()->toDateString(),
            note: $validated['note'] ?? null,
            userId: $request->user()->id,
        );

        return response()->json([
            'message' => 'Pith stock transferred successfully',
            'transfer' => $transfer->toApiArray(),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! Gate::allows('pith_inventory_outward.edit') || ! Gate::allows('pith_inventory_inward.edit')) {
            return response()->json(['message' => 'You are not authorized to edit a transfer.'], 403);
        }

        $transfer = $this->transfers->findForTeam($request->user()->activeTeam()->id, $id);

        if (! $transfer) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        $validated = $request->validate([
            'to_location_id' => ['required', 'integer'],
            'cft' => ['required', 'numeric', 'gt:0', 'max:'.PithStockTransferService::MAX_CFT],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'to_location_id.required' => 'Destination location is required.',
            'cft.required' => 'Transfer quantity is required.',
            'cft.numeric' => 'Transfer quantity must be a number.',
            'cft.gt' => 'Transfer quantity must be greater than 0 cft.',
            'cft.max' => 'Transfer quantity cannot exceed '.PithStockTransferService::MAX_CFT.' cft.',
            'date.date_format' => 'Date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'Date cannot be in the future.',
            'note.max' => 'Comments cannot exceed 255 characters.',
        ]);

        $transfer = $this->transfers->update(
            transfer: $transfer,
            toLocationId: (int) $validated['to_location_id'],
            cft: (float) $validated['cft'],
            date: $validated['date'] ?? $transfer->date->format('Y-m-d'),
            note: $validated['note'] ?? null,
        );

        return response()->json([
            'message' => 'Transfer updated successfully',
            'transfer' => $transfer->toApiArray(),
        ], 200);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! Gate::allows('pith_inventory_outward.delete') || ! Gate::allows('pith_inventory_inward.delete')) {
            return response()->json(['message' => 'You are not authorized to delete a transfer.'], 403);
        }

        $transfer = $this->transfers->findForTeam($request->user()->activeTeam()->id, $id);

        if (! $transfer) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        $this->transfers->delete($transfer);

        return response()->json(['message' => 'Transfer deleted successfully'], 200);
    }
}
