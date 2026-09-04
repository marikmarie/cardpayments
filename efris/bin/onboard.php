<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require dirname(__DIR__, 2) . '/bootstrap.php';

[$script, $apiKeyId, $tenantId, $name, $tin, $branchCode, $uraBranchId, $deviceNumber] = array_pad($argv, 8, '');
if ($deviceNumber === '') {
    fwrite(STDERR, "Usage: php efris/bin/onboard.php <api-key-id> <tenant-id> <name> <tin> <branch-code> <ura-branch-id> <device-number>\n");
    exit(1);
}

try {
    $tenant = (new \Efris\Gateway(new \App\Store()))->onboard([
        'api_key_id' => $apiKeyId,
        'tenant_id' => $tenantId,
        'name' => $name,
        'tin' => $tin,
        'branch_code' => $branchCode,
        'ura_branch_id' => $uraBranchId,
        'device_number' => $deviceNumber,
    ]);
    echo json_encode(['data' => $tenant], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
