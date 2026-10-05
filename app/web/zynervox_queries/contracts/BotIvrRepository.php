<?php
declare(strict_types=1);
namespace ZynervoxQueries;
interface BotIvrRepository {
    public function campaigns(): array;
    public function campaign(int $id): array;
    public function createCampaign(string $name, bool $active): int;
    public function updateCampaign(int $id, array $values): void;
    public function lists(int $campaignId): array;
    public function createList(int $campaignId, string $name, bool $active): int;
    public function updateList(int $campaignId, int $listId, string $name, bool $active): void;
    public function list(int $listId, int $campaignId): array;
    public function leadCount(int $listId): int;
    public function importLeads(int $listId, int $campaignId, array $parsed): array;
}
