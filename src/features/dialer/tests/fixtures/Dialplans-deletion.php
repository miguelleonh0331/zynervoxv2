<?php
namespace Includes;
class Dialplans {
    public static $deleted=[];
    public static function delete(int $id): array { self::$deleted[]=$id; return []; }
}
