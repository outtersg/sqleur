<?php

function aff($req, $fermeture = false)
{
	// Notre aff() pouvant être appelée par Sqleur.exécuter() qui désormais lui force un premier paramètre à false, on interprète ce dernier comme notre séparateur habituel.
	if($fermeture === false) $fermeture = ';';
	if(isset($GLOBALS['rés'])) $GLOBALS['rés'] .= $req.$fermeture."\n";
	if($GLOBALS['aff'] >= 2)
	echo "[90m$req[0m\n";
}

class Rempl
{
	public function r($corr)
	{
		return eval($corr[1].';');
	}
}

class JoueurPdo
{
	public $bdd;
	
	public function __construct($bdd)
	{
		$this->bdd = $bdd;
	}
	
	public function exécuter($sql, $appliquerDéfs = false, $interne = false)
	{
		$rés = $this->bdd->query($sql);
		if($interne)
			return $rés;
		$rés->setFetchMode(PDO::FETCH_ASSOC);
		if(isset($GLOBALS['rés']))
			aff("--", '');
		foreach($rés->fetchAll() as $l)
			aff(implode("\t", $l), '');
	}
}

class PréproBdd
{
	public $_sqleur;
	
	public function préprocesse($instr, $ligne)
	{
		switch($instr)
		{
			case '#bdd':
			case '#db':
				$ligne = explode(' ', $ligne, 2);
				$bdd = new PDO($ligne[1]);
				$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				if(method_exists($bdd, 'pgsqlSetNoticeCallback'))
					$bdd->pgsqlSetNoticeCallback(array($this, 'notifDiag'));
				$joueur = new JoueurPdo($bdd);
				$this->_sqleur->_sortie = array($joueur, 'exécuter');
				return;
		}
		
		return false;
	}
	
	public function notifDiag($message)
	{
		fprintf(STDERR, "> %s\n", $message);
	}
}

function parfaire($chemin)
{
	foreach(array('', '0', null) as $suffixeRés)
		if(!isset($suffixeRés) || file_exists(($cheminRés = strtr($chemin, array('.sql' => ".res$suffixeRés.sql")))))
			break;
	if(isset($suffixeRés))
	{
		$GLOBALS['rés'] = '';
		$GLOBALS['résAttendu'] = file_get_contents($cheminRés);
	}
	
	if($GLOBALS['aff'] >= 2)
		echo "[36m=== $chemin ===[0m\n";
	
	$fonction = 'faire'.$suffixeRés;
	$fonction($chemin);
	
	$rés = null;
	if(isset($suffixeRés))
		$rés = comp($GLOBALS['résAttendu'], $GLOBALS['rés']);
	if($GLOBALS['aff'] >= 1)
	{
		if(!isset($rés))
			$affRés = '[90mfait';
		else
			$affRés = $rés ? '[32moui ' : '[31mnon ';
		echo "$affRés [36m$chemin[0m\n";
	}
	
	return $rés;
}

function faire($chemin)
{
	// À FAIRE: isolation de processus pour ne pas tout planter en cas de vautrage: lancer un processus fils php sur moi-même.
	$prépros = array();
	$options = array();
	$mode = 0;
	require_once "Sqleur.php";
	foreach(file($chemin) as $l)
	{
		$l = explode(' ', $l);
		if($l[0] != '--')
			break;
		if($l[1] == 'prepro')
			$prépros = array_slice($l, 2);
		else if($l[1] == 'sqleur.tailleBloc')
			$options['tailleBloc'] = 0 + trim($l[2]);
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
	$prépros[] = new PréproBdd();
	$s = new Sqleur('aff', $prépros);
	foreach($options as $option => $valeur)
		$s->$option = $valeur;
	$s->_mode = $mode;
	$rempl = new Rempl();
	$s->avecDéfs(array('#{{([^}]+|}[^}]+)+}}#' => array($rempl, 'r')));
	$s->decoupeFichier($chemin);
}

function faire0($chemin)
{
	exec("php sql2csv.php -E -0 $chemin", $rés, $err);
	$rés = strtr(implode("\n", $rés), array(chr(0) => "--#\n"));
	$GLOBALS['rés'] = sansCommentaireLigne($rés);
	$GLOBALS['résAttendu'] = sansCommentaireLigne($GLOBALS['résAttendu']);
}

function sansCommentaireLigne($contenu)
{
	$contenu = preg_replace('#(?:^|\n)--.*#', '', $contenu);
	return $contenu;
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
		echo preg_replace(array('/^(-.*)$/m', '/^(\+.*)$/m'), array('[32m\1[0m', '[31m\1[0m'), $diff = file_get_contents($tmp.'.diff'));
		unlink($tmp.'.attendu.sql');
		unlink($tmp.'.obtenu.sql');
		unlink($tmp.'.diff');
		return empty($diff);
	}
	else
		return true;
}

function _const($nom)
{
	$miroir = new ReflectionClass('Sqleur');
	$consts = $miroir->getConstants();
	return $consts[$nom];
}

function tourner($argv)
{
	$faits = 0;
	$GLOBALS['aff'] = 1;
	
error_reporting(-1);
ini_set('display_errors', 1);

	array_shift($argv);
	while(count($argv))
	{
		switch($argv[0])
		{
			case '-v':
				$GLOBALS['aff'] = 2;
				break;
			case '-q':
			case '-s':
				$GLOBALS['aff'] = 0;
				break;
			default:
				++$faits;
				parfaire($argv[0]);
				break;
		}
		
		array_shift($argv);
	}
	if(!$faits)
		foreach(glob(dirname(__FILE__).'/tests/*.test.sql') as $chemin)
			parfaire($chemin);
}

tourner($argv);

?>
