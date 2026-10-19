<?php
// Administrative provisioning is deliberately separate from application startup.
declare(strict_types=1);
$root = dirname(__DIR__);
$connection = json_decode(file_get_contents($root.'/private/config/aiven-admin.json'), true, flags: JSON_THROW_ON_ERROR);
$secrets = json_decode(file_get_contents($root.'/private/config/airbersih-secrets.json'), true, flags: JSON_THROW_ON_ERROR);
$p = $connection['service_uri_params'];
$ca = str_replace('\\', '/', $root.'/private/config/aiven-ca.pem');
putenv('PGSSLROOTCERT='.$ca);
$connect = static function (string $database) use ($p, $ca): PDO {
    return new PDO("pgsql:host={$p['host']};port={$p['port']};dbname={$database};sslmode=verify-full;connect_timeout=15", $p['user'], $p['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};
try {
    $db = $connect('defaultdb');
    $roles = ['airbersih_dev_migrator' => 1, 'airbersih_dev_app' => 3, 'airbersih_test_runner' => 3];
    foreach ($roles as $role => $limit) {
        $q = $db->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $q->execute([$role]);
        if (! $q->fetchColumn()) {
            $db->exec('CREATE ROLE '.$role.' LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE CONNECTION LIMIT '.$limit.' PASSWORD '.$db->quote($secrets[$role]));
        }
    }
    foreach (['airbersih_dev' => 'airbersih_dev_migrator', 'airbersih_test' => 'airbersih_test_runner'] as $name => $owner) {
        $q = $db->prepare('SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = ?');
        $q->execute([$name]);
        $existing = $q->fetchColumn();
        if ($existing && $existing !== $owner) {
            throw new RuntimeException('Target ownership mismatch.');
        }
        if (! $existing) {
            $db->exec('CREATE DATABASE '.$name.' OWNER '.$owner);
        }
        $db->exec('REVOKE ALL ON DATABASE '.$name.' FROM PUBLIC');
    }
    $db->exec('GRANT CONNECT ON DATABASE airbersih_dev TO airbersih_dev_app');
    $db = null;
    $dev = $connect('airbersih_dev');
    $dev->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
    $dev->exec('GRANT USAGE ON SCHEMA public TO airbersih_dev_app');
    $dev->exec('ALTER DEFAULT PRIVILEGES FOR ROLE airbersih_dev_migrator IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO airbersih_dev_app');
    $dev->exec('ALTER DEFAULT PRIVILEGES FOR ROLE airbersih_dev_migrator IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO airbersih_dev_app');
    if ($dev->query("SELECT to_regclass('public.audits')")->fetchColumn()) {
        $dev->exec('REVOKE UPDATE, DELETE ON audits FROM airbersih_dev_app');
    }
    $dev = null;
    $test = $connect('airbersih_test');
    $test->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
    echo "Dedicated databases and restricted roles ready; TLS verification enabled.\n";
} catch (Throwable $e) {
    // Do not emit DSNs, SQL containing role passwords, or administrative credentials.
    fwrite(STDERR, 'Provisioning failed: '.$e::class.' ['.$e->getCode()."]\n");
    exit(1);
}
