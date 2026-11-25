<?php

declare(strict_types=1);

namespace wise\notification\tests\helpers;

/**
 * 队列任务替身
 *
 * 真实 `think\queue\Job` 是抽象类（原桩类是可实例化的普通类），
 * 测试只需要 delete/isDeleted 这类状态方法，这里补上三个抽象方法即可。
 */
class TestJob extends \think\queue\Job
{
    public function __construct()
    {
    }

    public function getJobId()
    {
        return 'test-job-id';
    }

    public function attempts()
    {
        return 0;
    }

    public function getRawBody()
    {
        return '';
    }
}
