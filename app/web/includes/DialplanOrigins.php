<?php
namespace Includes;

class DialplanOrigins {
    const HEADER = "; Manual calls: list 1. Future worker must set ZV2_LIST_ID explicitly.\n[zynervoxv2]\n";

    public static function body(string $dialplan): string {
        $dialplan = str_replace(["\r\n", "\r"], "\n", $dialplan);
        $dialplan = preg_replace('/^\s*; Manual calls: list 1\. Future worker must set ZV2_LIST_ID explicitly\.\s*\n/', '', $dialplan);
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
        return self::HEADER.$routes.$helpers;
    }
}
