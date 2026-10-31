<?php

namespace App\Support\Pulse;

use InvalidArgumentException;

/**
 * Validates and derives circle/polygon geofence geometry for API storage.
 */
class GeofenceBoundary
{
    public const TYPE_CIRCLE = 'circle';

    public const TYPE_POLYGON = 'polygon';

    public const MIN_CIRCLE_RADIUS_METERS = 10;

    public const MIN_POLYGON_POINTS = 3;

    public const MAX_POLYGON_POINTS = 32;

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     boundary_type: string,
     *     latitude: float|null,
     *     longitude: float|null,
     *     radius: int|null,
     *     polygon_coordinates: array<int, array{lat: float, lng: float}>|null
     * }
     */
    public static function normalize(array $input): array
    {
        $boundaryType = $input['boundary_type'] ?? self::TYPE_CIRCLE;

        if ($boundaryType === self::TYPE_CIRCLE) {
            $latitude = isset($input['latitude']) ? (float) $input['latitude'] : null;
            $longitude = isset($input['longitude']) ? (float) $input['longitude'] : null;
            $radius = isset($input['radius']) ? (int) $input['radius'] : null;

            if ($latitude === null || $longitude === null || $radius === null) {
                throw new InvalidArgumentException('Circle geofences need latitude, longitude, and radius.');
            }
            if ($radius < self::MIN_CIRCLE_RADIUS_METERS) {
                throw new InvalidArgumentException('Circle radius must be at least '.self::MIN_CIRCLE_RADIUS_METERS.' meters.');
            }

            return [
                'boundary_type' => self::TYPE_CIRCLE,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius' => $radius,
                'polygon_coordinates' => null,
            ];
        }

        if ($boundaryType !== self::TYPE_POLYGON) {
            throw new InvalidArgumentException('Boundary type must be circle or polygon.');
        }

        $points = self::normalizePolygonPoints($input['polygon_coordinates'] ?? []);
        $centroid = self::centroid($points);
        $radius = (int) ceil(self::boundingRadiusMeters($centroid, $points));

        return [
            'boundary_type' => self::TYPE_POLYGON,
            'latitude' => $centroid['lat'],
            'longitude' => $centroid['lng'],
            'radius' => max($radius, self::MIN_CIRCLE_RADIUS_METERS),
            'polygon_coordinates' => $points,
        ];
    }

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    public static function normalizePolygonPoints(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('Polygon geofences need a list of coordinates.');
        }

        $points = [];
        foreach ($raw as $point) {
            if (! is_array($point)) {
                throw new InvalidArgumentException('Each polygon point must have lat and lng.');
            }
            $lat = $point['lat'] ?? $point['latitude'] ?? null;
            $lng = $point['lng'] ?? $point['longitude'] ?? null;
            if ($lat === null || $lng === null) {
                throw new InvalidArgumentException('Each polygon point must have lat and lng.');
            }
            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw new InvalidArgumentException('Polygon coordinates are out of range.');
            }
            if ($points !== [] && self::samePoint($points[array_key_last($points)], ['lat' => $lat, 'lng' => $lng])) {
                continue;
            }
            $points[] = ['lat' => $lat, 'lng' => $lng];
        }

        if ($points !== [] && self::samePoint($points[0], $points[array_key_last($points)]) && count($points) > 1) {
            array_pop($points);
        }

        $count = count($points);
        if ($count < self::MIN_POLYGON_POINTS) {
            throw new InvalidArgumentException('Polygon geofences need at least '.self::MIN_POLYGON_POINTS.' points.');
        }
        if ($count > self::MAX_POLYGON_POINTS) {
            throw new InvalidArgumentException('Polygon geofences cannot have more than '.self::MAX_POLYGON_POINTS.' points.');
        }
        if (self::isSelfIntersecting($points)) {
            throw new InvalidArgumentException('Polygon boundary crosses itself. Adjust the points and try again.');
        }

        return $points;
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $points
     * @return array{lat: float, lng: float}
     */
    public static function centroid(array $points): array
    {
        $lat = 0.0;
        $lng = 0.0;
        foreach ($points as $point) {
            $lat += $point['lat'];
            $lng += $point['lng'];
        }
        $count = count($points);

        return [
            'lat' => $lat / $count,
            'lng' => $lng / $count,
        ];
    }

    /**
     * @param  array{lat: float, lng: float}  $centroid
     * @param  array<int, array{lat: float, lng: float}>  $points
     */
    public static function boundingRadiusMeters(array $centroid, array $points): float
    {
        $max = 0.0;
        foreach ($points as $point) {
            $max = max($max, self::haversineMeters(
                $centroid['lat'],
                $centroid['lng'],
                $point['lat'],
                $point['lng']
            ));
        }

        return $max;
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $points
     */
    public static function isSelfIntersecting(array $points): bool
    {
        $n = count($points);
        if ($n < 4) {
            return false;
        }

        $ring = $points;
        $ring[] = $points[0];

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $adjacent = $j === $i + 1 || ($i === 0 && $j === $n - 1);
                if ($adjacent) {
                    continue;
                }
                if (self::segmentsIntersect($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{lat: float, lng: float}  $a
     * @param  array{lat: float, lng: float}  $b
     */
    private static function samePoint(array $a, array $b): bool
    {
        return abs($a['lat'] - $b['lat']) < 0.0000001 && abs($a['lng'] - $b['lng']) < 0.0000001;
    }

    /**
     * @param  array{lat: float, lng: float}  $p1
     * @param  array{lat: float, lng: float}  $p2
     * @param  array{lat: float, lng: float}  $q1
     * @param  array{lat: float, lng: float}  $q2
     */
    private static function segmentsIntersect(array $p1, array $p2, array $q1, array $q2): bool
    {
        $o1 = self::orientation($p1, $p2, $q1);
        $o2 = self::orientation($p1, $p2, $q2);
        $o3 = self::orientation($q1, $q2, $p1);
        $o4 = self::orientation($q1, $q2, $p2);

        return $o1 !== $o2 && $o3 !== $o4;
    }

    /**
     * @param  array{lat: float, lng: float}  $p
     * @param  array{lat: float, lng: float}  $q
     * @param  array{lat: float, lng: float}  $r
     */
    private static function orientation(array $p, array $q, array $r): int
    {
        $value = ($q['lng'] - $p['lng']) * ($r['lat'] - $q['lat'])
            - ($q['lat'] - $p['lat']) * ($r['lng'] - $q['lng']);

        if (abs($value) < 1e-12) {
            return 0;
        }

        return $value > 0 ? 1 : 2;
    }

    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }
}
