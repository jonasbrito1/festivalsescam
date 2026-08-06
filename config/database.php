<?php

/**
 * Conexao opcional com SQL Server.
 *
 * [SEGURANCA] Este arquivo continha usuario 'sa' e a senha de um servidor da rede interna, em texto plano. Credencial em codigo-fonte vaza junto com
 * qualquer copia do projeto, e aquela senha vale para o servidor da rede
 * interna — nao apenas para este sistema.
 *
 * As credenciais agora vem de variaveis de ambiente. Se nenhuma estiver
 * definida (o caso em producao, onde o sistema usa data/db.json), a
 * integracao simplesmente fica desligada.
 *
 * Para ligar, defina antes de subir o PHP:
 *   FESTIVAL_SQLSRV_SERVER, FESTIVAL_SQLSRV_DATABASE,
 *   FESTIVAL_SQLSRV_USER,   FESTIVAL_SQLSRV_PASSWORD
 */

$servidor = getenv('FESTIVAL_SQLSRV_SERVER') ?: '';
$usuario  = getenv('FESTIVAL_SQLSRV_USER') ?: '';
$senha    = getenv('FESTIVAL_SQLSRV_PASSWORD') ?: '';

if ($servidor === '' || $usuario === '' || $senha === '') {
    // Sem configuracao: o sistema opera com o armazenamento local (db.json).
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
