<?php
declare(strict_types=1);
// Run in the hosting owner's authenticated shell. Send a locally generated password hash via stdin.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../admin/core.php';
[$directory] = openStore();
$hash = trim(stream_get_contents(STDIN, 512));
if (password_get_info($hash)['algo'] === null) {
    fwrite(STDERR, "Expected a valid PHP password hash on stdin.\n");
    exit(1);
}
state($directory, static function (array &$data) use ($hash): void {
    $data['password_hash'] = $hash;
    $data['auth_epoch'] = ($data['auth_epoch'] ?? 0) + 1;
    $data['attempts'] = [];
    $data['version']++;
});
echo "Password reset. Existing editor sessions were revoked.\n";
