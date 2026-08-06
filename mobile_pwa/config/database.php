<?php

/**
 * Conexao opcional com SQL Server (variante PWA).
 *
 * [SEGURANCA] Este arquivo continha usuario 'sa' e a senha de um servidor da rede interna, em texto plano — a mesma credencial que estava em
 * config/database.php. Ver o comentario daquele arquivo.
 *
 * Para ligar, defina antes de subir o PHP:
 *   FESTIVAL_SQLSRV_SERVER, FESTIVAL_SQLSRV_DATABASE,
 *   FESTIVAL_SQLSRV_USER,   FESTIVAL_SQLSRV_PASSWORD
 */

$servidor = getenv('FESTIVAL_SQLSRV_SERVER') ?: '';
$usuario  = getenv('FESTIVAL_SQLSRV_USER') ?: '';
$senha    = getenv('FESTIVAL_SQLSRV_PASSWORD') ?: '';

if ($servidor === '' || $usuario === '' || $senha === '') {
    return ['driver' => 'none'];
}

return [
    'driver'   => 'sqlsrv',
    'server'   => $servidor,
    'database' => getenv('FESTIVAL_SQLSRV_DATABASE') ?: 'FESTIVAL_CALOUROS',
    'username' => $usuario,
    'password' => $senha,
    'options'  => [
        'TrustServerCertificate' => true,
    ],
];
