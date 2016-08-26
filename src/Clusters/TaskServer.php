<?php

namespace MyQEE\Server\Clusters;

use MyQEE\Server\Server;

/**
 * 任务服务器
 *
 * @package MyQEE\Server\TaskServer
 */
class TaskServer
{
    /**
     * @var \Swoole\Server
     */
    protected $server;

    protected $id;

    /**
     * TaskServer constructor.
     */
    public function __construct()
    {

    }

    public function start($ip, $port)
    {
        if (!Host::$table)
        {
            # Host还没初始化, 需要初始化
            Host::init(false);
        }

        # 初始化任务服务器
        $server         = new \Swoole\Server($ip, $port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
        Server::$server = $server;
        $this->server   = $server;

        $config = [
            'dispatch_mode'      => 5,
            'worker_num'         => Server::$config['swoole']['task_worker_num'],
            'max_request'        => Server::$config['swoole']['task_max_request'],
            'task_worker_num'    => 0,
            'package_max_length' => 1024 * 1024 * 50,
            'task_tmpdir'        => Server::$config['swoole']['task_tmpdir'],
            'buffer_output_size' => Server::$config['swoole']['buffer_output_size'],
            'open_eof_check'     => true,
            'open_eof_split'     => true,
            'package_eof'        => \MyQEE\Server\RPC\Server::$EOF,
        ];

        $server->set($config);
        $server->on('WorkerStart', [$this, 'onStart']);
        $server->on('Receive',     [$this, 'onReceive']);
        $server->on('Start', function() use ($ip, $port)
        {
            Server::$instance->info("task sever tcp://$ip:$port start success.");
        });

        $server->start();
    }

    public function onStart()
    {
        if ($this->server->worker_id === 0)
        {
            $id = isset(Server::$config['clusters']['id']) && Server::$config['clusters']['id'] >= 0 ? (int)Server::$config['clusters']['id'] : -1;
            \MyQEE\Server\Register\Client::init(Server::$config['clusters']['group'] ?: 'default', $id, true);
        }

        global $argv;
        $className = Server::$namespace. 'WorkerTask';

        if (!class_exists($className))
        {
            if ($this->id === 0)
            {
                Server::$instance->warn("任务进程 $className 类不存在");
            }
            $className = '\\MyQEE\\Server\\WorkerTask';
        }

        # 内存限制
        ini_set('memory_limit', Server::$config['server']['task_worker_memory_limit'] ?: '4G');

        Server::setProcessName("php ". implode(' ', $argv) ." [taskServer#$this->id]");

        # 启动任务进度对象
        Server::$workerTask         = new $className($this->server);
        Server::$workerTask->id     = $this->id;
        Server::$workerTask->taskId = $this->id;
        Server::$workerTask->onStart();
    }

    public function onReceive($server, $fd, $fromId, $data)
    {
        /**
         * @var \Swoole\Server $server
         */
        $tmp = @msgpack_unpack($data);

        if ($tmp && is_object($tmp))
        {
            $data = $tmp;
            unset($tmp);
            if ($data instanceof \stdClass)
            {
                if ($data->bind)
                {
                    # 绑定进程ID
                    $server->bind($fd, $data->id);

                    return;
                }

                if ($key = \MyQEE\Server\Register\Client::$host->key)
                {
                    # 需要解密
                    $data = \MyQEE\Server\RPC\Server::decryption($data, $key);

                    # 解密失败
                    if (!$data)return;
                }

                $eof = \MyQEE\Server\RPC\Server::$EOF;
                switch ($data->type)
                {
                    case 'task':
                        $rs = Server::$workerTask->onTask($server, $data->id, $data->wid, $data->data, $data->sid);

                        if ($rs !== null)
                        {
                            # 执行 Finish
                            $rsData        = new \stdClass();
                            $rsData->id    = $data->id;
                            $rsData->data  = $rs;
                            $rsData->wname = $data->wname;

                            if ($key)
                            {
                                # 加密数据
                                $rsData = \MyQEE\Server\RPC\Server::encrypt($rsData, $key) . $eof;
                            }
                            else
                            {
                                # 格式化数据
                                $rsData = msgpack_pack($rsData) . $eof;
                            }

                            $server->send($fd, $rsData, $fromId);
                        }

                        break;
                    case 'taskWait':
                }
            }
        }
        else
        {
            Server::$instance->warn("task server get error msgpack data length: ". strlen($data));
            Server::$instance->debug($data);
            $this->server->close($fd);
        }
    }
}