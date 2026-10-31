<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\PithLocation;

class PithLoadsController extends Controller
{
    public function index($locationId)
    {
        // Fetch the location by ID
        $location = PithLocation::findOrFail($locationId);
        $locationName = $location->name;

        return view('pithloads', compact('locationId', 'locationName'));
    }
}
