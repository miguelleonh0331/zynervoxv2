<?php
/* Plantilla de configuracion. Copiar a config/db.php y completar con valores reales.
 * NUNCA versionar config/db.php: contiene credenciales. Estructura identica al
 * original del proyecto host (mismas claves: host, database, user, password, charset). */
return [
    'host'     => 'localhost',   // host de MariaDB/MySQL
    'database' => 'CAMBIAR_DB',  // nombre de la base de datos de prueba/integracion
    'user'     => 'CAMBIAR_USER',// usuario de la base
    'password' => 'CAMBIAR_PASS',// contrasena de la base
    'charset'  => 'utf8mb4',     // charset (utf8mb4 recomendado)
];
