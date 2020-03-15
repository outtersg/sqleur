<?php

function aff($req)
{
	echo "[90m$req[0m\n";
}

class Rempl
{
	public function r($corr)
	{
		return eval($corr[1].';');
	}
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
	$rempl = new Rempl();
	$s->avecDéfs(array('#{{([^}]+|}[^}]+)+}}#' => array($rempl, 'r')));
	$s->decoupeFichier($chemin);
}

function _const($nom)
{
	$miroir = new ReflectionClass('Sqleur');
	$consts = $miroir->getConstants();
	return $consts[$nom];
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
