<?php
/**
 * Editorial contract for the clinic galleries and the scoped equipment section.
 *
 * This runs without WordPress. It deliberately validates source data and policy
 * boundaries, while browser acceptance validates the actual staging runtime.
 */

declare(strict_types=1);

$root          = dirname(__DIR__, 2);
$gbp_file      = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-gbp-local.php';
$hub_file      = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-clinics-hub.php';
$registry_file = $root . '/wp-content/themes/nuvanx-medical/inc/data/clinic-asset-registry.json';

$fail = static function (string $reason): never {
    fwrite(STDERR, "CLINICS_HUB_EQUIPMENT_CONTRACT=FAIL reason={$reason}\n");
    exit(1);
};

foreach (array($gbp_file, $hub_file, $registry_file) as $file) {
    if (!is_readable($file)) {
        $fail('required_file_unreadable:' . basename($file));
    }
}

$gbp      = (string) file_get_contents($gbp_file);
$hub      = (string) file_get_contents($hub_file);
$registry = json_decode((string) file_get_contents($registry_file), true);
if (!is_array($registry)) {
    $fail('registry_invalid_json');
}

$gallery_paths = array(
    'goya' => array(
        '2026/03/nuvanx-medicina-estetica1.webp',
        '2026/06/nvx-fachada-goya-900.webp',
        '2026/07/gosia-1.webp',
        '2026/07/WhatsApp-Image-2026-07-04-at-1.39.33-PM.webp',
    ),
    'chamberi' => array(
        '2026/03/nuvanx-medicina-estetica7.webp',
        '2026/06/nvx-fachada-chamberi-final-760.webp',
        '2026/06/Sala-Nuvanx.webp',
        '2025/04/despacho-nuvanx.webp',
    ),
);
$equipment_paths = array(
    '2026/08/endolift-lasemar-1500-eufoton.webp',
    '2026/08/BTL-Exion-Mobile-Version-1024x956-1.png',
    '2026/08/Endolift-ISO9001-Laser.webp',
    '2026/08/SmartLipo-for-Laserlipolysis-DEKA-1.png',
    '2026/08/ipl-exilite-luz-pulsada.webp',
    '2026/08/Emfusion-btl-lentigo-aranitas-vasculares-punto-de-rubi-marcas-de-acne.png',
    '2026/08/SMARTXIDE-DOT_EQUIPO-TOUCH-DEKA-LASER-CO2-FRACCIONAL.png',
);

$map_start = strpos($gbp, 'function nvx_clinic_editorial_photo_map');
$map_end   = strpos($gbp, 'function nvx_clinic_landing_photos');
if (false === $map_start || false === $map_end || $map_end <= $map_start) {
    $fail('gallery_map_missing');
}
$map = substr($gbp, $map_start, $map_end - $map_start);
foreach ($gallery_paths as $clinic => $paths) {
    foreach ($paths as $path) {
        if (1 !== substr_count($map, $path)) {
            $fail('gallery_path_missing_or_duplicated:' . $clinic . ':' . $path);
        }
    }
}
if (substr_count($map, "'uploads_path'") !== 8) {
    $fail('gallery_path_count_not_eight');
}
foreach (array('1077', '1078', '1630', '1632') as $retired_id) {
    if (false !== strpos($map, "'id'           => {$retired_id}")) {
        $fail('retired_gallery_attachment_reintroduced:' . $retired_id);
    }
}
if (false === strpos($gbp, 'wp_getimagesize( $source_path )')) {
    $fail('gallery_intrinsic_dimensions_not_resolved');
}
if (false === strpos($gbp, "'srcset'  => \$url . ' ' . (int) \$image_size[0] . 'w'")) {
    $fail('gallery_srcset_contract_missing');
}

$catalog_start = strpos($hub, 'function nvx_clinics_hub_equipment_catalog');
$catalog_end   = strpos($hub, 'function nvx_clinics_hub_equipment_image_markup');
if (false === $catalog_start || false === $catalog_end || $catalog_end <= $catalog_start) {
    $fail('equipment_catalog_missing');
}
$catalog = substr($hub, $catalog_start, $catalog_end - $catalog_start);
if (substr_count($catalog, "'uploads_path'") !== 7) {
    $fail('equipment_path_count_not_seven');
}
if (substr_count($catalog, "'alt'") !== 7 || substr_count($catalog, "'description'") !== 7) {
    $fail('equipment_alt_or_description_count');
}
foreach ($equipment_paths as $path) {
    if (1 !== substr_count($catalog, $path)) {
        $fail('equipment_path_missing_or_duplicated:' . $path);
    }
}
if (false !== strpos($catalog, 'https://')) {
    $fail('equipment_cross_origin_source_forbidden');
}
foreach (array(
    'data-nvx-approved-equipment-section="clinic-hub-v1"',
    'NVX_APPROVED_EQUIPMENT_SECTION:clinic-hub-v1',
    'function nvx_clinics_hub_append_approved_equipment',
    "add_filter( 'the_content', 'nvx_clinics_hub_append_approved_equipment', 220 );",
) as $needle) {
    if (false === strpos($hub, $needle)) {
        $fail('equipment_scope_hook_missing');
    }
}

$override = $registry['approved_editorial_overrides'] ?? null;
if (!is_array($override) || 'operator_explicit' !== ($override['source'] ?? null)) {
    $fail('registry_override_missing');
}
foreach ($gallery_paths as $clinic => $paths) {
    $entries = $override['clinic_landing_galleries'][$clinic] ?? null;
    if (!is_array($entries) || count($entries) !== 4) {
        $fail('registry_gallery_count:' . $clinic);
    }
    $actual = array_map(static fn(array $entry): string => (string) ($entry['uploads_path'] ?? ''), $entries);
    if ($actual !== $paths) {
        $fail('registry_gallery_order_or_paths:' . $clinic);
    }
}
$equipment_override = $override['clinics_hub_equipment_section'] ?? null;
if (!is_array($equipment_override) || 'clinic-hub-v1' !== ($equipment_override['marker'] ?? null)) {
    $fail('registry_equipment_scope_missing');
}
if (($equipment_override['allowed_uploads_paths'] ?? null) !== $equipment_paths) {
    $fail('registry_equipment_paths_mismatch');
}
$prohibited = $equipment_override['prohibited_uses'] ?? array();
foreach (array('GBP', 'individual sede landing galleries', 'proof of physical availability at a specific sede', 'unverified clinical efficacy claims') as $scope) {
    if (!in_array($scope, $prohibited, true)) {
        $fail('registry_equipment_prohibition_missing');
    }
}

echo "CLINICS_HUB_EQUIPMENT_CONTRACT=PASS galleries=8 equipment=7 scope=clinic-hub-v1\n";
