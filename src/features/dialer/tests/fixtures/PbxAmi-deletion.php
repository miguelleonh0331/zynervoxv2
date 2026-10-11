<?php
namespace Includes;
// Test adapter: records cleanup calls without contacting Asterisk.
class PbxAmi {
    public static $events=[];
    public static $fail=false;
    public static function load(): array { return []; }
    public static function command(array $config,string $command): string {
        if (self::$fail) throw new \RuntimeException('Injected PBX failure');
        self::$events[]=$command;
        return strpos($command,'queue show ')===0 ? 'SIP/79990 (Not in use)' : 'OK';
    }
    public static function rawAction(array $config,string $action,array $fields): string {
        self::$events[]=[$action,$fields]; return 'OK';
    }
}
