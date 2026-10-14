<?php
namespace Includes;

class DialplanOrigins {
    const HEADER = "; Manual calls: list 1. Future worker must set ZV2_LIST_ID explicitly.\n[zynervoxv2]\n";

    const COMMON = <<<'ASTERISK'
[zynervoxv2-bot-ivr]
exten => s,1,Set(AGISIGHUP=no)
 same => n,MixMonitor(/var/lib/zynervoxv2/asterisk/recordings/bot_ivr/${FILTER(0-9A-Za-z._-,${ZV2_CALL_ID})}.wav)
 same => n,Gosub(zynervoxv2-amd,s,1)
 same => n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?fin)
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/ivr_engine_agi.php,${ZV2_LIST_ID},${ZV2_LEAD_ID},${ZV2_CALL_ID})
 same => n(fin),StopMixMonitor()
 same => n,Set(GOSUB_RESULT=CONTINUE)
 same => n,Return()

[zynervoxv2-bot-ivr-sin-amd]
exten => s,1,Set(AGISIGHUP=no)
 same => n,ExecIf($["${ZV2_PREANSWER_MONITOR}" = ""]?MixMonitor(/var/lib/zynervoxv2/asterisk/recordings/bot_ivr/${FILTER(0-9A-Za-z._-,${ZV2_CALL_ID})}.wav))
 same => n,ExecIf($["${ZV2_BARESIP}" != "1"]?Set(MASTER_CHANNEL(ZV2_ANSWERED)=1))
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},ANSWER)
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/ivr_engine_agi.php,${ZV2_LIST_ID},${ZV2_LEAD_ID},${ZV2_CALL_ID})
 same => n,StopMixMonitor()
 same => n,Set(GOSUB_RESULT=CONTINUE)
 same => n,Return()

[zynervoxv2-amd]
exten => s,1,Set(MASTER_CHANNEL(ZV2_ANSWERED)=1)
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},ANSWER)
 same => n,AMD()
 same => n,Set(MASTER_CHANNEL(ZV2_AMD_STATUS)=${AMDSTATUS})
 same => n,Set(MASTER_CHANNEL(ZV2_AMD_CAUSE)=${AMDCAUSE})
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},AMD,${AMDSTATUS},${AMDCAUSE})
 same => n,NoOp(AMD resultado=${AMDSTATUS} causa=${AMDCAUSE})
 same => n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?casilla)
 same => n,Return()
 same => n(casilla),Set(GOSUB_RESULT=CONTINUE)
 same => n,Return()

[zynervoxv2-call-finish]
exten => s,1,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,finish,${ZV2_CALL_ID},${DIALSTATUS},${ZV2_AMD_STATUS},${ZV2_AMD_CAUSE},${HANGUPCAUSE},${ZV2_ANSWERED})
 same => n,Return()

[zynervoxv2-outbound]
exten => s,1,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},OUTBOUND)
 same => n,AGI(/usr/bin/php,/srv/www/htdocs/zynervoxv2/bot_ivr/preanswer_agi.php,${ZV2_CALL_ID})
 same => n,Return()

; SIP annex origin: no Answer() until the real destination answers.
[zynervoxv2-baresip]
exten => _7307X.,1,Set(ZV2_ORIGIN_OK=0)
 same => n,AGI(/usr/bin/php,/srv/www/htdocs/zynervoxv2/bot_ivr/baresip_context_agi.php,${EXTEN})
 same => n,GotoIf($["${ZV2_ORIGIN_OK}" != "1"]?invalid)
 same => n,Set(__ZV2_BARESIP=1)
 same => n,ExecIf($["${ZV2_ORIGIN_CID}" != ""]?Set(CALLERID(num)=${ZV2_ORIGIN_CID}))
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,start,${ZV2_LIST_ID},${ZV2_PHONE},${ZV2_CALL_ID},7307,carrier1,${CHANNEL(linkedid)})
 same => n,GotoIf($["${ZV2_TRACK_OK}" != "1"]?invalid)
 same => n,Set(CHANNEL(hangup_handler_push)=zynervoxv2-call-finish,s,1)
 same => n,Dial(SIP/carrier1/881851${ZV2_PHONE},60,b(zynervoxv2-outbound^s^1)G(zynervoxv2-baresip-answer^answered^1))
 same => n(invalid),Hangup()

; G() splits the two legs, as in 3006. Only the callee owns post-answer FINISH.
[zynervoxv2-baresip-answer]
exten => answered,1,Goto(zynervoxv2-baresip-caller,caller,1)
 same => n,Goto(callee,1)
exten => callee,1,Set(__ZV2_ANSWERED=1)
 same => n,Set(DIALSTATUS=ANSWER)
 same => n,Set(CHANNEL(hangup_handler_push)=zynervoxv2-call-finish,s,1)
 same => n,Gosub(zynervoxv2-bot-ivr-sin-amd,s,1)
 same => n,StopMixMonitor()
 same => n,Hangup()

[zynervoxv2-baresip-caller]
exten => caller,1,Set(CHANNEL(hangup_handler_pop)=)
 same => n,Wait(1)
 same => n,Hangup()
ASTERISK;

    public static function template(): string {
        return self::COMMON."\n\n".self::HEADER;
    }

    private static function commonContexts(): array {
        $common=str_replace(["\r\n","\r"],"\n",self::COMMON);
        preg_match_all('/^\s*\[([^\]\n]+)\][^\n]*\n?(.*?)(?=^\s*\[|\z)/ms',$common,$matches,PREG_SET_ORDER);
        $contexts=[];
        foreach ($matches as $match) $contexts[$match[1]]=trim($match[2]);
        return $contexts;
    }

    public static function body(string $dialplan): string {
        $dialplan = str_replace(["\r\n", "\r"], "\n", $dialplan);
        $dialplan = preg_replace('/^\s*; Manual calls: list 1\. Future worker must set ZV2_LIST_ID explicitly\.\s*\n/m', '', $dialplan);
        $common=self::commonContexts();
        $dialplan=preg_replace_callback('/^\s*\[(zynervoxv2-bot-ivr-sin-amd|zynervoxv2-bot-ivr|zynervoxv2-amd|zynervoxv2-call-finish|zynervoxv2-outbound|zynervoxv2-baresip|zynervoxv2-baresip-answer|zynervoxv2-baresip-caller)\][^\n]*\n?(.*?)(?=^\s*\[|\z)/ms', function ($match) use ($common) {
            if (trim($match[2])!==$common[$match[1]]) throw new \InvalidArgumentException('El contexto '.$match[1].' pertenece a la plantilla común; no lo redefinas.');
            return '';
        },$dialplan);
        return ltrim(preg_replace('/^\s*\[zynervoxv2\]\s*(?:;[^\n]*)?\n?/', '', $dialplan));
    }

    public static function prefixes(string $dialplan): array {
        $body = self::body($dialplan);
        // Only the initial zynervoxv2 routes are reusable origins, not helper contexts.
        $body = preg_split('/^\s*\[.*$/m', $body, 2)[0];
        preg_match_all('/^\s*exten\s*=>\s*_([0-9]{1,20})X\.\s*,\s*1\s*,/mi', $body, $matches);
        return array_values(array_unique($matches[1]));
    }

    public static function render(array $carriers): string {
        $routes = '';
        $contexts = [];
        foreach ($carriers as $carrier) {
            $body = self::body($carrier['dialplan_entry']);
            if (trim($body) === '') continue;
            $parts = preg_split('/(?=^\s*\[[^\]\n]+\])/m', $body, 2);
            $comment = '; ---- Carrier: '.$carrier['carrier_id'].' ----'."\n";
            $routes .= $comment.rtrim($parts[0])."\n\n";
            if (isset($parts[1])) {
                preg_match_all('/^\s*\[([^\]\n]+)\][^\n]*\n?(.*?)(?=^\s*\[|\z)/ms', $parts[1], $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $name=trim($match[1]);
                    if (strtolower($name)==='zynervoxv2') throw new \InvalidArgumentException('La cabecera zynervoxv2 es fija; no la repitas dentro del dialplan.');
                    $definition=trim($match[2]);
                    if (isset($contexts[$name]) && $contexts[$name]!==$definition) throw new \InvalidArgumentException('Contexto auxiliar repetido con contenido distinto: '.$name);
                    $contexts[$name]=$definition;
                }
            }
        }
        $helpers='';
        foreach ($contexts as $name=>$definition) $helpers.='['.$name."]\n".$definition."\n\n";
        return self::template().$routes.$helpers;
    }
}
