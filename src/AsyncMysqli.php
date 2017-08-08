<?php
/**
 * 一个异步查询类，利用mysqli扩展和mysqlnd驱动实现；
 * 底层是使用的是多路复用select轮询网络IO
 */
class AsyncMysqli {

	protected $_enable = false;
	protected $_limit = 20;
	protected $_timeout = 30000;  // 30000 ms
	protected $_t_sec = 2;  // 2 s
	protected $_t_usec = 0; // 0 us
	protected $_links = [];
	protected $_callback = [];
	protected $_event = [];
	protected $_host;
	protected $_user;
	protected $_passwd;
	protected $_port;
	protected $_dbname;
	public $error;

	public function __construct($config)
	{
		if( extension_loaded('mysqlnd') && is_callable('mysqli_poll', false) ) {
			$this->_enable = true;
		}else{
			$this->error = 'unable async query';
			return false;
		}

		if(isset($config['block_tv'])) {
			$block_tv = (float)$config['block_tv'];
			if($block_tv >= 0) {
				$block_tv = (int)( $block_tv*1000 );
				$this->_t_usec = ($block_tv % 1000) * 1000;
				$this->_t_sec = ($block_tv - $this->_t_usec / 1000) / 1000;
			}
		}

		if(isset($config['timeout']) && is_numeric($config['timeout']) && $config['timeout'] > 0 ) {
			$this->_timeout = (int)($config['timeout'] * 1000);
		}

		if(isset($config['limit']) && is_numeric($config['limit']) && $config['limit'] > 0 ) {
			$this->_limit = (int)$config['limit'];
		}		

		$this->_host = isset($config['host'])? $config['host']: '127.0.0.1';

		$this->_user = isset($config['user'])? $config['user']: 'root';

		$this->_passwd = isset($config['passwd'])? $config['passwd']: '';

		$this->_port = isset($config['port'])? $config['port']: '3306';

		$this->_dbname = isset($config['dbname'])? $config['dbname']: 'test';
	}

	/**
	 * 添加查询语句和回调函数
	 * @Author   JC
	 * @DateTime 2017-08-07
	 * @param    string        $sql      sql语句
	 * @param    callable|null $callback 回调方法
	 */
	public function addAsyncQuery(string $sql, callable $callback = null)
	{
		if(!$this->_enable) {
			$this->error = 'unable async query';
			return false;
		}

		if(count($this->_event) >= $this->_limit) {
			$this->error = "over {$this->_limit} sql query events";
			return false;
		}

		if(empty($sql)) {
			$this->error = 'empty sql';
			return false;
		}

		if($callback && !is_callable($callback)) {
			$this->error = 'callback func is not callable';
			return false;
		}

		$this->_event[] = array(
			'sql' => $sql,
			'callback' => $callback? $callback: null
		);

		return true;
	}

	/**
	 * 开始并发查询事件
	 * @Author   JC
	 * @DateTime 2017-08-07
	 * @return   [type]     [description]
	 */
	public function loop()
	{
		if(!$this->_enable) {
			$this->error = 'unable async query';
			return false;
		}

		if(empty($this->_event)) {
			$this->error = 'empty events';
			return false;
		}	

		// 链接失败，就关闭已连接
		foreach ($this->_event as $event) {

			if( !($link = $this->_connect()) ){
				$this->error = 'can not connect db';
				$this->_reset();
				return false;
			}

			// 发起mysqli的异步查询，立马返回
			$link->query($event['sql'], MYSQLI_ASYNC); 

			// 把当前查询的链接保存到待轮询的数组
			$this->_links[$link->thread_id] = $link;
			$this->_callback[$link->thread_id] = $event['callback'];
		}	

		$process = 0;
		$llen = count($this->_links);

		$read_links = $error_links = $reject_links = [];

		$start = $this->_microtime();

		do{
			$read_links = $error_links = $reject_links = $this->_links;

			$cost = $this->_microtime() - $start;

			if( $cost >  $this->_timeout ) {
				$this->error = 'timeout';
				$this->_reset();
				return false;				
			}

			// 轮询，返回可读的链接
			if(! ($ret = mysqli_poll($read_links, $error_links, $reject_links, $this->_t_sec, $this->_t_usec)) ) {
				continue;
			}

			if($ret == -1) {
				$this->error = 'mysqli poll error';
				$this->_reset();
				break;
			}

			// 执行回调函数，删除完成的查询
			foreach ($read_links as $link) {

				if($result = $link->reap_async_query()){

					if(! ($result instanceof mysqli_result) ) {

						$this->error = 'result error';
						$this->_reset();
						return false;
					}

					if($this->_callback[$link->thread_id]) {
						call_user_func($this->_callback[$link->thread_id], $result);
					}
					mysqli_free_result($result);					
				}else{

					$this->error = $link->error? $link->error: 'get result error';
					$this->_reset();
					return false;
				}

				unset($this->_links[$link->thread_id]);
				unset($this->_callback[$link->thread_id]);
				$this->_release($link);
				$process++;
			}

			foreach ($error_links as $link) {

				$this->error = $link->error;
				$this->_reset();
				return false;
			}

			foreach ($reject_links as $link) {

				$this->error = 'is not async query';
				$this->_reset();
				return false;
			}

		}while($process < $llen);

		$this->_reset();
		return true;
	}

	/**
	 * 关闭数据库链接
	 * @Author   JC
	 * @DateTime 2017-08-07
	 * @param    [type]     $link [description]
	 * @return   [type]           [description]
	 */
	protected function _release($link)
	{
		$link->close();
	}

	/**
	 * 链接数据库
	 * @Author   JC
	 * @DateTime 2017-08-07
	 * @return   [type]     [description]
	 */
	protected function _connect()
	{
		try {
			$link = mysqli_connect($this->_host, $this->_user, $this->_passwd, $this->_dbname,$this->_port);
			return $link;
		} catch(Exception $e) {
			$this->error = $e->getMessage();
			$this->_reset();
			return false;
		}
	}	

	/**
	 * 关闭所有数据库链接，重置当前对象状态
	 * @Author   JC
	 * @DateTime 2017-08-07
	 * @return   [type]     [description]
	 */
	protected function _reset()
	{
		foreach ($this->_links as $link) {
			$this->_release($link);
		}

		$this->_callback = [];
		$this->_links = [];
		$this->_event = [];
	}

	/**
	 * 获取当前毫秒时间
	 * @Author   JC
	 * @DateTime 2017-08-07
	 * @return   [type]     [description]
	 */
	protected function _microtime()
	{
		$tvs = microtime();
		$tv = explode(' ', $tvs);

		return $tv[1] * 1000 + (int)($tv[0] * 1000);	
	}
}