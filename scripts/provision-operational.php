<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = json_decode(file_get_contents($root.'/private/config/aiven-admin.json'), true, flags: JSON_THROW_ON_ERROR);
$p = $config['service_uri_params'];
putenv('PGSSLROOTCERT='.str_replace('\\', '/', $root.'/private/config/aiven-ca.pem'));
$connect = static fn ($name) => new PDO("pgsql:host={$p['host']};port={$p['port']};dbname={$name};sslmode=verify-full;connect_timeout=15", $p['user'], $p['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $db = $connect('defaultdb');
    $size = (int) $db->query('SELECT SUM(pg_database_size(oid)) FROM pg_database')->fetchColumn();
    $clients = (int) $db->query("SELECT count(*) FROM pg_stat_activity WHERE backend_type='client backend'")->fetchColumn();
    $max = (int) $db->query('SHOW max_connections')->fetchColumn();
    $evidence = ['checked_utc' => gmdate('c'), 'database_mb' => round($size / 1048576, 2), 'clients' => $clients, 'max_connections' => $max, 'target' => 'airbersih_operasional', 'applied' => false];
    if (in_array('--apply', $argv, true)) {
        if ($size > 512 * 1048576 || $clients + 4 >= $max) {
            throw new RuntimeException('Insufficient capacity headroom.');
        }
        $secretPath = $root.'/private/config/operational-secrets.json';
        $secrets = is_file($secretPath) ? json_decode(file_get_contents($secretPath), true, flags: JSON_THROW_ON_ERROR) : [];
        foreach (['airbersih_ops_migrator' => 1, 'airbersih_ops_app' => 3] as $role => $limit) {
            $secrets[$role] ??= bin2hex(random_bytes(24));
            file_put_contents($secretPath, json_encode($secrets, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $q = $db->prepare('SELECT 1 FROM pg_roles WHERE rolname=?');
            $q->execute([$role]);
            if (! $q->fetchColumn()) {
                $db->exec('CREATE ROLE '.$role.' LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE CONNECTION LIMIT '.$limit.' PASSWORD '.$db->quote($secrets[$role]));
            }
        }
        $owner = $db->query("SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname='airbersih_operasional'")->fetchColumn();
        if ($owner && $owner !== 'airbersih_ops_migrator') {
            throw new RuntimeException('Operational owner mismatch.');
        }
        if (! $owner) {
            $db->exec('CREATE DATABASE airbersih_operasional OWNER airbersih_ops_migrator');
        }
        $db->exec('REVOKE ALL ON DATABASE airbersih_operasional FROM PUBLIC');
        $db->exec('GRANT CONNECT ON DATABASE airbersih_operasional TO airbersih_ops_app');
        $db = null;
        $ops = $connect('airbersih_operasional');
        $ops->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        $ops->exec('GRANT USAGE ON SCHEMA public TO airbersih_ops_app');
        $ops->exec('ALTER DEFAULT PRIVILEGES FOR ROLE airbersih_ops_migrator IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO airbersih_ops_app');
        $ops->exec('ALTER DEFAULT PRIVILEGES FOR ROLE airbersih_ops_migrator IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO airbersih_ops_app');
        if ($ops->query("SELECT to_regclass('public.audits')")->fetchColumn()) {
            $ops->exec('REVOKE UPDATE, DELETE ON audits FROM airbersih_ops_app');
        }
        if ($ops->query("SELECT to_regclass('public.recovery_copies')")->fetchColumn()) {
            $ops->exec('REVOKE UPDATE, DELETE ON recovery_copies, recovery_chunks FROM airbersih_ops_app');
        }
        $baseEnv = file_get_contents($root.'/web/.env');
        $key = 'base64:'.base64_encode(random_bytes(32));
        foreach (['operational' => 'airbersih_ops_app', 'operational-migration' => 'airbersih_ops_migrator'] as $environment => $role) {
            $dest = $root.'/web/.env.'.$environment;
            if (is_file($dest)) {
                continue;
            }
            $env = $baseEnv;
            foreach (['APP_ENV' => $environment, 'APP_DEBUG' => 'false', 'APP_NAME' => '"Air Bersih RT05"', 'APP_KEY' => $key, 'DB_DATABASE' => 'airbersih_operasional', 'DB_USERNAME' => $role, 'DB_PASSWORD' => $secrets[$role], 'FILESYSTEM_DISK' => 'cloud'] as $name => $value) {
                $pattern = '/^'.preg_quote($name, '/').'=.*$/m';
                $env = preg_match($pattern, $env) ? preg_replace($pattern, $name.'='.$value, $env) : $env."\n".$name.'='.$value;
            }
            $env .= "\nAIR_MODE=operational\nAIR_DOCUMENTS_DISK=cloud\nAIR_INITIAL_ADMIN_EMAIL=\nCLOUD_ACCESS_KEY_ID=\nCLOUD_SECRET_ACCESS_KEY=\nCLOUD_BUCKET=airbersih-rt05-private\nCLOUD_ENDPOINT=https://vbxlskqqkvvaconrftqs.storage.supabase.co/storage/v1/s3\n";
            file_put_contents($dest, $env);
        }
        $evidence['applied'] = true;
    }
    file_put_contents($root.'/docs/evidence/operational-database.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Operational provisioning failed: '.$e::class.' ['.$e->getCode()."]\n");
    exit(1);
}
