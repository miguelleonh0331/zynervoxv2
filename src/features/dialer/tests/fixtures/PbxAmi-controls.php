<?php
namespace Includes;
// In-memory PBX adapter: no network calls or persistent queues.
class PbxAmi {
    public static $queues=[];
    public static $events=[];
    public static $channels='';
    public static $fail=false;
    public static function load(): array { return []; }
    public static function command(array $config,string $command): string {
        if (self::$fail) throw new \RuntimeException('Injected PBX failure');
        if ($command==='core show channels concise') return self::$channels;
        $queue=substr($command,strlen('queue show ')); $lines=[];
        foreach (self::$queues[$queue] ?? [] as $interface=>$reason) $lines[]=$interface.' (Not in use)'.($reason!==null ? ' (paused:'.$reason.')' : '');
        return implode("\n",$lines);
    }
    public static function rawAction(array $config,string $action,array $fields): string {
        if (self::$fail) throw new \RuntimeException('Injected PBX failure');
        self::$events[]=[$action,$fields]; $queue=$fields['Queue'] ?? ''; $interface=$fields['Interface'] ?? '';
        if ($action==='QueueAdd') self::$queues[$queue][$interface]=($fields['Paused'] ?? '')==='true' ? 'initial' : null;
        if ($action==='QueueRemove') unset(self::$queues[$queue][$interface]);
        if ($action==='QueuePause') self::$queues[$queue][$interface]=$fields['Paused']==='true' ? ($fields['Reason'] ?? 'manual') : null;
        return 'OK';
    }
}
