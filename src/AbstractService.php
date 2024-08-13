<?php

namespace App;

use Dotenv\Dotenv;

abstract class AbstractService
{
    public const int MESSAGES_PER_SECOND = 5;
    public const int TRIES = 10;
    public const int SLEEP = 5;
    public const int MESSAGE_SLEEPER = 2;

    protected array $skipChannels = [];

    protected int $tries;

    public function __construct(protected readonly string $channelDir, protected readonly bool $printOutput = true)
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->load();
        $this->tries = AbstractService::TRIES;
    }

    protected function resetTries(): void
    {
        $this->tries = AbstractService::TRIES;
    }

    protected function useTry(): void
    {
        $this->tries--;

        if ($this->tries < 0) {
            $this->print('Out of tries!');
            exit(255);
        }
    }

    public function doNotSkipChannel(string $channelName): void
    {
        if (($key = array_search($channelName, $this->skipChannels)) !== false) {
            unset($this->skipChannels[$key]);
        }
    }

    public function skipChannel(string $channelName): void
    {
        $this->skipChannels = array_unique(array_merge($this->skipChannels, [$channelName]));
    }

    protected function print(string $format, string ...$arguments): void
    {
        if ($this->printOutput) {
            echo vsprintf($format, $arguments) . "\n";
        }
    }

    protected function toJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function getEstimatedTime(int $quantity): string
    {
        $pauses = floor($quantity / self::MESSAGES_PER_SECOND);
        $totalSeconds = $quantity / self::MESSAGES_PER_SECOND + $pauses * self::MESSAGE_SLEEPER;

        return $this->toSimpleTime($totalSeconds);
    }

    protected function toSimpleTime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds / 60) % 60);
        $seconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    protected function format(int $number): string
    {
        return number_format($number, 0, '', '.');
    }
}