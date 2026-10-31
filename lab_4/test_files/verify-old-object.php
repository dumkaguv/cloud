<?php
// Temporary, reversible test on the disposable capsule #7 only.
// Run on the app-server: php verify-old-object.php old|restore
require '/var/www/timecapsule/src/bootstrap.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['old', 'restore'], true)) {
    fwrite(STDERR, "Usage: php verify-old-object.php old|restore\n");
    exit(2);
}

$newKey = '4e07678c3c46a9460b31882611f04bea.png';
$oldKey = '3e0a94bdae459ce76bc942fd6fc12a62.webp';
$from = $mode === 'old' ? $newKey : $oldKey;
$to = $mode === 'old' ? $oldKey : $newKey;
$name = $mode === 'old' ? 'old-lab2.webp' : 'architecture.png';
$mime = $mode === 'old' ? 'image/webp' : 'image/png';

$pdo = db();
$pdo->beginTransaction();
try {
    $query = $pdo->prepare('SELECT c.file_path, c.title, u.email FROM capsules c JOIN users u ON u.id = c.user_id WHERE c.id = 7 FOR UPDATE');
    $query->execute();
    $row = $query->fetch();
    if (!$row || $row['title'] !== 'ЛР4 — проверка подписанного URL PNG' || $row['email'] !== 'lab4-verify-20261031@example.invalid' || $row['file_path'] !== $from) {
        throw new RuntimeException('Capsule #7 does not match the expected disposable test state.');
    }

    $update = $pdo->prepare('UPDATE capsules SET file_path = ?, file_name = ?, file_mime = ? WHERE id = 7');
    $update->execute([$to, $name, $mime]);
    $pdo->commit();
    echo "Capsule #7 attachment switched to $to\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
