<?php

	$sqls = array(
		'SELECT * FROM `mz_table_1` LIMIT 1000,10',
		'SELECT * FROM `mz_table_1` LIMIT 1010,10',
		'SELECT * FROM `mz_table_1` LIMIT 1020,10',
		'SELECT * FROM `mz_table_1` LIMIT 10000,10',
		'SELECT * FROM `mz_table_2` LIMIT 1',
		'SELECT * FROM `mz_table_2` LIMIT 5,1'
	);

	$tvs = microtime();
	$tv = explode(' ', $tvs);

	$start = $tv[1] * 1000 + (int)($tv[0] * 1000);
	
	$link = mysqli_connect('127.0.0.1', 'root', 'root', 'dbname', '3306');

	foreach ($sqls as $sql) {
		$result = $link->query($sql);
        print_r($result->fetch_row());
        if (is_object($result))
            mysqli_free_result($result);
	}
	$link->close();
	$tvs = microtime();
	$tv = explode(' ', $tvs);
	$end = $tv[1] * 1000 + (int)($tv[0] * 1000);

	echo $end - $start,PHP_EOL;