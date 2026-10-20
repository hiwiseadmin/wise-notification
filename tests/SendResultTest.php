<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\contract\SendResult;
use wise\notification\contract\BatchSendResult;

/**
 * SendResult & BatchSendResult 值对象测试
 *
 * 测试构造、toArray、isSuccess 判断、fromResults 工厂方法、getFailed 等。
 */
class SendResultTest extends TestCase
{
    // ── SendResult 测试 ──────────────────────────────────────────

    /** @test */
    public function send_result_creates_successful_result(): void
    {
        $result = new SendResult(
            success: true,
            channel: 'email',
            response: 'OK',
        );

        $this->assertTrue($result->success);
        $this->assertSame('email', $result->channel);
        $this->assertSame('OK', $result->response);
        $this->assertNull($result->error);
        $this->assertSame(0, $result->durationMs);
    }

    /** @test */
    public function send_result_creates_failed_result(): void
    {
        $result = new SendResult(
            success: false,
            channel: 'sms',
            error: 'Connection timeout',
            durationMs: 1500,
        );

        $this->assertFalse($result->success);
        $this->assertSame('sms', $result->channel);
        $this->assertNull($result->response);
        $this->assertSame('Connection timeout', $result->error);
        $this->assertSame(1500, $result->durationMs);
    }

    /** @test */
    public function send_result_toArray_returns_correct_structure(): void
    {
        $result = new SendResult(
            success: true,
            channel: 'database',
            response: '{"id": 1}',
            durationMs: 42,
        );

        $expected = [
            'success'     => true,
            'channel'     => 'database',
            'response'    => '{"id": 1}',
            'error'       => null,
            'duration_ms' => 42,
        ];

        $this->assertSame($expected, $result->toArray());
    }

    /** @test */
    public function send_result_toArray_handles_null_response_and_error(): void
    {
        $result = new SendResult(
            success: false,
            channel: 'dingtalk',
        );

        $arr = $result->toArray();

        $this->assertFalse($arr['success']);
        $this->assertNull($arr['response']);
        $this->assertNull($arr['error']);
    }

    /** @test */
    public function send_result_durationMs_defaults_to_zero(): void
    {
        $result = new SendResult(success: true, channel: 'email');

        $this->assertSame(0, $result->durationMs);
    }

    // ── BatchSendResult 测试 ─────────────────────────────────────

    /** @test */
    public function batch_send_result_fromResults_counts_correctly(): void
    {
        $results = [
            new SendResult(success: true, channel: 'email', response: 'OK'),
            new SendResult(success: true, channel: 'sms', response: 'OK'),
            new SendResult(success: false, channel: 'dingtalk', error: 'Timeout'),
        ];

        $batch = BatchSendResult::fromResults($results);

        $this->assertSame(3, $batch->total);
        $this->assertSame(2, $batch->successCount);
        $this->assertSame(1, $batch->failedCount);
        $this->assertCount(3, $batch->results);
    }

    /** @test */
    public function batch_send_result_fromResults_handles_empty_array(): void
    {
        $batch = BatchSendResult::fromResults([]);

        $this->assertSame(0, $batch->total);
        $this->assertSame(0, $batch->successCount);
        $this->assertSame(0, $batch->failedCount);
        $this->assertSame([], $batch->results);
    }

    /** @test */
    public function batch_send_result_fromResults_all_success(): void
    {
        $results = [
            new SendResult(success: true, channel: 'email'),
            new SendResult(success: true, channel: 'sms'),
            new SendResult(success: true, channel: 'database'),
        ];

        $batch = BatchSendResult::fromResults($results);

        $this->assertSame(3, $batch->total);
        $this->assertSame(3, $batch->successCount);
        $this->assertSame(0, $batch->failedCount);
    }

    /** @test */
    public function batch_send_result_fromResults_all_failed(): void
    {
        $results = [
            new SendResult(success: false, channel: 'email', error: 'Err1'),
            new SendResult(success: false, channel: 'sms', error: 'Err2'),
        ];

        $batch = BatchSendResult::fromResults($results);

        $this->assertSame(2, $batch->total);
        $this->assertSame(0, $batch->successCount);
        $this->assertSame(2, $batch->failedCount);
    }

    /** @test */
    public function getFailed_returns_only_failed_results(): void
    {
        $r1 = new SendResult(success: true, channel: 'email');
        $r2 = new SendResult(success: false, channel: 'sms', error: 'E1');
        $r3 = new SendResult(success: true, channel: 'database');
        $r4 = new SendResult(success: false, channel: 'dingtalk', error: 'E2');

        $batch = BatchSendResult::fromResults([$r1, $r2, $r3, $r4]);

        $failed = $batch->getFailed();

        $this->assertCount(2, $failed);
        $this->assertSame($r2, $failed[0]);
        $this->assertSame($r4, $failed[1]);
    }

    /** @test */
    public function getFailed_returns_empty_when_all_success(): void
    {
        $results = [
            new SendResult(success: true, channel: 'email'),
            new SendResult(success: true, channel: 'sms'),
        ];

        $batch = BatchSendResult::fromResults($results);

        $this->assertSame([], $batch->getFailed());
    }

    /** @test */
    public function batch_send_result_toArray_returns_correct_structure(): void
    {
        $results = [
            new SendResult(success: true, channel: 'email', response: 'OK', durationMs: 100),
            new SendResult(success: false, channel: 'sms', error: 'Timeout', durationMs: 5000),
        ];

        $batch = BatchSendResult::fromResults($results);
        $arr = $batch->toArray();

        $this->assertSame(2, $arr['total']);
        $this->assertSame(1, $arr['success_count']);
        $this->assertSame(1, $arr['failed_count']);
        $this->assertCount(2, $arr['results']);

        $this->assertTrue($arr['results'][0]['success']);
        $this->assertSame('email', $arr['results'][0]['channel']);
        $this->assertFalse($arr['results'][1]['success']);
        $this->assertSame('Timeout', $arr['results'][1]['error']);
    }

    /** @test */
    public function batch_send_result_has_accessible_results_property(): void
    {
        $r = new SendResult(success: true, channel: 'email');
        $batch = BatchSendResult::fromResults([$r]);

        $this->assertSame([$r], $batch->results);
        $this->assertSame(1, $batch->total);
    }
}
