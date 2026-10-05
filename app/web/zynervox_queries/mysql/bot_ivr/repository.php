<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
require_once __DIR__.'/../../contracts/BotIvrRepository.php';
require_once __DIR__.'/campaigns.php';
require_once __DIR__.'/lists.php';
require_once __DIR__.'/leads.php';
final class Repository implements \ZynervoxQueries\BotIvrRepository {
    use CampaignQueries, ListQueries, LeadQueries;
    private $db;
    public function __construct(\PDO $db) { $this->db = $db; }
}
