<?php

require_once dirname(__FILE__).'/../SqlUtils.php';

$u = new SqlUtils();

$bla = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
$bla = str_repeat($bla, 5); // 260 caractères, sachant que les … ont besoin de 256 caractères pour apparaître.
$bla .= ";\n";

$sql = "select 1;\ndrop uh;";

for($i = -1; ++$i <= strlen($sql);)
{
	echo "----\n";
	echo $u->contexteSql($sql, $i)."\n";
	echo $u->contexteSql($bla.$sql, strlen($bla) + $i)."\n";
}

?>
