<?php
namespace Includes;

require_once __DIR__.'/Database.php';
require_once __DIR__.'/Audit.php';
require_once __DIR__.'/DialplanOrigins.php';

class Dialplans {
    private static function database() {
        if (!\Config\Config::deployment('isolated', false) || \Config\Config::get('CORE_DB_database') !== 'zynervox_core') throw new \RuntimeException('Dialplan requiere zynervox_core aislado.');
        return Database::getCoreInstance();
    }

    public static function getAll(): array {
        return self::database()->query('SELECT * FROM v2_dialplans ORDER BY dial_prefix,dialplan_id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function getById(int $id): ?array {
        $stmt=self::database()->prepare('SELECT * FROM v2_dialplans WHERE dialplan_id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function origins(): array {
        return self::database()->query("SELECT dialplan_id,dial_prefix,name FROM v2_dialplans WHERE active='Y' ORDER BY name,dial_prefix")->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Publish only the telephone route. IVR tracking/hooks are not executed by the dialer.
    public static function outboundRoute(int $id): array {
        $row=self::getById($id);
        if (!$row || $row['active']!=='Y') throw new \InvalidArgumentException('El dialplan seleccionado no existe o está inactivo.');
        if (strpos($row['name'],'v2dialer_campaign_')===0) throw new \InvalidArgumentException('Selecciona un dialplan de salida, no un callback interno.');
        return self::sipRoute($row);
    }

    public static function sipRoute(array $row): array {
        $invalid='El dialplan no tiene una salida SIP simple compatible con el marcador.';
        $body=DialplanOrigins::body((string)$row['dialplan_entry']);
        if (preg_match('/^\s*\[/m',$body)) throw new \InvalidArgumentException($invalid);
        $prefixes=DialplanOrigins::prefixes($body);
        if (count($prefixes)!==1 || $prefixes[0]!==$row['dial_prefix']) throw new \InvalidArgumentException($invalid);
        $apps=[]; $phoneSets=0; $slice=null;
        foreach (explode("\n",$body) as $line) {
            $line=trim($line);
            if ($line==='' || $line[0]===';') continue;
            if (!preg_match('/^(?:exten\s*=>\s*_'.preg_quote($row['dial_prefix'],'/').'X\.\s*,\s*1|same\s*=>\s*n(?:\(fallo\))?)\s*,\s*(.+)$/iD',$line,$match)) throw new \InvalidArgumentException($invalid);
            $apps[]=$match[1];
            if (preg_match('/^Set\(_{0,2}ZV2_PHONE=\$\{EXTEN:([0-9]{1,2})\}\)$/D',$match[1],$set)) { $phoneSets++; $slice=(int)$set[1]; }
        }
        $dials=array_values(array_filter($apps,static function($app) { return stripos($app,'Dial(')===0; }));
        if (count($dials)!==1 || !preg_match('/^Dial\(SIP\/([A-Za-z0-9_-]{1,60})\/([0-9]{0,20})(\$\{EXTEN:([0-9]{1,2})\}|\$\{ZV2_PHONE\})(?:,([0-9]{1,3})(?:,((?:b\(zynervoxv2-outbound\^s\^1\))?(?:U\(zynervoxv2-(?:amd|bot-ivr|bot-ivr-sin-amd)\))?))?)?\)$/D',$dials[0],$dial)) throw new \InvalidArgumentException($invalid);
        if ($dial[3]==='${ZV2_PHONE}') {
            if ($phoneSets!==1 || $slice!==strlen($row['dial_prefix'])) throw new \InvalidArgumentException($invalid);
        } elseif ((int)$dial[4]!==strlen($row['dial_prefix']) || $phoneSets>0) throw new \InvalidArgumentException($invalid);
        $seenDial=false;
        foreach ($apps as $app) {
            if ($app===$dials[0]) { $seenDial=true; continue; }
            if (preg_match('/^NoOp\([^\r\n]*\)$/D',$app)) continue;
            if ($seenDial) { if ($app==='Hangup()') continue; throw new \InvalidArgumentException($invalid); }
            if (preg_match('/^Set\(_{0,2}ZV2_PHONE=\$\{EXTEN:[0-9]{1,2}\}\)$/D',$app)) continue;
            if (in_array($app,[
                'ExecIf($["${ZV2_LIST_ID}" = ""]?Set(ZV2_LIST_ID=1))',
                'Set(__ZV2_LIST_ID=${ZV2_LIST_ID})','Set(__ZV2_CALL_ID=${UNIQUEID})',
                'Set(AGISIGHUP=no)','Set(__ZV2_LEAD_ID=${ZV2_LEAD_ID})',
                'GotoIf($["${ZV2_TRACK_OK}" != "1"]?fallo)',
                'Set(CHANNEL(hangup_handler_push)=zynervoxv2-call-finish,s,1)',
                'AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,start,${ZV2_LIST_ID},${ZV2_PHONE},${ZV2_CALL_ID},'.$row['dial_prefix'].','.$dial[1].',${CHANNEL(linkedid)})',
            ],true)) continue;
            throw new \InvalidArgumentException($invalid);
        }
        return ['dialplan_id'=>(int)$row['dialplan_id'],'name'=>$row['name'],'dial_prefix'=>$row['dial_prefix'],
            'protocol'=>'SIP','endpoint'=>$dial[1],'outbound_prefix'=>$dial[2]];
    }

    public static function save(?int $id, string $name, string $body, string $active): array {
        $name=trim($name);
        if ($name==='' || mb_strlen($name,'UTF-8')>100 || preg_match('/[\x00-\x1f]/',$name)) throw new \InvalidArgumentException('Ingresa un nombre de hasta 100 caracteres.');
        if (!in_array($active,['Y','N'],true)) throw new \InvalidArgumentException('Estado inválido.');
        $body=DialplanOrigins::body($body);
        if (strlen($body)>262144 || strpos($body,"\0")!==false) throw new \InvalidArgumentException('Dialplan inválido o mayor de 256 KB.');
        $prefixes=DialplanOrigins::prefixes($body);
        if (count($prefixes)!==1) throw new \InvalidArgumentException('Cada dialplan debe tener un único prefijo: exten => _7306X.,1,...');
        $db=self::database();
        $db->beginTransaction();
        try {
            $rows=$db->query('SELECT * FROM v2_dialplans ORDER BY dialplan_id FOR UPDATE')->fetchAll(\PDO::FETCH_ASSOC);
            $found=$id===null;
            $preview=[];
            foreach ($rows as $row) {
                if ((int)$row['dialplan_id']===$id) { $found=true; continue; }
                if ($row['dial_prefix']===$prefixes[0]) throw new \InvalidArgumentException('Este prefijo ya está registrado en otro dialplan.');
                if ($row['active']==='Y') $preview[]=['carrier_id'=>'DIALPLAN-'.$row['dialplan_id'],'dialplan_entry'=>$row['dialplan_entry']];
            }
            if (!$found) throw new \InvalidArgumentException('El dialplan no existe.');
            // Validate helper context conflicts even before publishing a new active plan.
            if ($active==='Y') $preview[]=['carrier_id'=>'DIALPLAN-NEW','dialplan_entry'=>$body];
            DialplanOrigins::render($preview);
            if ($id===null) {
                $db->prepare('INSERT INTO v2_dialplans (name,dial_prefix,dialplan_entry,active) VALUES (?,?,?,?)')->execute([$name,$prefixes[0],$body,$active]);
                $id=(int)$db->lastInsertId();
            } else $db->prepare('UPDATE v2_dialplans SET name=?,dial_prefix=?,dialplan_entry=?,active=? WHERE dialplan_id=?')->execute([$name,$prefixes[0],$body,$active,$id]);
            Audit::logAccess($_SESSION['user'] ?? 'system','DIALPLAN_SAVE',(string)$id);
            $db->commit();
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        return ['id'=>$id]+self::publish();
    }

    public static function delete(int $id): array {
        $stmt=self::database()->prepare('DELETE FROM v2_dialplans WHERE dialplan_id=?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) throw new \InvalidArgumentException('El dialplan no existe.');
        Audit::logAccess($_SESSION['user'] ?? 'system','DIALPLAN_DELETE',(string)$id);
        return self::publish();
    }

    private static function publish(): array {
        require_once __DIR__.'/Carriers.php';
        return Carriers::regenerateAndReload();
    }
}
