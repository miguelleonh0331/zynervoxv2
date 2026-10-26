<?php
// Siembra un piso de demostracion sobre zynervox_core para que las pantallas
// de supervision tengan que pintar. TODO lo que escribe lleva marca:
//
//   · campanas cuyo nombre empieza con DEMO_PREFIJO
//   · agentes cuyo login esta en DEMO_AGENTES
//
// y "borrar" se lleva exactamente eso y nada mas. Sin la marca, en dos semanas
// nadie distingue lo sembrado de lo real y los reportes quedan contaminados.
//
// Seguridad: las campanas nacen y se quedan en STOPPED, y los telefonos salen
// del rango de documentacion 555-01xx, que no es asignable. Si alguien arranca
// el motor por error, no hay a quien llamar.
//
//   sudo -n php src/features/core/services/demo-piso.php sembrar
//   sudo -n php src/features/core/services/demo-piso.php borrar
//   sudo -n php src/features/core/services/demo-piso.php estado
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo por linea de comandos.\n"); }

const DEMO_PREFIJO = 'DEMO ';
const DEMO_AGENTES = [
    ['9001', 'Carlos Díaz Mendoza'],
    ['9002', 'Lucía Flores Ríos'],
    ['9003', 'Jorge Quispe Ayala'],
    ['9004', 'Valeria Ruiz Castro'],
    ['9005', 'Diana Paredes Soto'],
    ['9006', 'Marco Salinas Vega'],
];
const DEMO_CAMPANAS = [
    ['DEMO Crédito personal', 'ADAPTIVE', 2.4, 12],
    ['DEMO Renovación de póliza', 'PROGRESSIVE', 1.0, 6],
];
// Rango reservado para documentacion: no existe, no se puede marcar.
const DEMO_TELEFONO_BASE = '5155501';

$accion = $argv[1] ?? 'estado';
$db = new PDO('mysql:host=localhost;dbname=zynervox_core;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

/** Ids de las campanas sembradas. Unica fuente de verdad del alcance. */
function demoCampanaIds(PDO $db): array {
    $stmt = $db->prepare('SELECT campaign_id FROM v2_dialer_campaigns WHERE name LIKE ?');
    $stmt->execute([DEMO_PREFIJO . '%']);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function demoAgenteLogins(): array {
    return array_column(DEMO_AGENTES, 0);
}

function marcadores(PDO $db): array {
    $ids = demoCampanaIds($db);
    $logins = demoAgenteLogins();
    return [
        $ids ? implode(',', $ids) : 'NULL',
        "'" . implode("','", $logins) . "'",
    ];
}

function borrar(PDO $db): void {
    [$ids, $logins] = marcadores($db);
    // De las hojas al tronco: las filas hijas primero.
    $sentencias = [
        "DELETE FROM v2_dialer_call_log WHERE campaign_id IN ($ids) OR agent_user IN ($logins) OR reserved_agent_user IN ($logins) OR disposition_agent_user IN ($logins)",
        "DELETE FROM v2_dialer_agent_intervals WHERE campaign_id IN ($ids) OR agent_user IN ($logins)",
        "DELETE FROM v2_dialer_agent_pauses WHERE agent_user IN ($logins)",
        "DELETE FROM v2_dialer_agent_status WHERE agent_user IN ($logins)",
        "DELETE FROM v2_dialer_agent_campaigns WHERE campaign_id IN ($ids) OR agent_user IN ($logins)",
        "DELETE FROM v2_dialer_leads WHERE list_id IN (SELECT list_id FROM v2_dialer_lists WHERE campaign_id IN ($ids))",
        "DELETE FROM v2_dialer_dispositions WHERE campaign_id IN ($ids)",
        "DELETE FROM v2_dialer_lists WHERE campaign_id IN ($ids)",
        "DELETE FROM v2_dialer_campaigns WHERE campaign_id IN ($ids)",
        "DELETE FROM v2_core_user_campaigns WHERE origin='agents' AND campaign_id IN ($ids)",
        "DELETE FROM v2_zynervox_users WHERE user IN ($logins) AND user_level=1",
    ];
    $total = 0;
    foreach ($sentencias as $sql) $total += $db->exec($sql);
    echo "Borradas $total filas de demostracion.\n";
}

function estado(PDO $db): void {
    [$ids, $logins] = marcadores($db);
    $filas = [
        'campanas' => "SELECT COUNT(*) FROM v2_dialer_campaigns WHERE campaign_id IN ($ids)",
        'agentes' => "SELECT COUNT(*) FROM v2_zynervox_users WHERE user IN ($logins)",
        'leads' => "SELECT COUNT(*) FROM v2_dialer_leads WHERE list_id IN (SELECT list_id FROM v2_dialer_lists WHERE campaign_id IN ($ids))",
        'llamadas' => "SELECT COUNT(*) FROM v2_dialer_call_log WHERE campaign_id IN ($ids)",
        'intervalos' => "SELECT COUNT(*) FROM v2_dialer_agent_intervals WHERE campaign_id IN ($ids)",
        'pausas' => "SELECT COUNT(*) FROM v2_dialer_agent_pauses WHERE agent_user IN ($logins)",
    ];
    foreach ($filas as $titulo => $sql) printf("%-12s %d\n", $titulo, (int) $db->query($sql)->fetchColumn());
}

function sembrar(PDO $db): void {
    if (demoCampanaIds($db)) { echo "Ya hay piso sembrado. Corre 'borrar' primero.\n"; return; }
    $azar = static function (int $min, int $max): int { return random_int($min, $max); };
    $hoy = date('Y-m-d');
    $ahora = new DateTimeImmutable();
    $inicioTurno = new DateTimeImmutable($hoy . ' 08:00:00');
    if ($ahora < $inicioTurno->modify('+30 minutes')) $ahora = $inicioTurno->modify('+4 hours');

    $db->beginTransaction();
    try {
        // --- agentes ---
        $insUser = $db->prepare('INSERT INTO v2_zynervox_users (user,pass,full_name,user_level,active) VALUES (?,?,?,1,\'Y\')');
        foreach (DEMO_AGENTES as [$login, $nombre]) {
            $insUser->execute([$login, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), $nombre]);
        }

        // --- campanas, listas, leads, tipificaciones ---
        $insCamp = $db->prepare('INSERT INTO v2_dialer_campaigns (name,active,dial_method,dial_level,max_concurrent_calls,queue_name,engine_state) VALUES (?,1,?,?,?,?,\'STOPPED\')');
        $insLista = $db->prepare('INSERT INTO v2_dialer_lists (campaign_id,name,active) VALUES (?,?,1)');
        $insLead = $db->prepare('INSERT INTO v2_dialer_leads (list_id,phone_number,first_name,last_name,status,called_count) VALUES (?,?,?,?,?,?)');
        $insDisp = $db->prepare('INSERT INTO v2_dialer_dispositions (campaign_id,code,label,is_contact,is_sale,retryable,retry_delay_minutes) VALUES (?,?,?,?,?,?,?)');
        $insAsig = $db->prepare('INSERT INTO v2_dialer_agent_campaigns (agent_user,campaign_id) VALUES (?,?)');

        $tipos = [
            ['010000', 'INTERESADO', 1, 0, 1, 240],
            ['020000', 'VENTA CONFIRMADA', 1, 1, 0, 0],
            ['030000', 'NO INTERESADO', 1, 0, 0, 0],
            ['040000', 'NO CONTESTA', 0, 0, 1, 120],
            ['050000', 'VOLVER A LLAMAR', 1, 0, 1, 60],
        ];
        $nombres = ['Betty', 'Héctor', 'Rosa', 'Pedro', 'Elena', 'Raúl', 'Carmen', 'Óscar', 'Nadia', 'Julio'];
        $apellidos = ['Ramos', 'Chávez', 'Jiménez', 'Luna', 'Vargas', 'Ponce', 'Herrera', 'Aguilar'];

        $campanas = [];
        foreach (DEMO_CAMPANAS as $i => [$nombre, $metodo, $nivel, $canales]) {
            $insCamp->execute([$nombre, $metodo, $nivel, $canales, 'v2demo_' . ($i + 1)]);
            $campId = (int) $db->lastInsertId();
            $campanas[] = $campId;
            foreach ($tipos as $t) $insDisp->execute(array_merge([$campId], $t));
            $insLista->execute([$campId, strtolower(str_replace(' ', '_', substr($nombre, 5))) . '_oct']);
            $listaId = (int) $db->lastInsertId();
            $cuantos = $i === 0 ? 420 : 260;
            for ($n = 0; $n < $cuantos; $n++) {
                $insLead->execute([
                    $listaId,
                    DEMO_TELEFONO_BASE . str_pad((string) $azar(0, 99), 2, '0', STR_PAD_LEFT),
                    $nombres[$n % count($nombres)],
                    $apellidos[$n % count($apellidos)],
                    $n < $cuantos * 0.45 ? 'NEW' : 'DONE',
                    $n < $cuantos * 0.45 ? 0 : $azar(1, 3),
                ]);
            }
        }

        // Los agentes se reparten: unos en una campana, otros en las dos.
        foreach (DEMO_AGENTES as $i => [$login]) {
            $insAsig->execute([$login, $campanas[$i % 2]]);
            if ($i < 2) $insAsig->execute([$login, $campanas[($i + 1) % 2]]);
        }

        // --- la manana del piso: intervalos y pausas ---
        $insInt = $db->prepare('INSERT INTO v2_dialer_agent_intervals (campaign_id,agent_user,state,started_at,ended_at,last_seen_at,open_slot) VALUES (?,?,?,?,?,?,?)');
        $insPausa = $db->prepare('INSERT INTO v2_dialer_agent_pauses (agent_user,pause_type_id,label,started_at,ended_at,open_slot) VALUES (?,?,?,?,?,?)');
        $tiposPausa = $db->query('SELECT pause_type_id,label FROM v2_dialer_pause_types ORDER BY pause_type_id')->fetchAll(PDO::FETCH_ASSOC);

        foreach (DEMO_AGENTES as $i => [$login]) {
            $campId = $campanas[$i % 2];
            if ($i === 5) continue; // uno sin conectar: el piso nunca esta completo
            $cursor = $inicioTurno->modify('+' . $azar(0, 25) . ' minutes');
            $abierto = false;
            while ($cursor < $ahora) {
                $estado = [1 => 'ACTIVE', 2 => 'ACTIVE', 3 => 'WRAPUP', 4 => 'PAUSED'][$azar(1, 4)];
                $largo = $estado === 'ACTIVE' ? $azar(4, 22) : ($estado === 'WRAPUP' ? $azar(1, 3) : $azar(8, 25));
                $fin = $cursor->modify('+' . $largo . ' minutes');
                $ultimo = $fin > $ahora;
                if ($ultimo) { $fin = $ahora; $abierto = true; }
                $insInt->execute([
                    $campId, $login, $estado,
                    $cursor->format('Y-m-d H:i:s'),
                    $ultimo ? null : $fin->format('Y-m-d H:i:s'),
                    $fin->format('Y-m-d H:i:s'),
                    $ultimo ? $campId . ':' . $login : null,
                ]);
                if ($estado === 'PAUSED' && $tiposPausa) {
                    $tp = $tiposPausa[$azar(0, count($tiposPausa) - 1)];
                    $insPausa->execute([
                        $login, (int) $tp['pause_type_id'], $tp['label'],
                        $cursor->format('Y-m-d H:i:s'),
                        $ultimo ? null : $fin->format('Y-m-d H:i:s'),
                        $ultimo ? $login : null,
                    ]);
                }
                $cursor = $fin->modify('+1 minute');
                if ($abierto) break;
            }
        }

        // --- llamadas del dia ---
        $insLlam = $db->prepare('INSERT INTO v2_dialer_call_log
            (campaign_id,lead_id,agent_user,phone_number,originated_at,answered_at,bridged_at,ended_at,
             duration_wait,duration_talk,duration_wrapup,amd_result,termination_reason,disposition_code,
             dial_level_at_origin,channel_uniqueid,direction)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'OUT\')');
        $leadsPorCampana = [];
        foreach ($campanas as $campId) {
            $stmt = $db->prepare('SELECT lead_id,phone_number FROM v2_dialer_leads WHERE list_id IN (SELECT list_id FROM v2_dialer_lists WHERE campaign_id=?)');
            $stmt->execute([$campId]);
            $leadsPorCampana[$campId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $minutosTurno = max(1, (int) (($ahora->getTimestamp() - $inicioTurno->getTimestamp()) / 60));
        $serie = 0;
        foreach ($campanas as $ci => $campId) {
            $cuantas = $ci === 0 ? 520 : 240;
            $agentes = [];
            foreach (DEMO_AGENTES as $i => [$login]) if ($i !== 5 && $campanas[$i % 2] === $campId) $agentes[] = $login;
            for ($n = 0; $n < $cuantas; $n++) {
                $lead = $leadsPorCampana[$campId][$azar(0, count($leadsPorCampana[$campId]) - 1)];
                $inicio = $inicioTurno->modify('+' . $azar(0, $minutosTurno - 1) . ' minutes +' . $azar(0, 59) . ' seconds');
                $dado = $azar(1, 100);
                $espera = $azar(3, 14);
                if ($dado <= 38) {           // contesto una persona
                    $agente = $agentes ? $agentes[$azar(0, count($agentes) - 1)] : null;
                    $habla = $azar(35, 420); $cierre = $azar(10, 70);
                    $tip = $azar(1, 100) <= 9 ? '020000' : ($azar(1, 100) <= 40 ? '010000' : ($azar(1, 100) <= 60 ? '050000' : '030000'));
                    $insLlam->execute([$campId, (int) $lead['lead_id'], $agente, $lead['phone_number'],
                        $inicio->format('Y-m-d H:i:s'),
                        $inicio->modify('+' . $espera . ' seconds')->format('Y-m-d H:i:s'),
                        $inicio->modify('+' . ($espera + 1) . ' seconds')->format('Y-m-d H:i:s'),
                        $inicio->modify('+' . ($espera + 1 + $habla + $cierre) . ' seconds')->format('Y-m-d H:i:s'),
                        $espera, $habla, $cierre, 'HUMAN', 'COMPLETED', $tip, 2.4, 'zv2demo-' . (++$serie)]);
                } elseif ($dado <= 56) {     // contestador
                    $insLlam->execute([$campId, (int) $lead['lead_id'], null, $lead['phone_number'],
                        $inicio->format('Y-m-d H:i:s'),
                        $inicio->modify('+' . $espera . ' seconds')->format('Y-m-d H:i:s'), null,
                        $inicio->modify('+' . ($espera + 12) . ' seconds')->format('Y-m-d H:i:s'),
                        $espera, 0, 0, 'MACHINE', 'COMPLETED', null, 2.4, 'zv2demo-' . (++$serie)]);
                } elseif ($dado <= 60) {     // colgada por el motor
                    $insLlam->execute([$campId, (int) $lead['lead_id'], null, $lead['phone_number'],
                        $inicio->format('Y-m-d H:i:s'),
                        $inicio->modify('+' . $espera . ' seconds')->format('Y-m-d H:i:s'), null,
                        $inicio->modify('+' . ($espera + 6) . ' seconds')->format('Y-m-d H:i:s'),
                        $espera, 0, 0, 'HUMAN', 'DROPPED', null, 2.4, 'zv2demo-' . (++$serie)]);
                } else {                      // no contesta, ocupado, no disponible
                    $motivo = ['NOANSWER', 'BUSY', 'CHANUNAVAIL', 'CONGESTION'][$azar(0, 3)];
                    $insLlam->execute([$campId, (int) $lead['lead_id'], null, $lead['phone_number'],
                        $inicio->format('Y-m-d H:i:s'), null, null,
                        $inicio->modify('+' . $azar(8, 25) . ' seconds')->format('Y-m-d H:i:s'),
                        $azar(8, 25), 0, 0, null, $motivo, null, 2.4, 'zv2demo-' . (++$serie)]);
                }
            }
            // llamadas en curso: sin ended_at, que es como el motor las deja
            foreach (array_slice($agentes, 0, 3) as $agente) {
                $lead = $leadsPorCampana[$campId][$azar(0, count($leadsPorCampana[$campId]) - 1)];
                $inicio = $ahora->modify('-' . $azar(1, 7) . ' minutes');
                $insLlam->execute([$campId, (int) $lead['lead_id'], $agente, $lead['phone_number'],
                    $inicio->format('Y-m-d H:i:s'),
                    $inicio->modify('+6 seconds')->format('Y-m-d H:i:s'),
                    $inicio->modify('+7 seconds')->format('Y-m-d H:i:s'),
                    null, 6, 0, 0, 'HUMAN', null, null, 2.4, 'zv2demo-' . (++$serie)]);
            }
        }

        $db->commit();
        echo "Piso sembrado.\n";
        estado($db);
        echo "Las campanas quedan en STOPPED. Para borrarlo todo: demo-piso.php borrar\n";
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

switch ($accion) {
    case 'sembrar': sembrar($db); break;
    case 'borrar': borrar($db); break;
    case 'estado': estado($db); break;
    default: exit("Acciones: sembrar | borrar | estado\n");
}
