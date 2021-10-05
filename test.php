<?php

function aff($req)
{
	if(isset($GLOBALS['rés'])) $GLOBALS['rés'] .= $req.";\n";
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
	if(!file_exists(($cheminRés = strtr($chemin, array('.sql' => '.res.sql'))))) $cheminRés = null;
	$GLOBALS['rés'] = isset($cheminRés) ? '' : null;
	
	$prépros = array();
	$mode = 0;
	require_once "Sqleur.php";
	foreach(file($chemin) as $l)
	{
		$l = explode(' ', $l);
		if($l[0] != '--')
			break;
		if($l[1] == 'prepro')
			$prépros = array_slice($l, 2);
		else if($l[1] == 'sqleur._mode')
			foreach(explode('|', $l[2]) as $cmode)
				$mode |= _const(trim($cmode));
	}
	foreach($prépros as $i => $prépro)
	{
		$prépro = trim($prépro);
		require_once $prépro.'.php';
		$prépros[$i] = new $prépro();
	}
	$s = new Sqleur('aff', $prépros);
	$s->_mode = $mode;
	$rempl = new Rempl();
	$s->avecDéfs(array('#{{([^}]+|}[^}]+)+}}#' => array($rempl, 'r')));
	$s->decoupeFichier($chemin);
	
	if(isset($cheminRés))
		comp(file_get_contents($cheminRés), $GLOBALS['rés']);
}

function comp($attendu, $obtenu)
{
	$tmp = sys_get_temp_dir().'/temp.sqleurtest';
	$comp = array
	(
		'attendu' => $attendu,
		'obtenu' => $obtenu,
	);
	foreach($comp as $quoi => $contenu)
		$comp[$quoi] = preg_replace("/(^|\r\n?|\n)(?:\s*[\r\n])+/", '\1', preg_replace('/[\r\n]+;/', ';', $contenu));
	if($comp['obtenu'] != $comp['attendu'])
	{
		foreach($comp as $quoi => $contenu)
			file_put_contents($tmp.'.'.$quoi.'.sql', $contenu);
		system("diff -uw $tmp.attendu.sql $tmp.obtenu.sql > $tmp.diff");
		echo preg_replace(array('/^(-.*)$/m', '/^(\+.*)$/m'), array('[32m\1[0m', '[31m\1[0m'), file_get_contents($tmp.'.diff'));
		unlink($tmp.'.attendu.sql');
		unlink($tmp.'.obtenu.sql');
		unlink($tmp.'.diff');
	}
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
