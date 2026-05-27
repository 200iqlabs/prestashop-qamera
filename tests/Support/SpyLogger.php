<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Webhook\WebhookLogger;

final class SpyLogger implements WebhookLogger
{
    /** @var list<array{level:string, message:string, context:array<string, string|int|null>}> */
    public array $entries = [];

    public function info(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    /**
     * @return list<array{level:string, message:string, context:array<string, string|int|null>}>
     */
    public function entriesAtLevel(string $level): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['level'] === $level
        ));
    }

    public function dumpAsText(): string
    {
        $lines = [];
        foreach ($this->entries as $e) {
            $line = $e['level'] . ' ' . $e['message'];
            foreach ($e['context'] as $k => $v) {
                $line .= ' ' . $k . '=' . (string) ($v ?? '-');
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
