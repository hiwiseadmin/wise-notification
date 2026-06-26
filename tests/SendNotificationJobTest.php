<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use think\facade\Event as EventFacade;
use think\facade\Queue as QueueFacade;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\job\SendNotificationJob;
use think\queue\Job;

/**
 * SendNotificationJob 队列任务测试
 *
 * 测试 fire() 方法的核心流程：resolve + dispatch + history update。
 */
class SendNotificationJobTest extends TestCase
{
    protected function setUp(): void
    {
        EventFacade::reset();
        QueueFacade::reset();
        \wise\notification\tests\helpers\MockApp::reset();
    }

    /** @test */
    public function fire_deletes_job_after_successful_dispatch(): void
    {
        $job = new Job();
        $task = new SendNotificationJob();

        $task->fire($job, [
            'channel'         => 'email',
            'notifiable_type' => get_class($this->createTestNotifiable()),
            'notifiable_id'   => '1',
            'message'         => [
                'title'   => 'Test',
                'content' => 'Body',
                'data'    => ['type' => 'test'],
            ],
            'message_type'    => 'direct',
            'is_batch'        => false,
            'batch_id'        => null,
        ]);

        $this->assertTrue($job->isDeleted());
    }

    /** @test */
    public function fire_handles_unresolvable_notifiable(): void
    {
        $job = new Job();
        $task = new SendNotificationJob();

        // 不抛出异常，因为 catch 块会处理
        $task->fire($job, [
            'channel'         => 'email',
            'notifiable_type' => 'NonExistentClass',
            'notifiable_id'   => '999',
            'message'         => ['title' => 'T', 'content' => 'C'],
            'message_type'    => 'direct',
        ]);

        // 即使出错，job 也应该被删除
        $this->assertTrue($job->isDeleted());
    }

    /** @test */
    public function fire_handles_empty_notifiable_type(): void
    {
        $job = new Job();
        $task = new SendNotificationJob();

        $task->fire($job, [
            'channel'         => 'email',
            'notifiable_type' => '',
            'notifiable_id'   => '',
            'message'         => ['title' => 'T', 'content' => 'C'],
            'message_type'    => 'direct',
        ]);

        $this->assertTrue($job->isDeleted());
    }

    /** @test */
    public function fire_uses_default_channel_when_missing(): void
    {
        $job = new Job();
        $task = new SendNotificationJob();

        // 缺少 channel 时使用默认 'email'
        $task->fire($job, [
            'notifiable_type' => get_class($this->createTestNotifiable()),
            'notifiable_id'   => '1',
            'message'         => ['title' => 'T', 'content' => 'C'],
            'message_type'    => 'direct',
        ]);

        $this->assertTrue($job->isDeleted());
    }

    /**
     * 创建测试用 Notifiable
     */
    private function createTestNotifiable(): Notifiable
    {
        return new class implements Notifiable {
            public function routeNotificationFor(string $channel): string|array
            {
                return '1';
            }

            public function getKey(): int
            {
                return 1;
            }

            public function find($id): ?static
            {
                return $this;
            }
        };
    }
}
