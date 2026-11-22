<?php
declare(strict_types=1);

/** Public service-area catalogue used by the structured address dropdowns. */
function api_addresses(): void
{
    $catalog = address_catalog();
    $cities = array_map(static function (array $city): array {
        return [
            'name' => $city['name'],
            'province' => $city['province'],
            'region' => $city['region'],
            'region_code' => $city['region_code'],
            'postal_code' => $city['postal_code'],
            'latitude' => $city['latitude'],
            'longitude' => $city['longitude'],
            'barangays' => $city['barangays'],
        ];
    }, array_values($catalog));
    usort($cities, static fn($a, $b) => strcmp($a['name'], $b['name']));
    json_response([
        'regions' => address_regions(),
        'provinces' => address_provinces(),
        'cities' => $cities,
    ]);
}
