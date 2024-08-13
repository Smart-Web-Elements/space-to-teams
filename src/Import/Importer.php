<?php

namespace App\Import;

use App\AbstractService;
use DateTime;
use Exception;
use GuzzleHttp\Psr7\Response;
use Microsoft\Graph\Generated\Models\AadUserConversationMember;
use Microsoft\Graph\Generated\Models\BodyType;
use Microsoft\Graph\Generated\Models\Channel;
use Microsoft\Graph\Generated\Models\ChannelMembershipType;
use Microsoft\Graph\Generated\Models\ChatMessage;
use Microsoft\Graph\Generated\Models\ChatMessageFromIdentitySet;
use Microsoft\Graph\Generated\Models\Identity;
use Microsoft\Graph\Generated\Models\ItemBody;
use Microsoft\Graph\Generated\Models\Team;
use Microsoft\Graph\Generated\Models\TeamVisibilityType;
use Microsoft\Graph\Generated\Models\User;
use Microsoft\Graph\Generated\Teams\Item\Members\Add\AddPostRequestBody;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Abstractions\ApiException;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;

class Importer extends AbstractService
{

    private GraphServiceClient $graphClient;

    /**
     * @var User[]
     */
    private array $members = [];

    private array $channelTeamMapping = [];

    private array $memberTeamMapping = [];

    private array $failedMessages = [];

    /**
     * @var int[]
     */
    private array $messageTimestamps = [];

    public function __construct(string $channelDir, bool $printOutput = true)
    {
        parent::__construct($channelDir, $printOutput);

        $tokenRequestContext = new ClientCredentialContext(
            $_ENV['TENANT_ID'],
            $_ENV['MS_CLIENT_ID'],
            $_ENV['MS_CLIENT_SECRET']
        );

        $this->graphClient = new GraphServiceClient($tokenRequestContext);
        $this->collectMembers();
        $this->removeTeams();
    }

    public function addChannelTeamMapping(string $channelName, string $teamName): void
    {
        if (!isset($this->channelTeamMapping[$teamName])) {
            $this->channelTeamMapping[$teamName] = [];
        }

        $this->channelTeamMapping[$teamName][] = $channelName;
    }

    public function addMemberTeamMapping(string $memberMail, string $teamName): void
    {
        if (!isset($this->memberTeamMapping[$teamName])) {
            $this->memberTeamMapping[$teamName] = [];
        }

        $this->memberTeamMapping[$teamName][] = $memberMail;
    }

    public function getChannelByName(array $channelJson, string $teamId): ?Channel
    {
        $this->useTry();

        try {
            $channels = $this->graphClient
                ->teams()
                ->byTeamId($teamId)
                ->channels()
                ->get()
                ->wait()
                ->getValue();
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            return $this->getChannelByName($channelJson, $teamId);
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        $targetChannel = null;

        foreach ($channels as $channel) {
            if (strtolower($channel->getDisplayName()) === 'general') {
                $targetChannel = $channel;
                break;
            }
        }

        if (!$targetChannel) {
            return $this->getChannelByName($channelJson, $teamId);
        }

        $targetChannel->setCreatedDateTime(new DateTime($channelJson['created']['iso']));
        $targetChannel->setMembershipType(new ChannelMembershipType(ChannelMembershipType::STANDARD));
        $additionalData = [
            '@microsoft.graph.channelCreationMode' => 'migration',
        ];
        $targetChannel->setAdditionalData($additionalData);

        if (!empty($channelJson['description'])) {
            $targetChannel->setDescription($channelJson['description']);
        }

        try {
            $targetChannel = $this->graphClient
                ->teams()
                ->byTeamId($teamId)
                ->channels()
                ->byChannelId($targetChannel->getId())
                ->patch($targetChannel)
                ->wait();
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            return $this->getChannelByName($channelJson, $teamId);
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        $this->resetTries();

        return $targetChannel;
    }

    public function import(): void
    {
        $startTime = time();

        foreach ($this->memberTeamMapping as $teamName => $members) {
            $dateTime = $this->getDateTimeForTeam($teamName);
            $team = $this->createTeam($teamName, $dateTime);

            if (!$team) {
                $this->print('Unable to create team "%s"', $teamName);
                exit(255);
            }

            $this->importChannels($team);
            $this->completeGeneralChannelMigration($team);
            $this->completeTeamMigration($team->getId());
            $this->addMembersToTeam($team->getId(), $members);
        }

        $executionTime = time() - $startTime;
        $this->print('');
        $this->print('--------------------------------');
        $this->print('');
        $this->print('Import finished in %s', $this->toSimpleTime($executionTime));
    }

    private function addMembersToTeam(string $teamId, array $memberMails): void
    {
        $this->useTry();
        sleep(AbstractService::SLEEP);
        $this->print('Add %d members to team:', count($memberMails));
        $requestBody = new AddPostRequestBody();
        $memberArray = [];

        foreach ($memberMails as $index => $memberMail) {
            $user = $this->getMember($memberMail);
            $member = new AadUserConversationMember();
            $member->setOdataType('microsoft.graph.aadUserConversationMember');
            $roles = [];

            if ($index === 0) {
                $roles[] = 'owner';
            }

            $member->setRoles($roles);

            $additionalData = [
                'user@odata.bind' => 'https://graph.microsoft.com/beta/users/' . $user->getId(),
            ];
            $member->setAdditionalData($additionalData);
            $this->print('- "%s"', $user->getDisplayName());
            $memberArray[] = $member;
        }

        $requestBody->setValues($memberArray);

        try {
            $this->graphClient
                ->teams()
                ->byTeamId($teamId)
                ->members()
                ->add()
                ->post($requestBody)
                ->wait();
            sleep(AbstractService::SLEEP);
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            $this->addMembersToTeam($teamId, $memberMails);
            return;
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        $this->resetTries();
    }

    private function checkTimestamp(int $timestamp): DateTime
    {
        $seconds = floor($timestamp / 1000);

        while (in_array($seconds, $this->messageTimestamps)) {
            $seconds++;
        }

        $this->messageTimestamps[] = $seconds;

        return new DateTime('@' . $seconds);
    }

    private function collectMembers(): void
    {
        $this->useTry();
        $this->print('Collect all users of this organization');
        try {
            $this->members = $this->graphClient
                ->users()
                ->get()
                ->wait()
                ->getValue();
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            $this->collectMembers();
            return;
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        $this->print('Found %d users', count($this->members));
        $this->resetTries();
    }

    private function completeChannelMigration(string $teamId, string $channelId): void
    {
        $this->useTry();
        try {
            $channel = $this->graphClient
                ->teams()
                ->byTeamId($teamId)
                ->channels()
                ->byChannelId($channelId)
                ->get()
                ->wait();
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            $this->completeChannelMigration($teamId, $channelId);
            return;
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        $this->print('Completing channel migration for channel "%s".', $channel->getDisplayName());

        try {
            $this->graphClient
                ->teams()
                ->byTeamId($teamId)
                ->channels()
                ->byChannelId($channelId)
                ->completeMigration()
                ->post()
                ->wait();
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            $this->completeChannelMigration($teamId, $channelId);
            return;
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        $this->resetTries();
    }

    private function completeGeneralChannelMigration(Team $team): void
    {
        $this->useTry();
        $id = '';

        try {
            $channels = $this->graphClient
                ->teams()
                ->byTeamId($team->getId())
                ->channels()
                ->get()
                ->wait()
                ->getValue();
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            $this->completeGeneralChannelMigration($team);
            return;
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        foreach ($channels as $channel) {
            if ($channel->getDisplayName() === 'General') {
                $id = $channel->getId();
            }
        }

        $this->resetTries();
        sleep(AbstractService::SLEEP);
        $this->completeChannelMigration($team->getId(), $id);
        sleep(AbstractService::SLEEP);
    }

    private function completeTeamMigration(string $teamId): void
    {
        $this->useTry();
        $this->print('Completing team migration.');

        try {
            $this->graphClient
                ->teams()
                ->byTeamId($teamId)
                ->completeMigration()
                ->post()
                ->wait();
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            $this->completeTeamMigration($teamId);
            return;
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        $this->resetTries();
    }

    private function createChannel(array $channelJson, string $teamId): ?Channel
    {
        if (strtolower($channelJson['name']) === 'general') {
            return $this->getChannelByName($channelJson, $teamId);
        }

        $this->useTry();
        $channel = new Channel();
        $channel->setDisplayName($channelJson['name']);
        $channel->setCreatedDateTime(new DateTime($channelJson['created']['iso']));
        $channel->setMembershipType(new ChannelMembershipType(ChannelMembershipType::STANDARD));
        $additionalData = [
            '@microsoft.graph.channelCreationMode' => 'migration',
        ];
        $channel->setAdditionalData($additionalData);

        if (!empty($channelJson['description'])) {
            $channel->setDescription($channelJson['description']);
        }

        try {
            $newChannel = $this->graphClient
                ->teams()
                ->byTeamId($teamId)
                ->channels()
                ->post($channel)
                ->wait();
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            return $this->createChannel($channelJson, $teamId);
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        $this->resetTries();

        return $newChannel;
    }

    private function createMessage(array $messageJson, string $teamId, string $channelId, int $index): ?ChatMessage
    {
        if (
            (isset($messageJson['author']['details']['className'])
                && $messageJson['author']['details']['className'] === 'CApplicationPrincipalDetails')
            || empty(trim($messageJson['text']))
        ) {
            return null;
        }

        $dateTime = $this->checkTimestamp($messageJson['created']['timestamp']);
        $message = new ChatMessage();
        $message->setCreatedDateTime($dateTime);
        $from = $this->getUserForMessage($messageJson);

        if (!$from) {
            return null;
        }

        $message->setFrom($from);

        $body = new ItemBody();
        $body->setContentType(new BodyType(BodyType::HTML));
        $body->setContent($messageJson['text']);
        $message->setBody($body);

        try {
            return $this->graphClient
                ->teams()
                ->byTeamId($teamId)
                ->channels()
                ->byChannelId($channelId)
                ->messages()
                ->post($message)
                ->wait();
        } catch (ApiException $e) {
            $this->print('Failed message import on message %d', $index + 1);
            $this->print('TeamID: %s', $teamId);
            $this->print('ChannelID: %s', $channelId);
            $this->print('Message:');
            var_dump($messageJson);
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            $this->failedMessages[$index] = $messageJson;
            return null;
        } catch (Exception $e) {
            $this->print('Failed message import on message %d', $index + 1);
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }
    }

    private function createTeam(string $displayName, DateTime $dateTime): ?Team
    {
        $this->useTry();
        $this->print('Create team "%s"', $displayName);
        $requestBody = new Team();
        $requestBody->setDisplayName($displayName);
        $requestBody->setDescription('Migration von JetBrains Space');
        $requestBody->setCreatedDateTime($dateTime);
        $additionalData = [
            '@microsoft.graph.teamCreationMode' => 'migration',
            'template@odata.bind' => 'https://graph.microsoft.com/v1.0/teamsTemplates(\'standard\')',
        ];
        $requestBody->setAdditionalData($additionalData);
        $requestBody->setVisibility(new TeamVisibilityType(TeamVisibilityType::PRIVATE));

        try {
            $this->graphClient
                ->teams()
                ->post($requestBody)
                ->wait();

            $this->resetTries();

            while (true) {
                $this->useTry();
                $team = $this->getTeam($displayName);

                if ($team) {
                    $this->resetTries();
                    return $team;
                }
            }
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            return $this->createTeam($displayName, $dateTime);
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }
    }

    private function getChannelsForTeam(string $teamName): array
    {
        if (!isset($this->channelTeamMapping[$teamName])) {
            return [];
        }

        $channelDirectories = scandir($this->channelDir);
        $channels = [];

        foreach ($channelDirectories as $channelDirectory) {
            if ($channelDirectory === '.' || $channelDirectory === '..') {
                continue;
            }

            $channelInfo = json_decode(
                file_get_contents($this->channelDir . '/' . $channelDirectory . '/channel.json'),
                true
            );
            $channelNameParts = explode('-', $channelInfo['name']);
            $prefix = array_shift($channelNameParts) . '-';

            if (
                !in_array($channelInfo['name'], $this->channelTeamMapping[$teamName])
                && !in_array($prefix, $this->channelTeamMapping[$teamName])
            ) {
                continue;
            }

            $channels[] = [
                'channel' => $channelInfo,
                'messages' => json_decode(
                    file_get_contents($this->channelDir . '/' . $channelDirectory . '/messages.json'),
                    true
                ),
            ];
        }

        return $channels;
    }

    private function getDateTimeForTeam(string $teamName): ?DateTime
    {
        $channels = $this->getChannelsForTeam($teamName);

        if (empty($channels)) {
            return null;
        }

        $time = 0;

        foreach ($channels as $channelInfo) {
            $channelTime = $channelInfo['channel']['created']['timestamp'];

            if ($channelTime < $time || $time === 0) {
                $time = $channelTime;
            }
        }

        return new DateTime('@' . floor($time / 1000));
    }

    private function getMember(string $mail): ?User
    {
        foreach ($this->members as $member) {
            if ($member->getMail() === $mail) {
                return $member;
            }
        }

        return null;
    }

    private function getMemberFromDetails(array $details): ?User
    {
        if (!isset($details['user']['emails'])) {
            return null;
        }

        foreach ($details['user']['emails'] as $mail) {
            $member = $this->getMember($mail['email']);
            if ($member) {
                return $member;
            }
        }

        return null;
    }

    private function getTeam(string $name): ?Team
    {
        sleep(AbstractService::SLEEP);
        $this->print('Searching for team "%s"', $name);

        try {
            $teams = $this->graphClient
                ->teams()
                ->get()
                ->wait()
                ->getValue();
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            exit(255);
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }

        foreach ($teams as $team) {
            if ($team->getDisplayName() === $name) {
                return $team;
            }
        }

        return null;
    }

    private function getUserForMessage(array $messageJson): ?ChatMessageFromIdentitySet
    {
        $from = null;
        if ($messageJson['author']['details']) {
            $member = $this->getMemberFromDetails($messageJson['author']['details']);
            if ($member) {
                $from = $this->prepareChatMessageFromMember($member);
            } else {
                $this->print('Member with "%s" not found.', $messageJson['author']['name']);
            }
        } elseif (trim(strtolower($messageJson['author']['name'])) === 'deleted') {
            $member = $this->getMember('k.schneider@s-w-e.com');
            if ($member) {
                $from = $this->prepareChatMessageFromMember($member);
            } else {
                $this->print('Member with "%s" not found.', $messageJson['author']['name']);
            }
        }

        return $from;
    }

    private function importChannels(Team $team): void
    {
        $channels = $this->getChannelsForTeam($team->getDisplayName());

        if (empty($channels)) {
            $this->print('No channels found for team "%s"', $team->getDisplayName());
            return;
        }

        $teamId = $team->getId();
        $this->print('Import channels for team "%s"', $team->getDisplayName());

        foreach ($channels as $channelInfo) {
            $this->print('');
            $this->print(
                'Import channel "%s" into team "%s"',
                $channelInfo['channel']['name'],
                $team->getDisplayName()
            );
            $channel = $this->createChannel($channelInfo['channel'], $teamId);

            if (!$channel) {
                $this->print('Could not create channel "%s"', $channelInfo['channel']['name']);
                continue;
            }

            $channelId = $channel->getId();

            $this->print('Channel created. Import %d messages...', $channelInfo['channel']['totalMessages']);
            $totalMessages = $this->importMessages($channelInfo['messages'], $teamId, $channelId, 0);
            $this->resetTries();

            while (!empty($this->failedMessages)) {
                $this->useTry();
                $totalMessages = $this->importMessages($this->failedMessages, $teamId, $channelId, $totalMessages);
            }

            $this->resetTries();

            $this->print('Imported %d messages.', $totalMessages);
            $this->completeChannelMigration($teamId, $channelId);
        }
    }

    private function importMessages(array $messages, string $teamId, string $channelId, int $totalMessages): int
    {
        sleep(AbstractService::MESSAGE_SLEEPER);
        $skipIndex = 0;

        foreach ($messages as $index => $message) {
            $chatMessage = $this->createMessage($message, $teamId, $channelId, $index);

            if ($chatMessage) {
                $totalMessages++;
                if (isset($this->failedMessages[$index])) {
                    unset($this->failedMessages[$index]);
                }
            } else {
                $this->print('Skipped message %d', $index + 1);
            }

            if (($skipIndex + 1) % AbstractService::MESSAGES_PER_SECOND === 0) {
                sleep(AbstractService::MESSAGE_SLEEPER);
            }

            $skipIndex++;
        }

        return $totalMessages;
    }

    private function prepareChatMessageFromMember(User $member): ChatMessageFromIdentitySet
    {
        $from = new ChatMessageFromIdentitySet();
        $fromUser = new Identity();
        $fromUser->setId($member->getId());
        $fromUser->setDisplayName($member->getDisplayName());
        $additionalData = ['userIdentityType' => 'aadUser'];
        $fromUser->setAdditionalData($additionalData);
        $from->setUser($fromUser);

        return $from;
    }

    private function printStatusError(int $statusCode): void
    {
        $response = new Response($statusCode);
        $this->print('Request returned status code: %d (%s)', $statusCode, $response->getReasonPhrase());
    }

    private function removeTeams(): void
    {
        try {
            $this->print('Get all teams');
            $allTeams = $this->graphClient
                ->teams()
                ->get()
                ->wait()
                ->getValue();

            $this->print('Found %d teams', count($allTeams));
            foreach ($allTeams as $team) {
                if (in_array($team->getDisplayName(), array_keys($this->channelTeamMapping))) {
                    $channels = $this->graphClient
                        ->teams()
                        ->byTeamId($team->getId())
                        ->channels()
                        ->get()
                        ->wait()
                        ->getValue();

                    foreach ($channels as $channel) {
                        if ($channel->getDisplayName() === 'General') {
                            continue;
                        }

                        $this->print(
                            'Remove channel "%s" in team "%s"',
                            $channel->getDisplayName(),
                            $team->getDisplayName()
                        );
                        $this->graphClient
                            ->teams()
                            ->byTeamId($team->getId())
                            ->channels()
                            ->byChannelId($channel->getId())
                            ->delete()
                            ->wait();
                    }

                    $this->print('Remove group "%s"', $team->getDisplayName());
                    $this->graphClient
                        ->groups()
                        ->byGroupId($team->getId())
                        ->delete()
                        ->wait();
                }
            }
        } catch (ApiException $e) {
            $this->printStatusError($e->getResponseStatusCode());
            $this->print('Error message: %s', $e->getMessage());
            exit(255);
        } catch (Exception $e) {
            $this->print('Something went wrong in %s "%s"', __METHOD__, $e->getMessage());
            exit(255);
        }
    }
}