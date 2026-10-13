<?php
declare(strict_types=1);
namespace ZynervoxQueries;
interface BotIvrRepository {
    public function ttsProviders(): array;
    public function createTtsProvider(string $name, string $endpoint): int;
    public function createDialRun(int $listId,int $campaignId,string $runId,int $channels,array $config,string $actor): void;
    public function dialRun(int $listId,int $campaignId,?string $runId=null): ?array;
    public function transitionDialRun(string $runId,string $state,string $error=''): void;
    public function claimDialLead(string $runId,string $callId): ?array;
    public function rejectDialClaim(string $runId,string $callId): void;
    public function saveIvrResult(string $callId, string $nodeId, array $fields): void;
    public function ivrCallContext(int $listId, int $leadId, string $callId): array;
    public function recordCallEvent(string $callId, string $type, string $eventId, int $epoch, array $data): void;
    public function preanswerCall(string $callId): ?array;
    public function pendingPreanswerCalls(int $limit=200): array;
    public function savePreanswerResult(string $callId,string $eventId,int $epoch,array $data): bool;
    public function openCallsForRecovery(): array;
    public function observeCallPresence(string $callId, bool $active, int $epoch): bool;
    public function startCall(int $listId, string $phone, string $callId): int;
    public function finishCall(string $callId, string $dialStatus, string $amdStatus, string $amdCause, int $hangupCause, bool $answered): void;
    public function campaignMonitor(): array;
    public function campaigns(): array;
    public function campaign(int $id): array;
    public function createCampaign(string $name, bool $active): int;
    public function updateCampaign(int $id, array $values): void;
    public function deactivateCampaign(int $id): void;
    public function lists(int $campaignId): array;
    public function createList(int $campaignId, string $name, bool $active, ?int $flowId = null): int;
    public function updateList(int $campaignId, int $listId, string $name, bool $active, ?int $flowId = null): void;
    public function list(int $listId, int $campaignId): array;
    public function updateListCallChannels(int $listId,int $campaignId,int $channels): void;
    public function updateListDialPrefix(int $listId, int $campaignId, string $prefix): void;
    public function listStatistics(int $listId,int $campaignId): array;
    public function latestLeadCalls(int $listId,int $campaignId,int $limit=50,int $offset=0,?int $leadId=null,bool $managedOnly=false,?string $status=null): array;
    public function leadCount(int $listId,bool $managedOnly=false): int;
    public function audioLeads(int $listId, int $campaignId): array;
    public function importLeads(int $listId, int $campaignId, array $parsed): array;
}
