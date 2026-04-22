<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Support;

use Throwable;

final class HookManager
{
    /** @var array<string, array<int, array{callback: callable, priority: int}>> */
    private array $hooks = [];

    public function register(HookPoint $point, callable $callback, int $priority = 0): void
    {
        $this->hooks[$point->value][] = ['callback' => $callback, 'priority' => $priority];
        usort(
            $this->hooks[$point->value],
            static fn(array $a, array $b) => $a['priority'] <=> $b['priority']
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param null|callable(Throwable, HookPoint, array<string, mixed>): void $onException
     * @return array<string, mixed>
     */
    public function fire(HookPoint $point, array $context = [], ?callable $onException = null): array
    {
        $hooks = $this->hooks[$point->value] ?? [];
        foreach ($hooks as $hook) {
            try {
                $result = ($hook['callback'])($context);
            } catch (Throwable $exception) {
                if ($onException === null) {
                    throw $exception;
                }

                $onException($exception, $point, $context);
                continue;
            }

            if (is_array($result)) {
                $context = array_merge($context, $result);
            }
        }
        return $context;
    }

    public function hasHooks(HookPoint $point): bool
    {
        return !empty($this->hooks[$point->value]);
    }
}
