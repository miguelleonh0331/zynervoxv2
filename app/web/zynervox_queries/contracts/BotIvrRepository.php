<?php
declare(strict_types=1);
namespace ZynervoxQueries;
interface BotIvrRepository {
    public function startCall(int $listId, string $phone, string $callId): int;
    public function finishCall(string $callId, string $dialStatus, string $amdStatus, string $amdCause, int $hangupCause, bool $answered): void;
    public function campaigns(): array;
    public function campaign(int $id): array;
    public function createCampaign(string $name, bool $active): int;
    public function updateCampaign(int $id, array $values): void;
    public function lists(int $campaignId): array;
    public function createList(int $campaignId, string $name, bool $active, ?int $flowId = null): int;
    public function updateList(int $campaignId, int $listId, string $name, bool $active, ?int $flowId = null): void;
    public function list(int $listId, int $campaignId): array;
    public function updateListDialPrefix(int $listId, int $campaignId, string $prefix): void;
    public function leadCount(int $listId): int;
    public function audioLeads(int $listId, int $campaignId): array;
    public function importLeads(int $listId, int $campaignId, array $parsed): array;
}
