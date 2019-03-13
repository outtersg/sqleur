<?php

function aff($req)
{
	echo "[90m$req[0m\n";
}

function faire($chemin)
{
	$prépros = array();
	require_once "Sqleur.php";
	foreach(file($chemin) as $l)
	{
		$l = explode(' ', $l);
		if($l[0] == '--' && $l[1] == 'prepro')
			$prépros = array_slice($l, 2);;
		break;
	}
	foreach($prépros as $i => $prépro)
	{
		$prépro = trim($prépro);
		require_once $prépro.'.php';
		$prépros[$i] = new $prépro();
	}
	$s = new Sqleur('aff', $prépros);
	$s->decoupeFichier($chemin);
}

error_reporting(-1);
ini_set('display_errors', 1);

if(count($argv) > 1)
{
	foreach(array_slice($argv, 1) as $chemin)
		faire($chemin);
}
else foreach(glob(dirname(__FILE__).'/tests/*.test.sql') as $chemin)
	faire($chemin);

?>