<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-v4mpg-table-deploy-service.php';

use AGSyncBridge\V4MPG_Table_Deploy_Service;

$reflection = new ReflectionClass(V4MPG_Table_Deploy_Service::class);
$service = $reflection->newInstanceWithoutConstructor();
$validator = $reflection->getMethod('validate_release');
$hash = hash('sha256', 'final-native-dataset');
$targets = array(array('dataset_id' => 'dataset-a', 'candidate' => array('dataset_sha256' => $hash, 'changed_cell_count' => 17)));
$release = array(
    'source_type' => 'current-native-runtime',
    'release_id' => 'native-release-001',
    'activation_receipt_sha256' => hash('sha256', 'activation-proof'),
    'native_manifest_sha256' => hash('sha256', 'native-manifest'),
    'candidate_summary_sha256' => hash('sha256', 'candidate-summary'),
    'source_preimages' => array(array('source_id' => 'reviewed-native-stage', 'sha256' => hash('sha256', 'source-preimage'))),
    'datasets' => array(array('dataset_id' => 'dataset-a', 'final_dataset_sha256' => $hash, 'changed_cell_count' => 17)),
);

function reject_native(callable $call, string $message): void {
    try {
        $call();
    } catch (RuntimeException $error) {
        if (strpos($error->getMessage(), $message) === false) {
            throw new RuntimeException('Unexpected rejection: ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected native evidence rejection: ' . $message);
}

if ($validator->invoke($service, $release, $targets) !== $release) {
    throw new RuntimeException('Native provenance was not retained exactly.');
}
$bad = $release;
$bad['catalog_generation'] = 'fabricated-catalog';
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'Unexpected or missing');
$bad = $release;
$bad['source_type'] = 'arbitrary-source';
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'identity');
$bad = $release;
$bad['source_preimages'] = array();
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'missing or unbounded');
$bad = $release;
$bad['source_preimages'][] = $bad['source_preimages'][0];
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'duplicate');
$bad = $release;
$bad['source_preimages'][0]['source_id'] = 'C:/private/source.json';
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'identity');
$bad = $release;
$bad['native_manifest_sha256'] = 'not-a-hash';
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'SHA');
$bad = $release;
$bad['datasets'][0]['final_dataset_sha256'] = hash('sha256', 'tampered-dataset');
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'not bound');
$bad = $release;
$bad['datasets'][0]['changed_cell_count'] = 16;
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'not bound');
$bad = $release;
$bad['datasets'][0]['dataset_id'] = 'dataset-b';
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'not bound');
$bad = $release;
$bad['datasets'][] = $bad['datasets'][0];
reject_native(fn() => $validator->invoke($service, $bad, $targets), 'target count');
$two_targets = array_merge($targets, array(array('dataset_id' => 'dataset-b', 'candidate' => $targets[0]['candidate'])));
reject_native(fn() => $validator->invoke($service, $bad, $two_targets), 'duplicate');

// Catalog releases retain their original schema and dataset binding.
$catalog = array('release_id' => 'catalog-release-001', 'activation_receipt_sha256' => $release['activation_receipt_sha256'],
    'catalog_generation' => 'catalog-generation-001', 'catalog_sha256' => hash('sha256', 'catalog'),
    'candidate_summary_sha256' => $release['candidate_summary_sha256'],
    'authoring_runs' => array(array('run_id' => 'run-001', 'receipt_sha256' => hash('sha256', 'authoring'), 'patch_manifest_sha256' => hash('sha256', 'patch'))),
    'datasets' => $release['datasets']);
if ($validator->invoke($service, $catalog, $targets) !== $catalog) {
    throw new RuntimeException('Existing catalog release changed.');
}

echo "v4mpg native release evidence: ok\n";
