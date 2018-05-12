<?php
class ProxyServer
{
    protected $clients;
    protected $backends;
    protected $serv;

    function run()
    {
        $serv = new swoole_server("127.0.0.1", 9509);
        $serv->set(array(
            'worker_num' => 10, //reactor thread num
            'backlog' => 128, //listen backlog
            'max_connection' => 255,
            'dispatch_mode' => 2,
            'daemonize'=>false,
        ));
        $serv->on('WorkerStart', array($this, 'onStart'));
        $serv->on('Connect', array($this, 'onConnect'));
        $serv->on('Receive', array($this, 'onReceive'));
        $serv->on('Close', array($this, 'onClose'));
        $serv->on('WorkerStop', array($this, 'onShutdown'));
        //swoole_server_addtimer($serv, 2);
        #swoole_server_addtimer($serv, 10);
        $serv->start();
    }

    function onStart($serv)
    {
        $this->serv = $serv;
       // var_dump($this->serv);
        echo "Server: start.Swoole version is [" . SWOOLE_VERSION . "]\n";
    }

    function onShutdown($serv)
    {
        echo "Server: onShutdown\n";
    }

    function onClose($serv, $fd, $from_id)
    {
        //backend
        if (isset($this->clients[$fd])) {
            $backend_client = $this->clients[$fd]['socket'];
            unset($this->clients[$fd]);
            $backend_client->close();
            unset($this->backends[$backend_client->sock]);
            echo "client close\n";
        }
    }

    function onConnect($serv, $fd, $from_id)
    {
        $socket = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);//创建代理客户端
        echo microtime() . ": Client[$fd] backend-sock[{$socket->sock}]: Connect.\n";
       
        $socket->on('connect', function ($cli) {
            echo "connect to backend server success\n";
        });
        $socket->on('error', function ($cli) {
            echo "connect to backend server fail\n";
        });
        $socket->on('close', function ($cli) {
            echo "close to backend server success\n";
        });
        $socket->on('receive', function ($cli,$data){
            var_dump($cli->sock);
            $this->serv->send($this->backends[$cli->sock]['client_fd'],$data);//代理服务器给客户端发送数据
        });
        $socket->connect('127.0.0.1', 9502, 0.2);
        //$socket->sock的文件描述符,用于后端服务器返回数据给代理客户端,找到socket对应客户端fd
        $this->backends[$socket->sock] = array(
            'client_fd' => $fd,//客户端fd
            'socket' => $socket,//创建代理异步客户端对象
        );
        $this->clients[$fd] = array(
            'socket' => $socket,
            'client_fd' => $fd,////创建代理异步客户端对象
        );
    }

    function onReceive($serv, $fd, $from_id, $data)
    {
        echo microtime() . ": client receive\n";
        $backend_socket = $this->clients[$fd]['socket'];
        $backend_socket->send($data);
        echo microtime() . ": send to backend\n";
        echo str_repeat('-', 100) . "\n";
    }
}

$serv = new ProxyServer();
$serv->run();