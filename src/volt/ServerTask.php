<?php
namespace volt;
use pocketmine\Thread;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class ServerTask extends Thread{
    private $sock;
    private $pool;
    private $logger;
    private $config;
    private $valueStore;
    private $bannedUsers;
    /** @var  callable[] */
    private $helpers;
    public $stop, $path;
    public function __construct($path, \ClassLoader $loader, \Logger $logger, Config $config, array $bannedips) {
        $this->stop = false;
        $this->pool = new \Pool($config->get('pool-size'), \Worker::CLASS);
        $this->valueStore = serialize([]);
        $this->templates = serialize([]);
        $this->logger = $logger;
        $this->path = $path;
        $this->config = $config;
        $this->bannedUsers = $bannedips;
        $this->helpers = [];
        $this->loader = unserialize(serialize($loader));
        try {
            $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            //socket_set_option ($this->sock, SOL_SOCKET, SO_REUSEADDR, 1);
            socket_bind($this->sock, "0.0.0.0", $this->config->get("server-port"));
            socket_listen($this->sock, 5);
            socket_set_nonblock($this->sock);

            $this->getLogger()->info("[SUCCESS] Server Status: " . TextFormat::GREEN . "Active" . TextFormat::WHITE . " on port " . volt::$serverConfig->get("server-port") . "\n");
            $this->start();
        }
        catch(\RuntimeException $e){
            $this->getLogger()->critical("The server encountered an error while initialising itself. Maybe you have an instance already running?");
            $this->stop();
        }
    }
    public function stop() {
        $this->getLogger()->info("Server Status: " . TextFormat::RED . "Stopped\n");
        $this->stop = true;
    }
    public function run() {
        $this->loader->register(true);
        while($this->stop === false) {
            if (($msgsock = @socket_accept($this->sock)) === false) {
                continue;
            }
            socket_getpeername($msgsock, $address);
            if(!in_array($address, $this->bannedUsers)){
                $client = new ClientTask($msgsock, $this->loader, $this->getLogger(), $this->path, $this->config, $this->templates, $this->helpers, $this);
                $this->pool->submit($client);
            }
        }
        $this->pool->collect(function(ClientTask $client){
            $client->close();
            return true;
        });
        @socket_shutdown($this->sock);
        $arrOpt = array('l_onoff' => 1, 'l_linger' => 1);
        socket_set_block($this->sock);
        socket_set_option($this->sock, SOL_SOCKET, SO_LINGER, $arrOpt);
        socket_close($this->sock);

        $this->pool->shutdown();
        exit(0);
    }
    public function addTemplate($path, $template){
        if(is_file($this->path . $path)) return false;
        $templates = unserialize($this->templates);
        $templates[$path] = $template;
        $this->templates = serialize($templates);
        return true;
    }
    public function getTemplate($name){
        $templates = unserialize($this->templates);
        return isset($templates[$name]) ? $templates[$name] : null;
    }

    public function getValues(){
        return unserialize($this->valueStore);
    }
    public function getValue($name){
        $valueStore = unserialize($this->valueStore);
        return isset($valueStore[$name]) ? $valueStore[$name] : null;
    }
    public function setValue($name, $value){
        $valueStore = unserialize($this->valueStore);
        $valueStore[$name] = $value;
        $this->valueStore = serialize($valueStore);
    }
    public function addHelper($name, callable $helper){
        $this->helpers[$name] = clone $helper;
        return true; //For future use
    }
    public function getHelper($name){
        return clone $this->helpers[$name];
    }
    /**
     * @return \Logger
     */
    public function getLogger(){
        return $this->logger;
    }
}