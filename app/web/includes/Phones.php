<?php

namespace Includes;

use Includes\Database;
use Includes\Audit;
use PDO;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class Phones {
    
    public static function getTemplateValues() {
        $headers = explode(';', 'extension;dialplan_number;voicemail_id;phone_ip;computer_ip;server_ip;login;pass;status;active;phone_type;fullname;company;picture;messages;old_messages;protocol;local_gmt;ASTmgrUSERNAME;ASTmgrSECRET;login_user;login_pass;login_campaign;park_on_extension;conf_on_extension;VICIDIAL_park_on_extension;VICIDIAL_park_on_filename;monitor_prefix;recording_exten;voicemail_exten;voicemail_dump_exten;ext_context;dtmf_send_extension;call_out_number_group;client_browser;install_directory;local_web_callerID_URL;VICIDIAL_web_URL;AGI_call_logging_enabled;user_switching_enabled;conferencing_enabled;admin_hangup_enabled;admin_hijack_enabled;admin_monitor_enabled;call_parking_enabled;updater_check_enabled;AFLogging_enabled;QUEUE_ACTION_enabled;CallerID_popup_enabled;voicemail_button_enabled;enable_fast_refresh;fast_refresh_rate;enable_persistant_mysql;auto_dial_next_number;VDstop_rec_after_each_call;DBX_server;DBX_database;DBX_user;DBX_pass;DBX_port;DBY_server;DBY_database;DBY_user;DBY_pass;DBY_port;outbound_cid;enable_sipsak_messages;email;template_id;conf_override;phone_context;phone_ring_timeout;conf_secret;delete_vm_after_email;is_webphone;use_external_server_ip;codecs_list;codecs_with_template;webphone_dialpad;on_hook_agent;webphone_auto_answer;voicemail_timezone;voicemail_options;user_group;voicemail_greeting;voicemail_dump_exten_no_inst;voicemail_instructions;on_login_report;unavail_dialplan_fwd_exten;unavail_dialplan_fwd_context;nva_call_url;nva_search_method;nva_error_filename;nva_new_list_id;nva_new_phone_code;nva_new_status;webphone_dialbox;webphone_mute;webphone_volume;webphone_debug;outbound_alt_cid;conf_qualify;webphone_layout;mohsuggest;peer_status;ping_time;webphone_settings');
        $values = explode(';', '70553559;70553559;70553559;;;192.168.1.192;70553559;70553559;ACTIVE;Y;;;;;0;0;SIP;-5.00;cron;1234;\N;\N;\N;8301;8302;8301;park;8612;8309;8501;85026666666666;default;local/8500998@default;Zap/g2/;/usr/bin/mozilla;/usr/local/perl_TK;http://www.vicidial.org/test_callerid_output.php;http://www.vicidial.org/test_VICIDIAL_output.php;1;1;1;0;0;1;1;1;1;1;1;1;0;1000;0;1;1;\N;asterisk;cron;1234;3306;\N;asterisk;cron;1234;3306;70553559;0;\N;;\N;default;60;ngFoavIybh37nM8;N;N;N;;0;Y;N;Y;eastern;;---ALL---;;85026666666667;Y;N;;;\N;NONE;;995;1;NVAINS;Y;Y;Y;N;;Y;;;UNKNOWN;\N;VICIPHONE_SETTINGS');
        
        $template = [];
        foreach ($headers as $i => $header) {
            $val = $values[$i] ?? '';
            if ($val === '\N') $val = null;
            $template[$header] = $val;
        }
        return $template;
    }

    public static function createFromUser($user, $fullName) {
        $db = Database::getInstance();
        try {
            // Verificar si ya existe
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM phones WHERE login = :u OR extension = :e");
            $stmtCheck->execute(['u' => $user, 'e' => $user]);
            if ($stmtCheck->fetchColumn() > 0) return true; // Ya existe, no hacemos nada

            $template = self::getTemplateValues();
            
            // Personalizar con los datos del usuario
            $data = $template;
            $data['extension'] = $user;
            $data['dialplan_number'] = $user;
            $data['voicemail_id'] = $user;
            $data['login'] = $user;
            $data['pass'] = $user;
            $data['fullname'] = $fullName;
            $data['outbound_cid'] = $user;

            $columns = implode(", ", array_keys($data));
            $placeholders = ":" . implode(", :", array_keys($data));
            
            $sql = "INSERT INTO phones ($columns) VALUES ($placeholders)";
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($data)) {
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'CREATE', 'phones', 'extension', $user);
                return true;
            }
        } catch (\PDOException $e) { 
            error_log("Error creando phone para $user: " . $e->getMessage());
            return false; 
        }
        return false;
    }

    public static function delete($extension) {
        $db = Database::getInstance();
        try {
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'DELETE', 'phones', 'extension', $extension);
            $stmt = $db->prepare("DELETE FROM phones WHERE extension = :id");
            return $stmt->execute(['id' => $extension]);
        } catch (\PDOException $e) { return false; }
    }

    public static function getAll($search = null) {
        $db = Database::getInstance();
        $sql = "SELECT extension, login, fullname, protocol, active, status, user_group, outbound_cid FROM phones";
        $params = [];
        if ($search) {
            $sql .= " WHERE extension LIKE :s1 OR login LIKE :s2 OR fullname LIKE :s3";
            $params = ['s1' => "%$search%", 's2' => "%$search%", 's3' => "%$search%"];
        }
        $sql .= " ORDER BY extension ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById($extension) {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM phones WHERE extension = :id LIMIT 1");
        $stmt->execute(['id' => $extension]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Creacion manual de un anexo (no ligado a un Usuario), para telefonos
    // fisicos/softphones que necesiten extension propia sin pasar por Users.php.
    public static function create($data) {
        $db = Database::getInstance();
        try {
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM phones WHERE extension = :e");
            $stmtCheck->execute(['e' => $data['extension']]);
            if ($stmtCheck->fetchColumn() > 0) return false; // Ya existe ese anexo

            $template = self::getTemplateValues();
            $finalData = array_merge($template, $data);

            $columns = implode(", ", array_keys($finalData));
            $placeholders = ":" . implode(", :", array_keys($finalData));

            $stmt = $db->prepare("INSERT INTO phones ($columns) VALUES ($placeholders)");
            if ($stmt->execute($finalData)) {
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'CREATE', 'phones', 'extension', $data['extension']);
                return true;
            }
        } catch (\PDOException $e) { return false; }
        return false;
    }

    public static function update($extension, $data) {
        $db = Database::getInstance();
        try {
            $setParts = [];
            foreach (array_keys($data) as $key) {
                $setParts[] = "$key = :$key";
            }
            $setSql = implode(", ", $setParts);
            $data['extensionKey'] = $extension;

            $stmt = $db->prepare("UPDATE phones SET $setSql WHERE extension = :extensionKey");
            if ($stmt->execute($data)) {
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'UPDATE', 'phones', 'extension', $extension);
                return true;
            }
        } catch (\PDOException $e) { return false; }
        return false;
    }
}
