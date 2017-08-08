<?php

include '../src/AsyncMysqli.php';

$sqls = array(
	'SELECT * FROM `mz_table_1` LIMIT 1000,10',
	'SELECT * FROM `mz_table_1` LIMIT 1010,10',
	'SELECT * FROM `mz_table_1` LIMIT 1020,10',
	'SELECT * FROM `mz_table_1` LIMIT 10000,10',
	'SELECT * FROM `mz_table_2` LIMIT 1',
	'SELECT * FROM `mz_table_2` LIMIT 5,1'
);
$config = array(
	'host' => '127.0.0.1',
	'user' => 'root',
	'passwd' => 'root',
	'dbname' => 'test',
	'port' => '3306',
	'timeout' => 20.000, // 执行过期时间，非负数浮点数，单位秒，精确到毫秒
	'block_tv' => 1.000, // 每次轮询阻塞时间，非负数浮点数，单位秒，精确到毫秒
	'limit' => 20        // 一次可同步执行并发查询的sql上限
);

$tvs = microtime();
$tv = explode(' ', $tvs);
$start = $tv[1] * 1000 + (int)($tv[0] * 1000);

$asyncMysqli = new AsyncMysqli($config);


foreach ($sqls as $sql) {
	// 为每条异步查询设置一个回调函数，也可以不设置
	// 回调函数需要有一个参数，用来接收查询返回的mysqli_result对象
	if(!$asyncMysqli->addAsyncQuery($sql, 'display')){
		echo $asyncMysqli->error;
		exit;
	}
}

// 开始执行并发查询
if(!$asyncMysqli->loop()){
	echo $asyncMysqli->error;
}

$tvs = microtime();
$tv = explode(' ', $tvs);
$end = $tv[1] * 1000 + (int)($tv[0] * 1000);

echo $end - $start.PHP_EOL;

// 回调函数需要有一个参数，用来接收查询返回的mysqli_result对象
function display(mysqli_result $result) 
{
	print_r($result->fetch_row());
}
