<?php
declare(strict_types=1);

/**
 * Service-area address catalogue.
 *
 * The customer and branch forms use these lists to build structured addresses
 * with dropdowns instead of free text. Keep the city/barangay names in sync
 * with what the business actually serves. A city must belong to a province and
 * region so the API can backfill those fields from the dropdown selection.
 */
return [
    'regions' => [
        [
            'code' => 'BARMM',
            'name' => 'Bangsamoro Autonomous Region in Muslim Mindanao',
            'short' => 'BARMM',
        ],
        [
            'code' => 'IX',
            'name' => 'Zamboanga Peninsula',
            'short' => 'Region IX',
        ],
    ],
    'provinces' => [
        [
            'code' => 'SULU',
            'name' => 'Sulu',
            'region' => 'BARMM',
        ],
        [
            'code' => 'ZDS',
            'name' => 'Zamboanga del Sur',
            'region' => 'IX',
        ],
    ],
    'cities' => [
        [
            'name' => 'Jolo',
            'province' => 'Sulu',
            'region' => 'Bangsamoro Autonomous Region in Muslim Mindanao',
            'region_code' => 'BARMM',
            'postal_code' => '7400',
            'latitude' => 6.0522,
            'longitude' => 121.0022,
            'barangays' => [
                'Alat',
                'Asturias',
                'Bus-Bus',
                'Chinese Pier',
                'San Raymundo',
                'Takut Takut',
                'Tulay',
                'Walled City (Poblacion)',
            ],
        ],
        [
            'name' => 'Zamboanga City',
            'province' => 'Zamboanga del Sur',
            'region' => 'Zamboanga Peninsula',
            'region_code' => 'IX',
            'postal_code' => '7000',
            'latitude' => 6.9214,
            'longitude' => 122.0790,
            'barangays' => [
                'Arena Blanco', 'Ayala', 'Baliwasan', 'Baluno', 'Boalan', 'Bolong',
                'Buenavista', 'Bunguiao', 'Busay', 'Cabaluay', 'Cabatangan', 'Cacao',
                'Calabasa', 'Calarian', 'Camino Nuevo', 'Campo Islam', 'Canelar',
                'Capisan', 'Cawit', 'Culianan', 'Curuan', 'Dita', 'Divisoria',
                'Dulian', 'Guisao', 'Guiwan', 'Kasanyangan', 'La Paz', 'Labuan',
                'Lamisahan', 'Landang Gua', 'Landang Laum', 'Lanzones', 'Lapakan',
                'Latuan', 'Licomo', 'Limaong', 'Limpapa', 'Lubigan', 'Lumayang',
                'Lumbangan', 'Lunzuran', 'Maasin', 'Malagutay', 'Mampang',
                'Manalipa', 'Mangusu', 'Manicahan', 'Mariki', 'Mercedes', 'Muti',
                'Pamucutan', 'Pangapuyan', 'Panubigan', 'Pasilmanta', 'Pasobolong',
                'Pasonanca', 'Patalon', 'Putik', 'Quiniput', 'Recodo', 'Rio Hondo',
                'Salaan', 'San Jose Cawa-Cawa', 'San Jose Gusu', 'San Ramon',
                'San Roque', 'Sangali', 'Santa Barbara', 'Santa Catalina',
                'Santa Maria', 'Santo Niño', 'Sibulao', 'Sinubung', 'Sinunoc',
                'Tagasilay', 'Taguiti', 'Talabaan', 'Talisayan', 'Talon-Talon',
                'Taluksangay', 'Tetuan', 'Tictapul', 'Tigbalabag', 'Tigtabon',
                'Tolosa', 'Tugbungan', 'Tulungatung', 'Tumaga', 'Tumalutab',
                'Tumitus', 'Victoria', 'Vitali', 'Zambowood',
                'Zone I (Poblacion)', 'Zone II (Poblacion)',
                'Zone III (Poblacion)', 'Zone IV (Poblacion)',
            ],
        ],
    ],
];
