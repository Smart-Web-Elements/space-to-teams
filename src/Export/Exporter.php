<?php

namespace App\Export;

use App\AbstractService;
use Swe\SpaceSDK\HttpClient;
use Swe\SpaceSDK\Space;

class Exporter extends AbstractService
{
    private readonly Space $space;

    private int $totalMessages = 0;

    private bool $cleanUp = true;

    public function __construct(string $channelDir, bool $printOutput = true)
    {
        parent::__construct($channelDir, $printOutput);

        $client = new HttpClient(
            $_ENV['SPACE_URL'],
            $_ENV['SPACE_CLIENT_ID'],
            $_ENV['SPACE_CLIENT_SECRET']
        );
        $this->space = new Space($client);
    }

    public function export(): void
    {
        if ($this->cleanUp) {
            $this->print('Cleanup directories');
            $this->cleanUpDir($this->channelDir);
        }

        $channels = $this->getChannels();

        foreach ($channels['data'] as $channel) {
            $this->exportChannelWithMessages($channel);
        }

        $this->print('');
        $this->print('--------------------------------');
        $this->print('');
        $this->print('Total messages exported: %s', $this->format($this->totalMessages));
        $this->print(
            'Import the messages into Microsoft Teams will take %s',
            $this->getEstimatedTime($this->totalMessages)
        );
    }

    public function setCleanUp(bool $cleanUp): void
    {
        $this->cleanUp = $cleanUp;
    }

    private function addMessagesToFile(array $channel, ?string $startDate = null, array $messages = []): int
    {
        $batchSize = 50;
        $responseFormat = [
            'nextStartFromDate',
            'orgLimitReached',
            'messages' => [
                'archived',
                'author' => [
                    'details' => [
                        'user' => [
                            'emails' => [
                                'email',
                            ],
                        ],
                    ],
                    'name',
                ],
                'created',
                'text',
            ],
        ];

        $response = $this->space->chats()->messages()->getChannelMessages(
            [
                'channel' => 'id:' . $channel['channelId'],
                'sorting' => 'FromOldestToNewest',
                'startFromDate' => $startDate,
                'batchSize' => $batchSize,
            ],
            $responseFormat
        );

        $fileName = $this->channelDir . '/' . $channel['channelId'] . '/messages.json';

        $messages = array_merge($messages, $response['messages']);
        $messageQuantity = count($response['messages']);

        if (!$response['orgLimitReached'] && count($response['messages']) === $batchSize) {
            $messageQuantity += $this->addMessagesToFile($channel, $response['nextStartFromDate']['iso'], $messages);
        } else {
            file_put_contents($fileName, $this->toJson($messages), FILE_APPEND);
        }

        return $messageQuantity;
    }

    private function cleanUpDir(string $dir): void
    {
        if (is_dir($dir)) {
            $this->removeDir($dir);
        }
    }

    private function exportChannelWithMessages(array $channel): void
    {
        if (in_array($channel['name'], $this->skipChannels)) {
            $this->print('Skipping channel "%s"', $channel['name']);
            return;
        }

        $channelDir = $this->channelDir . '/' . $channel['channelId'];

        if (!is_dir($channelDir)) {
            mkdir($channelDir, 0777, true);
        }

        file_put_contents($channelDir . '/channel.json', $this->toJson($channel));

        $this->print('Adding %s messages from channel "%s"', $this->format($channel['totalMessages']), $channel['name']);
        $addedMessages = $this->addMessagesToFile($channel);

        $this->print('Added %s messages.', $this->format($addedMessages));
        $this->totalMessages += $addedMessages;
        $this->print('');
    }

    private function getChannels(): array
    {
        return $this->space->chats()->channels()->listAllChannels(
            [
                'query' => '',
                'withArchived' => false,
            ],
            [
                'data' => [
                    'channelId',
                    'created',
                    'description',
                    'name',
                    'totalMessages',
                ],
            ]
        );
    }

    private function removeDir(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    if (is_dir($dir . '/' . $object)) {
                        $this->removeDir($dir . '/' . $object);
                    } else {
                        unlink($dir . '/' . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
}