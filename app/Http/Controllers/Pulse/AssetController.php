<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Asset;
use App\Models\Pulse\AssetCategory;
use App\Models\Pulse\AssetOutward;
use App\Models\Pulse\AssetPurchase;
use App\Models\Pulse\AssetReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        // $currentTeamId = Auth::user()->currentTeam->id;
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $assets = Asset::with(['assetCategory'])
            ->where('team_id', $teamId)
            ->orderBy('asset_name', 'asc')
            ->get()
            ->map(function ($asset) {
                return [
                    'id' => $asset->id,
                    'asset_name' => $asset->asset_name,
                    'category_name' => $asset->assetCategory->name ?? null,
                    'state' => (int) $asset->state,
                    'remarks' => $asset->remarks,
                ];
            });

        return response()->json($assets);
    }

    public function add()
    {
        if (! Gate::allows('asset_tracking.create')) {
            return response()->json(['error' => 'You are not authorized to add asset.'], 403);
        }

        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        if (! $teamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        $categories = AssetCategory::where('team_id', $teamId)
            ->where('is_active', 1)
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);

        return response()->json([
            'categories' => $categories,
        ], 200);
    }

    public function store(Request $request)
    {
        $capability = $request->input('id') ? 'asset_tracking.edit' : 'asset_tracking.create';
        if (! Gate::allows($capability)) {
            return response()->json(['error' => 'You are not authorized to add or edit asset.'], 403);
        }

        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        if (! $teamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $validated = $request->validate([
                'asset_name' => 'required|string|max:50',
                'asset_category_id' => 'required|exists:asset_categories,id',
                'state' => 'required|in:0,1',
                'remarks' => 'nullable|string',
                'id' => 'nullable|integer',
                'attachments' => 'nullable|array',
                'attachments.*' => 'required|file|max:500|mimes:jpg,jpeg,png,gif,tiff,svg',
            ], [
                'attachments.*.file' => 'Each attachment must be a valid file.',
                'attachments.*.max' => 'The uploaded file must not be greater than 500 KB',
            ]);

            $assetId = $validated['id'] ?? null;
            $filePaths = [];

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filePaths[] = $file->store('attachments', 'public');
                }
            }

            if ($assetId) {
                $asset = Asset::where('team_id', $teamId)->where('id', $assetId)->first();
                if (! $asset) {
                    return response()->json(['error' => 'Asset not found or unauthorized.'], 422);
                }
                // Handle existing attachments preservation and deletion
                $existingAttachments = $asset->attachments ? json_decode($asset->attachments, true) : [];

                // Client may send remaining existing attachments (kept ones) as JSON
                $keepAttachments = $request->input('existing_attachments', []);
                if (is_string($keepAttachments)) {
                    $keepAttachments = json_decode($keepAttachments, true) ?? [];
                }
                $keepAttachments = is_array($keepAttachments) ? $keepAttachments : [];

                // Ensure the keep list only contains items that actually existed
                $keepAttachments = array_values(array_intersect($existingAttachments, $keepAttachments));

                // Delete attachments the user removed
                $attachmentsToDelete = array_diff($existingAttachments, $keepAttachments);
                foreach ($attachmentsToDelete as $file) {
                    Storage::disk('public')->delete($file);
                }

                // Enforce overall limit (assets allow up to 1 file in the web UI)
                $total = count($keepAttachments) + count($filePaths);
                if ($total > 1) {
                    return response()->json(['error' => 'You can upload a maximum of 1 file.'], 422);
                }

                $validated['attachments'] = json_encode(array_values(array_merge($keepAttachments, $filePaths)), JSON_UNESCAPED_SLASHES);

                $asset->update([
                    'asset_name' => $validated['asset_name'],
                    'asset_category_id' => $validated['asset_category_id'],
                    'state' => (int) $validated['state'],
                    'remarks' => $validated['remarks'] ?? null,
                    'attachments' => $validated['attachments'],
                ]);

                return response()->json(['message' => 'Asset updated successfully.', 'asset' => $asset], 200);
            } else {
                // For new asset, store uploaded files and ensure limits
                $totalNew = count($filePaths);
                if ($totalNew > 1) {
                    return response()->json(['error' => 'You can upload a maximum of 1 file.'], 422);
                }

                $validated['attachments'] = $filePaths ? json_encode($filePaths, JSON_UNESCAPED_SLASHES) : '';
                $validated['team_id'] = $teamId;

                $asset = Asset::create($validated);

                return response()->json(['message' => 'Asset saved successfully.', 'asset' => $asset], 200);
            }
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error saving asset: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function viewDetails($id)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $asset = Asset::with(['assetCategory'])
            ->where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $asset) {
            return response()->json(['error' => 'Asset not found or unauthorized.'], 404);
        }

        // Compute totals similar to Livewire AssetView logic
        $totalPurchased = (int) AssetPurchase::where('asset_id', $id)->sum('purchased_quantity');
        $totalOutward = (int) AssetOutward::where('asset_id', $id)->sum('outward_quantity');
        $totalReturned = (int) AssetReturn::whereIn('outward_id', function ($q) use ($id) {
            $q->select('outward_id')->from('pulse_asset_outwards')->where('asset_id', $id);
        })->sum('returned_quantity');

        $inStock = ($totalPurchased + $totalReturned) - $totalOutward;
        // Fetch lists for mobile details view
        $purchases = AssetPurchase::where('asset_id', $id)
            ->orderBy('purchase_date', 'desc')
            ->get()
            ->map(function ($p) {
                return [
                    'asset_purchase_id' => $p->asset_purchase_id,
                    'purchase_type' => $p->purchase_type,
                    'purchase_date' => $p->purchase_date,
                    'from_company' => $p->from_company,
                    'purchased_quantity' => (int) $p->purchased_quantity,
                    'price' => $p->price,
                    'remarks' => $p->remarks,
                ];
            });

        $outwards = AssetOutward::where('asset_id', $id)
            ->orderBy('outward_date', 'desc')
            ->get()
            ->map(function ($o) {
                return [
                    'outward_id' => $o->outward_id,
                    'outward_type' => $o->outward_type,
                    'outward_date' => $o->outward_date,
                    'approved_by' => $o->approved_by ?? null,
                    'to_company' => $o->to_company,
                    'outward_quantity' => (int) $o->outward_quantity,
                    'remarks' => $o->remarks,
                ];
            });

        $returns = AssetReturn::whereIn('outward_id', function ($q) use ($id) {
            $q->select('outward_id')->from('pulse_asset_outwards')->where('asset_id', $id);
        })->orderBy('returned_date', 'desc')
            ->get()
            ->map(function ($r) {
                return [
                    'return_id' => $r->return_id,
                    'outward_id' => $r->outward_id,
                    'returned_date' => $r->returned_date,
                    'returned_quantity' => (int) $r->returned_quantity,
                    'remarks' => $r->remarks,
                ];
            });

        return response()->json([
            'asset' => [
                'id' => $asset->id,
                'asset_name' => $asset->asset_name,
                'category' => [
                    'id' => $asset->assetCategory->id ?? null,
                    'name' => $asset->assetCategory->name ?? null,
                ],
                'state' => (int) $asset->state,
                'remarks' => $asset->remarks,
                'attachments' => $asset->attachments ? json_decode($asset->attachments, true) : [],
                'purchases' => $purchases,
                'outwards' => $outwards,
                'returns' => $returns,
                'totals' => [
                    'total_purchased' => $totalPurchased,
                    'total_outward' => $totalOutward,
                    'total_returned' => $totalReturned,
                    'in_stock' => $inStock,
                ],
            ],
        ], 200);
    }

    public function edit($id)
    {
        if (! Gate::allows('asset_tracking.edit')) {
            return response()->json(['error' => 'You are not authorized to edit asset.'], 403);
        }

        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $asset = Asset::with(['assetCategory'])
            ->where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $asset) {
            return response()->json(['error' => 'Asset not found or unauthorized.'], 404);
        }

        $categories = AssetCategory::where('team_id', $teamId)
            ->where('is_active', 1)
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);

        return response()->json([
            'asset' => [
                'id' => $asset->id,
                'asset_name' => $asset->asset_name,
                'category' => [
                    'id' => $asset->assetCategory->id ?? null,
                    'name' => $asset->assetCategory->name ?? null,
                ],
                'state' => (int) $asset->state,
                'remarks' => $asset->remarks,
                'attachments' => $asset->attachments ? json_decode($asset->attachments, true) : [],
            ],
            'categories' => $categories,
        ], 200);
    }

    public function destroy($id)
    {
        if (! Gate::allows('asset_tracking.delete')) {
            return response()->json(['error' => 'You are not authorized for this action - delete asset.'], 403);
        }

        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        if (! $teamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        $deleted = Asset::where('id', $id)->where('team_id', $teamId)->delete();

        if ($deleted === 0) {
            return response()->json(['error' => 'Asset does not exist or not authorized.'], 400);
        }

        return response()->json(['message' => 'Asset deleted successfully.'], 200);
    }

    public function purchaseStore(Request $request)
    {
        if (! Gate::allows('asset_tracking.create')) {
            return response()->json(['error' => 'You are not authorized to add purchase.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $validated = $request->validate([
                'asset_id' => 'required|integer|exists:assets,id',
                'purchase_type' => ['required', Rule::in(['Purchased', 'Loaned'])],
                'purchased_date' => ['required', 'date'],
                'from_company' => ['required', 'string', 'max:50'],
                'price' => ['nullable', 'numeric', 'min:0'],
                'purchased_quantity' => ['required', 'integer', 'min:1'],
                'remarks' => ['nullable', 'string'],
            ]);

            $asset = Asset::where('team_id', $currentTeamId)->where('id', $validated['asset_id'])->first();
            if (! $asset) {
                return response()->json(['error' => 'Asset not found or unauthorized.'], 422);
            }

            $purchase = AssetPurchase::create([
                'asset_id' => (int) $validated['asset_id'],
                'purchase_type' => $validated['purchase_type'],
                'purchase_date' => $validated['purchased_date'],
                'from_company' => $validated['from_company'],
                'purchased_quantity' => (int) $validated['purchased_quantity'],
                'price' => $validated['price'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            return response()->json(['message' => 'Purchase saved successfully', 'purchase' => $purchase], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error saving purchase: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function purchaseUpdate(Request $request, $id)
    {
        if (! Gate::allows('asset_tracking.edit')) {
            return response()->json(['error' => 'You are not authorized to update purchase.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $validated = $request->validate([
                'purchase_type' => ['required', Rule::in(['Purchased', 'Loaned'])],
                'purchased_date' => ['required', 'date'],
                'from_company' => ['required', 'string', 'max:50'],
                'price' => ['nullable', 'numeric', 'min:0'],
                'purchased_quantity' => ['required', 'integer', 'min:1'],
                'remarks' => ['nullable', 'string'],
            ]);

            $purchase = AssetPurchase::find($id);
            if (! $purchase) {
                return response()->json(['error' => 'Purchase not found.'], 404);
            }

            $asset = Asset::where('team_id', $currentTeamId)->where('id', $purchase->asset_id)->first();
            if (! $asset) {
                return response()->json(['error' => 'Purchase not authorized.'], 422);
            }

            // Ensure updating this purchase does not reduce total purchased below total outward
            $currentPurchasedTotal = (int) AssetPurchase::where('asset_id', $purchase->asset_id)->sum('purchased_quantity');
            $currentOutwardTotal = (int) AssetOutward::where('asset_id', $purchase->asset_id)->sum('outward_quantity');
            $newPurchasedTotal = $currentPurchasedTotal - (int) $purchase->purchased_quantity + (int) $validated['purchased_quantity'];

            if ($newPurchasedTotal < $currentOutwardTotal) {
                return response()->json(['error' => 'Cannot update: total purchased quantity would be less than outward quantity.'], 422);
            }

            $purchase->update([
                'purchase_type' => $validated['purchase_type'],
                'purchase_date' => $validated['purchased_date'],
                'from_company' => $validated['from_company'],
                'purchased_quantity' => (int) $validated['purchased_quantity'],
                'price' => $validated['price'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            return response()->json(['message' => 'Purchase updated successfully', 'purchase' => $purchase], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error updating purchase: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function purchaseDelete($id)
    {
        if (! Gate::allows('asset_tracking.delete')) {
            return response()->json(['error' => 'You are not authorized to delete purchase.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $purchase = AssetPurchase::find($id);
            if (! $purchase) {
                return response()->json(['error' => 'Purchase not found.'], 404);
            }

            $asset = Asset::where('team_id', $currentTeamId)->where('id', $purchase->asset_id)->first();
            if (! $asset) {
                return response()->json(['error' => 'Purchase not authorized.'], 422);
            }

            // Validate deletion won't make total purchased < total outward
            $currentPurchasedTotal = (int) AssetPurchase::where('asset_id', $purchase->asset_id)->sum('purchased_quantity');
            $currentOutwardTotal = (int) AssetOutward::where('asset_id', $purchase->asset_id)->sum('outward_quantity');
            $newPurchasedTotal = $currentPurchasedTotal - (int) $purchase->purchased_quantity;

            if ($newPurchasedTotal < $currentOutwardTotal) {
                return response()->json(['error' => 'Error: Purchase quantity would be less than the Outward quantity.'], 422);
            }

            $purchase->delete();

            return response()->json(['message' => 'Purchase deleted successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Error deleting purchase: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function outwardStore(Request $request)
    {
        if (! Gate::allows('asset_tracking.create')) {
            return response()->json(['error' => 'You are not authorized to add outward.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $validated = $request->validate([
                'asset_id' => 'required|integer|exists:assets,id',
                'outward_type' => ['required', Rule::in(['Maintenance', 'Loaned', 'Scrap', 'Sold', 'Transferred'])],
                'outward_date' => ['required', 'date'],
                'to_company' => ['required', 'string', 'max:50'],
                'outward_quantity' => ['required', 'integer', 'min:1'],
                'approved_by' => ['nullable', 'string', 'max:50'],
                'remarks' => ['nullable', 'string'],
            ]);

            $asset = Asset::where('team_id', $currentTeamId)->where('id', $validated['asset_id'])->first();
            if (! $asset) {
                return response()->json(['error' => 'Asset not found or unauthorized.'], 422);
            }

            // Compute max allowed outward similar to Livewire: (purchased + returned) - existing outwards
            $purchasedTotal = (int) AssetPurchase::where('asset_id', $validated['asset_id'])->sum('purchased_quantity');
            $returnedTotal = (int) AssetReturn::whereIn('outward_id', AssetOutward::where('asset_id', $validated['asset_id'])->pluck('outward_id'))->sum('returned_quantity');
            $existingOutwards = (int) AssetOutward::where('asset_id', $validated['asset_id'])->sum('outward_quantity');
            $maxAllowed = max(0, ($purchasedTotal + $returnedTotal) - $existingOutwards);

            if ((int) $validated['outward_quantity'] > $maxAllowed) {
                return response()->json(['error' => 'Outward quantity exceeds available stock'], 422);
            }

            $outward = AssetOutward::create([
                'asset_id' => (int) $validated['asset_id'],
                'outward_type' => $validated['outward_type'],
                'outward_date' => $validated['outward_date'],
                'to_company' => $validated['to_company'],
                'outward_quantity' => (int) $validated['outward_quantity'],
                'approved_by' => $validated['approved_by'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            return response()->json(['message' => 'Outward saved successfully', 'outward' => $outward], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error saving outward: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function outwardUpdate(Request $request, $id)
    {
        if (! Gate::allows('asset_tracking.edit')) {
            return response()->json(['error' => 'You are not authorized to update outward.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $validated = $request->validate([
                'outward_type' => ['required', Rule::in(['Maintenance', 'Loaned', 'Scrap', 'Sold', 'Transferred'])],
                'outward_date' => ['required', 'date'],
                'to_company' => ['required', 'string', 'max:50'],
                'outward_quantity' => ['required', 'integer', 'min:1'],
                'approved_by' => ['nullable', 'string', 'max:50'],
                'remarks' => ['nullable', 'string'],
            ]);

            $outward = AssetOutward::find($id);
            if (! $outward) {
                return response()->json(['error' => 'Outward not found.'], 404);
            }

            $asset = Asset::where('team_id', $currentTeamId)->where('id', $outward->asset_id)->first();
            if (! $asset) {
                return response()->json(['error' => 'Outward not authorized.'], 422);
            }

            // Already returned for this outward
            $alreadyReturned = (int) AssetReturn::where('outward_id', $id)->sum('returned_quantity');
            if ((int) $validated['outward_quantity'] < $alreadyReturned) {
                return response()->json(['error' => 'Cannot update: outward quantity is less than already returned quantity'], 422);
            }

            // Compute max allowed excluding this outward
            $assetId = $outward->asset_id;
            $purchasedTotal = (int) AssetPurchase::where('asset_id', $assetId)->sum('purchased_quantity');
            $returnedTotal = (int) AssetReturn::whereIn('outward_id', AssetOutward::where('asset_id', $assetId)->pluck('outward_id'))->sum('returned_quantity');
            $otherOutwards = (int) AssetOutward::where('asset_id', $assetId)->where('outward_id', '!=', $id)->sum('outward_quantity');
            $maxAllowed = max(0, ($purchasedTotal + $returnedTotal) - $otherOutwards);

            if ((int) $validated['outward_quantity'] > $maxAllowed) {
                return response()->json(['error' => 'Outward quantity exceeds available stock'], 422);
            }

            $outward->update([
                'outward_type' => $validated['outward_type'],
                'outward_date' => $validated['outward_date'],
                'to_company' => $validated['to_company'],
                'outward_quantity' => (int) $validated['outward_quantity'],
                'approved_by' => $validated['approved_by'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            return response()->json(['message' => 'Outward updated successfully', 'outward' => $outward], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error updating outward: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function outwardDelete($id)
    {
        if (! Gate::allows('asset_tracking.delete')) {
            return response()->json(['error' => 'You are not authorized to delete outward.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $outward = AssetOutward::find($id);
            if (! $outward) {
                return response()->json(['error' => 'Outward not found.'], 404);
            }

            $asset = Asset::where('team_id', $currentTeamId)->where('id', $outward->asset_id)->first();
            if (! $asset) {
                return response()->json(['error' => 'Outward not authorized.'], 422);
            }

            // Prevent deletion if returns exist for this outward
            $returnsCount = AssetReturn::where('outward_id', $id)->count();
            if ($returnsCount > 0) {
                return response()->json(['error' => 'Cannot delete outward with returned records'], 422);
            }

            $outward->delete();

            return response()->json(['message' => 'Outward deleted successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Error deleting outward: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function returnStore(Request $request)
    {
        if (! Gate::allows('asset_tracking.create')) {
            return response()->json(['error' => 'You are not authorized to add return.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $validated = $request->validate([
                'outward_id' => 'required|integer|exists:asset_outwards,outward_id',
                'returned_date' => ['required', 'date'],
                'returned_quantity' => ['required', 'integer', 'min:1'],
                'remarks' => ['nullable', 'string'],
            ]);

            $outward = AssetOutward::find($validated['outward_id']);
            if (! $outward) {
                return response()->json(['error' => 'Outward not found.'], 404);
            }

            $asset = Asset::where('team_id', $currentTeamId)->where('id', $outward->asset_id)->first();
            if (! $asset) {
                return response()->json(['error' => 'Outward not authorized.'], 422);
            }

            $alreadyReturned = (int) AssetReturn::where('outward_id', $outward->outward_id)->sum('returned_quantity');
            $allowed = max(0, (int) $outward->outward_quantity - $alreadyReturned);
            if ($allowed <= 0) {
                return response()->json(['error' => 'No quantity available to return.'], 422);
            }

            if ((int) $validated['returned_quantity'] > $allowed) {
                return response()->json(['error' => 'Returned quantity exceeds allowed amount.'], 422);
            }

            $ret = AssetReturn::create([
                'outward_id' => (int) $validated['outward_id'],
                'returned_date' => $validated['returned_date'],
                'returned_quantity' => (int) $validated['returned_quantity'],
                'remarks' => $validated['remarks'] ?? null,
            ]);

            return response()->json(['message' => 'Return saved successfully', 'return' => $ret], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error saving return: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function returnUpdate(Request $request, $id)
    {
        if (! Gate::allows('asset_tracking.edit')) {
            return response()->json(['error' => 'You are not authorized to update return.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $validated = $request->validate([
                'returned_date' => ['required', 'date'],
                'returned_quantity' => ['required', 'integer', 'min:1'],
                'remarks' => ['nullable', 'string'],
            ]);

            $ret = AssetReturn::find($id);
            if (! $ret) {
                return response()->json(['error' => 'Return not found.'], 404);
            }

            $outward = AssetOutward::find($ret->outward_id);
            if (! $outward) {
                return response()->json(['error' => 'Outward not found for this return.'], 422);
            }

            $asset = Asset::where('team_id', $currentTeamId)->where('id', $outward->asset_id)->first();
            if (! $asset) {
                return response()->json(['error' => 'Return not authorized.'], 422);
            }

            // compute allowed excluding this return
            $otherReturned = (int) AssetReturn::where('outward_id', $outward->outward_id)->where('return_id', '!=', $id)->sum('returned_quantity');
            $allowed = max(0, (int) $outward->outward_quantity - $otherReturned);

            if ((int) $validated['returned_quantity'] > $allowed) {
                return response()->json(['error' => 'Returned quantity exceeds allowed amount.'], 422);
            }

            $ret->update([
                'returned_date' => $validated['returned_date'],
                'returned_quantity' => (int) $validated['returned_quantity'],
                'remarks' => $validated['remarks'] ?? null,
            ]);

            return response()->json(['message' => 'Return updated successfully', 'return' => $ret], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error updating return: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function returnDelete($id)
    {
        if (! Gate::allows('asset_tracking.delete')) {
            return response()->json(['error' => 'You are not authorized to delete return.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        try {
            $ret = AssetReturn::find($id);
            if (! $ret) {
                return response()->json(['error' => 'Return not found.'], 404);
            }

            $outward = AssetOutward::find($ret->outward_id);
            if (! $outward) {
                return response()->json(['error' => 'Outward not found for this return.'], 422);
            }

            $asset = Asset::where('team_id', $currentTeamId)->where('id', $outward->asset_id)->first();
            if (! $asset) {
                return response()->json(['error' => 'Return not authorized.'], 422);
            }

            $ret->delete();

            return response()->json(['message' => 'Return deleted successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Error deleting return: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
