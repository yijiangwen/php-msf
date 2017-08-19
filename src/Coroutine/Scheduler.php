<?php
/**
 * 协程调度器
 *
 * @author camera360_server@camera360.com
 * @copyright Chengdu pinguo Technology Co.,Ltd.
 */

namespace PG\MSF\Coroutine;

use PG\MSF\Base\Core;
use PG\MSF\Controllers\Controller;
use PG\MSF\Helpers\Context;
use PG\MSF\Marco;

class Scheduler
{
    /**
     * 正在运行的IO协程对象列表
     *
     * @var array
     */
    public $IOCallBack = [];

    /**
     * 所有正在调度的协程任务（即请求）
     *
     * @var array
     */
    public $taskMap = [];

    /**
     * 初始化协程调度器
     */
    public function __construct()
    {
        /**
         * 每隔1s检查超时的协程任务
         */
        getInstance()->sysTimers[] = swoole_timer_tick(1000, function ($timerId) {
            // 当前进程的协程统计信息
            if (getInstance()::mode != 'console') {
                $this->stat();
            }

            if (empty($this->IOCallBack)) {
                return true;
            }

            foreach ($this->IOCallBack as $logId => $callBacks) {
                foreach ($callBacks as $key => $callBack) {
                    if ($callBack->ioBack) {
                        continue;
                    }

                    if ($callBack->isTimeout()) {
                        if (!empty($this->taskMap[$logId])) {
                            $this->schedule($this->taskMap[$logId]);
                        }
                    }
                }
            }
        });

        /**
         * 每隔300s清理对象池中的对象
         */
        swoole_timer_tick(300000, function ($timerId) {
            if (!empty(getInstance()->objectPool->map)) {
                foreach (getInstance()->objectPool->map as $class => &$objectsMap) {
                    while ($objectsMap->count()) {
                        $obj = $objectsMap->shift();
                        if ($obj instanceof Core) {
                            $obj->setRedisPools(null);
                            $obj->setRedisProxies(null);
                        }

                        if ($obj instanceof Controller) {
                            $obj->__getObjectPool()->destroy();
                            $obj->setObjectPool(null);
                        }
                        $obj = null;
                        unset($obj);
                    }
                }
            }
        });
    }

    /**
     * Worker进程协程统计信息写入共享内存
     */
    public function stat()
    {
        $data = [
            // 进程ID
            'pid' => 0,
            // 协程统计信息
            'coroutine' => [
                // 当前正在处理的请求数
                'total' => 0,
            ],
            // 内存使用
            'memory' => [
                // 峰值
                'peak' => '',
                // 当前使用
                'usage' => '',
            ],
            // 请求信息
            'request' => [
                // 当前Worker进程收到的请求次数
                'worker_request_count' => 0,
            ],
            // 其他对象池
            'object_poll' => [
                // 'xxx' => 22
            ],
            // 控制器对象池
            'controller_poll' => [
                // 'xxx' => 22
            ],
            // Model对象池
            'model_poll' => [
                // 'xxx' => 22
            ],
            // Http DNS Cache
            'dns_cache_http' => [
                // domain => [ip, time(), times]
            ],
            // Tcp DNS Cache
            'dns_cache_tcp' => [
                // domain => [ip, time(), times]
            ],
        ];
        $routineList = getInstance()->coroutine->taskMap;
        $data['pid'] = getInstance()->server->worker_pid;
        $data['coroutine']['total'] = count($routineList);
        $data['memory']['peak_byte'] = memory_get_peak_usage();
        $data['memory']['usage_byte'] = memory_get_usage();
        $data['memory']['peak'] = strval(number_format($data['memory']['peak_byte'] / 1024 / 1024, 3, '.', '')) . 'M';
        $data['memory']['usage'] = strval(number_format($data['memory']['usage_byte'] / 1024 / 1024, 3, '.', '')) . 'M';
        $data['request']['worker_request_count'] = getInstance()->server->stats()['worker_request_count'];

        if (!empty(getInstance()->objectPool->map)) {
            foreach (getInstance()->objectPool->map as $class => $objects) {
                if (APPLICATION_ENV == 'docker' && function_exists('refcount')) {
                    foreach ($objects as $object) {
                        $data['object_pool'][$class][] = [
                            'gen_time' => property_exists($object, '__genTime') ? $object->__genTime : 0,
                            'use_count' => property_exists($object, '__useCount') ? $object->__useCount : 0,
                            'ref_count' => refcount($object) - 1,
                        ];
                    }
                } else {
                    $data['object_pool'][$class] = $objects->count() + $data['coroutine']['total'];
                }
            }
        }

        $data['dns_cache_http'] = \PG\MSF\Client\Http\Client::$dnsCache;
        getInstance()->sysCache->set(Marco::SERVER_STATS . getInstance()->server->worker_id, $data);
    }

    /**
     * 调度协程任务（请求）
     *
     * @param Task $task
     * @return $this
     */
    public function schedule(Task $task)
    {
        /* @var $task Task */
        $task->run();

        try {
            if ($task->getRoutine()->valid() && ($task->getRoutine()->current() instanceof IBase)) {
            } else {
                if ($task->isFinished()) {
                    $task->resetRoutine();
                    if (is_callable($task->getCallBack())) {
                        ($task->getCallBack())();
                        $task->resetCallBack();
                    }
                } else {
                    $this->schedule($task);
                }
            }
        } catch (\Throwable $e) {
            $task->setException($e);
            $this->schedule($task);
        }

        return $this;
    }


    /**
     * 开始执行调度请求
     *
     * @param \Generator $routine
     * @param Context $context
     * @param Controller $controller
     * @param callable|null $callBack
     */
    public function start(\Generator $routine, Context $context, Controller $controller, callable $callBack = null)
    {
        $task = $context->getObjectPool()->get(Task::class, $routine, $context, $controller, $callBack);
        $this->IOCallBack[$context->getLogId()] = [];
        $this->taskMap[$context->getLogId()]    = $task;
        $this->schedule($task);
    }
}
