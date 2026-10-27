<?php

declare(strict_types=1);

/*
 * This file is part of the gilles-g/dump-loader-bundle package.
 *
 * (c) gilles-g <ggauthier@spiriit.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DumpLoader\LoadJob;

use DumpLoader\Dump\Dumps;
use DumpLoader\Step\OptionalStep;
use DumpLoader\Step\StepDefinition;

final readonly class Dashboard
{
    /**
     * @param list<StepDefinition> $availableOptions
     */
    private function __construct(
        public Dumps $dumps,
        public array $availableOptions,
        public LoadOptions $defaultOptions,
        public ?LoadJobSnapshot $activeJob,
        public ?string $unreadableJobDetail,
        public LoadJobs $history,
        public ?string $targetDatabase,
        public ?\DateTimeImmutable $lastCompletedAt,
        public int $completedCount,
        public int $failedCount,
    ) {
    }

    /**
     * @param list<OptionalStep> $options
     */
    public static function fromCurrentState(
        Dumps $dumps,
        array $options,
        ?LoadJobSnapshot $activeJob,
        ?string $unreadableJobDetail,
        LoadJobs $history,
        ?string $targetDatabase,
    ): self {
        $definitions = [];
        $defaults = [];

        foreach ($options as $option) {
            $definition = $option->definition();
            $definitions[] = $definition;

            if ($option->enabledByDefault()) {
                $defaults[] = $definition->name;
            }
        }

        $lastCompletedAt = null;
        $completedCount = 0;
        $failedCount = 0;

        foreach ($history as $snapshot) {
            if (LoadJobStatus::Completed === $snapshot->status) {
                ++$completedCount;

                if (null !== $snapshot->finishedAt && (null === $lastCompletedAt || $snapshot->finishedAt > $lastCompletedAt)) {
                    $lastCompletedAt = $snapshot->finishedAt;
                }
            }

            if (LoadJobStatus::Failed === $snapshot->status) {
                ++$failedCount;
            }
        }

        return new self(
            dumps: $dumps,
            availableOptions: $definitions,
            defaultOptions: LoadOptions::fromNames($defaults),
            activeJob: $activeJob,
            unreadableJobDetail: $unreadableJobDetail,
            history: $history,
            targetDatabase: $targetDatabase,
            lastCompletedAt: $lastCompletedAt,
            completedCount: $completedCount,
            failedCount: $failedCount,
        );
    }
}
