<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Support\Pulse\GeofencingModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

class GeofenceSearchController extends Controller
{
    public function __invoke(Request $request, $team): JsonResponse
    {
        abort_unless(Gate::allows('geofence_locations.view'), 403);

        $current = app()->has('current.team') ? app('current.team') : $request->user()?->currentTeam;
        abort_unless($current && (int) $current->id === (int) $team, 403, 'Factory mismatch.');
        abort_unless(GeofencingModule::isActive($current), 403, 'Geofencing is not enabled for this factory.');

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'viewbox' => ['nullable', 'string', 'max:80'],
        ]);

        $query = trim($validated['q']);
        $viewbox = $this->sanitizeViewbox($validated['viewbox'] ?? null);
        $cacheKey = 'geofence_search_'.md5($query.'|'.($viewbox ?? ''));

        $results = Cache::remember($cacheKey, 3600, function () use ($query, $viewbox) {
            return $this->searchNominatim($query, $viewbox);
        });

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    /**
     * @return list<array{label: string, lat: float, lng: float}>
     */
    private function searchNominatim(string $query, ?string $viewbox): array
    {
        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => 5,
            'countrycodes' => 'in',
        ];
        if ($viewbox !== null) {
            $params['viewbox'] = $viewbox;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => 'CoiioT Geofence Editor / 1.0'])
                ->get('https://nominatim.openstreetmap.org/search', $params);
        } catch (\Throwable $e) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        $out = [];
        foreach ($response->json() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lat = isset($row['lat']) ? (float) $row['lat'] : null;
            $lng = isset($row['lon']) ? (float) $row['lon'] : null;
            $label = trim((string) ($row['display_name'] ?? ''));
            if ($lat === null || $lng === null || $label === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        return $out;
    }

    private function sanitizeViewbox(?string $viewbox): ?string
    {
        if ($viewbox === null || $viewbox === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $viewbox));
        if (count($parts) !== 4) {
            return null;
        }

        foreach ($parts as $part) {
            if (! is_numeric($part)) {
                return null;
            }
        }

        return implode(',', $parts);
    }
}
