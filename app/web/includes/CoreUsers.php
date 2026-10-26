<?php
namespace Includes;
require_once __DIR__.'/Database.php';
require_once __DIR__.'/Audit.php';

class CoreUsers {
    // Niveles administrables desde la web: 1 agente, 7 GTR, 8 supervisor.
    // El 9 se da de alta por SQL a proposito: la web no multiplica admins.
    private const LEVELS = [1, 7, 8];

    private static function database() {
        if (!\Config\Config::deployment('isolated',false) || \Config\Config::get('CORE_DB_database')!=='zynervox_core') throw new \RuntimeException('Usuarios requiere zynervox_core aislado.');
        return Database::getCoreInstance();
    }
    public static function getAll(): array {
        return self::database()->query('SELECT user,full_name,user_level,active FROM v2_zynervox_users ORDER BY user')->fetchAll(\PDO::FETCH_ASSOC);
    }
    public static function getByUser(string $user): ?array {
        $stmt=self::database()->prepare('SELECT user,full_name,user_level,active FROM v2_zynervox_users WHERE user=?');$stmt->execute([$user]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    public static function levels(): array { return self::LEVELS; }

    public static function saveUser(array $input, ?string $existingUser=null): void {
        $user=$input['user'] ?? '';$name=$input['full_name'] ?? '';$password=$input['pass'] ?? '';$active=$input['active'] ?? '';
        $level=$input['user_level'] ?? 1;
        if (!is_scalar($level) || !in_array((int)$level,self::LEVELS,true)) throw new \InvalidArgumentException('Nivel inválido: solo agente, GTR o supervisor.');
        $level=(int)$level;
        if (!is_string($user)) throw new \InvalidArgumentException('Login inválido.');
        // El agente se loguea con su anexo SIP; GTR y supervisor no tienen anexo.
        if ($level===1) {
            if (!preg_match('/^[0-9]{1,20}$/D',$user)) throw new \InvalidArgumentException('El login del agente debe tener de 1 a 20 dígitos y coincidir con su anexo SIP.');
        } elseif (!preg_match('/^[a-z][a-z0-9._-]{2,19}$/D',$user)) {
            throw new \InvalidArgumentException('El login de GTR o supervisor debe tener de 3 a 20 caracteres en minúscula, empezar por letra y usar sólo letras, dígitos, punto, guion o guion bajo.');
        }
        if (!is_string($name) || trim($name)==='' || !mb_check_encoding($name,'UTF-8') || mb_strlen($name,'UTF-8')>100 || preg_match('/[\x00-\x1f\x7f]/',$name)) throw new \InvalidArgumentException('Ingresa un nombre de hasta 100 caracteres.');
        if (!is_string($password) || ($password!=='' && (strlen($password)<8 || strlen($password)>72 || strpos($password,"\0")!==false))) throw new \InvalidArgumentException('La contraseña debe tener entre 8 y 72 bytes.');
        if (!is_string($active) || !in_array($active,['Y','N'],true)) throw new \InvalidArgumentException('Estado inválido.');
        if ($existingUser!==null && $existingUser!==$user) throw new \InvalidArgumentException('El login no se puede cambiar.');
        if ($existingUser===null && $password==='') throw new \InvalidArgumentException('Ingresa una contraseña para la cuenta nueva.');
        $hash=$password==='' ? null : password_hash($password,PASSWORD_DEFAULT);
        if ($password!=='' && (!is_string($hash) || strlen($hash)>255)) throw new \RuntimeException('No se pudo proteger la contraseña.');
        $db=self::database();$db->beginTransaction();
        try {
            if ($existingUser===null) {
                $db->prepare('INSERT INTO v2_zynervox_users (user,pass,full_name,user_level,active) VALUES (?,?,?,?,?)')->execute([$user,$hash,trim($name),$level,$active]);
            } else {
                $stmt=$db->prepare('SELECT user_level FROM v2_zynervox_users WHERE user=? FOR UPDATE');$stmt->execute([$user]);
                $current=(int)$stmt->fetchColumn();
                if (!in_array($current,self::LEVELS,true)) throw new \InvalidArgumentException('Solo se pueden editar cuentas de agente, GTR o supervisor.');
                // El nivel no se cambia al editar: cambiarlo altera privilegios
                // de una cuenta viva. Se da de baja y se crea con el nivel nuevo.
                if ($current!==$level) throw new \InvalidArgumentException('El nivel de una cuenta existente no se cambia desde aquí.');
                $db->prepare('UPDATE v2_zynervox_users SET full_name=?,active=?'.($hash===null ? '' : ',pass=?').' WHERE user=?')->execute($hash===null ? [trim($name),$active,$user] : [trim($name),$active,$hash,$user]);
            }
            if (!Audit::logAccess($_SESSION['user'] ?? 'system',$existingUser===null ? 'CORE_AGENT_CREATE' : 'CORE_AGENT_UPDATE',$user)) throw new \RuntimeException('No se pudo registrar la operación.');
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error instanceof \PDOException && ($error->errorInfo[1] ?? null)===1062) throw new \InvalidArgumentException('Ese login ya existe.');
            throw $error;
        }
    }
}
