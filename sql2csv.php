<?php
/*
 * Copyright (c) 2017,2020-2022 Guillaume Outters
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.  IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */


require_once dirname(__FILE__).'/Sqleur.php';
require_once dirname(__FILE__).'/SqleurPreproIncl.php';
require_once dirname(__FILE__).'/SqleurPreproDef.php';
require_once dirname(__FILE__).'/SqleurPreproPrepro.php';

class Flux
{
	const STDIN = 0;
	const STDOUT = 1;
	const STDERR = 2;
	
	const À_OUVRIR = -1;
	const PERMANENT = 0;
	const OUVERT = 1;
	
	public $descr;
	public $f;
	protected $état;
	protected $sortieSinonEntrée;
	
	public function __construct($descr, $sortieSinonEntrée)
	{
		$this->sortieSinonEntrée = $sortieSinonEntrée;
		$this->basculer($descr);
	}
	
	public function basculer($descr)
	{
		if($descr === $this->descr) return;
		if(isset($this->descr)) $this->fermer();
		
		if(is_int($descr))
		{
			switch($descr)
			{
				case Flux::STDIN:
					$this->f = STDIN;
					break;
				case Flux::STDOUT:
					$this->f = STDOUT;
					break;
				case Flux::STDERR:
					$this->f = STDERR;
					break;
				default:
					throw new Exception("Descripteur de fichier $descr inconnu");
			}
			$this->état = Flux::PERMANENT;
		}
		else
			$this->état = Flux::À_OUVRIR;
		$this->descr = $descr;
	}
	
	public function ouvrir($re = false)
	{
		switch($this->état)
		{
			case Flux::À_OUVRIR:
				$this->f = fopen($this->descr, $this->sortieSinonEntrée ? ($re ? 'a' : 'w') : 'r');
				if($this->f === false)
					throw new Exception('Impossible d\'ouvrir "'.$this->descr.'"');
				$this->état = Flux::OUVERT;
				break;
		}
		return $this->f;
	}
	
	public function fermer()
	{
		switch($this->état)
		{
			case Flux::OUVERT:
				fclose($this->f);
				$this->état = Flux::À_OUVRIR;
				break;
		}
	}
}

class JoueurSql extends Sqleur
{
	const CSV = 'csv';
	const CSVBRUT = 'delim';
	
	public $sépChamps = ';';
	
	public $bdd;
	protected $sortiesDéjàUtilisées = array();
	public $conversions;
	public $bavard = 1;
	protected $avecEnTêtes = true;
	
	public function __construct()
	{
		$prépros = array
		(
			new SqleurPreproIncl(),
			$this->_préproDéf = new SqleurPreproDef(),
			new SqleurPreproPrepro(),
			$this,
		);
		parent::__construct(array($this, 'exécuter'), $prépros);
		if($this->bdd && method_exists($this->bdd, 'pgsqlSetNoticeCallback'))
			$this->bdd->pgsqlSetNoticeCallback(array($this, 'notifDiag'));
	}
	
	public function autoDéfs()
	{
		foreach(array(':pilote', ':driver') as $clé)
			if(isset($this->_defs['stat'][$clé]))
			{
				$pilote = $this->_defs['stat'][$clé];
				break;
			}
		if(isset($pilote))
		{
			/* COPIE: MajeurJoueurPdo */
			$définitionsParPilote = array
			(
				'pgsql' => array
				(
					'AUTOPRIMARY' => 'serial primary key',
					'BIGAUTOPRIMARY' => 'bigserial primary key',
					'T_TEXT' => 'text',
				),
				'sqlite' => array
				(
					'AUTOPRIMARY' => 'integer primary key',
					'BIGAUTOPRIMARY' => 'integer primary key', // https://sqlite.org/forum/info/2dfa968a702e1506e885cb06d92157d492108b22bf39459506ab9f7125bca7fd
					'T_TEXT' => 'text',
				),
			);
			if(isset($définitionsParPilote[$pilote]))
			{
				$défs = $définitionsParPilote[$pilote];
				$dyns = array();
				foreach($définitionsParPilote[$pilote] as $clé => $val)
					if(strpos($clé, '(') !== false)
						$dyns[$clé] = '#define '.$clé.' '.$val;
				$défs = array_diff_key($défs, $dyns);
				$this->ajouterDéfs($défs);
				foreach($dyns as $dyn)
					$this->_préproDéf->préprocesse('#define', $dyn);
			}
		}
	}
	
	public function préprocesse($instr, $ligne)
	{
		$ligne = preg_split('/[ \t]+/', $ligne);
		switch($instr)
		{
			case '#format':
				$this->avecEnTêtes = true;
				for($i = 0; ++$i < count($ligne);)
				{
					if
					(
						($avec = preg_match('/^(?:en-?t(?:e|ê|é)t(?:e|é)s?|head(?:er)s?)$/', $ligne[$i]))
						|| preg_match('/^(?:(?:sans-?en-?|(?:é|e))t(?:e|ê|é)t(?:e|é)s?|no-?head(?:er)s?)$/', $ligne[$i])
					)
					{
						$qqc = true;
						$this->avecEnTêtes = $avec;
					}
					else
					switch($ligne[$i])
					{
						case "'":
							// Grosse bidouille pour interpréter tout ce qui ressemble à ' ' comme un espace (qui a été dégommé par le preg_split).
							if($i + 1 < count($ligne) && $ligne[$i + 1] == "'")
								array_splice($ligne, $i, 2, array(' '));
						default:
							if(!isset($format))
								$format = $ligne[$i];
							else if(!isset($sép))
								$sép = $ligne[$i];
							else
								throw new Exception('#format: \''.$ligne[$i].'\' non reconnu');
							break;
					}
				}
				if(!isset($format))
					if(isset($qqc)) // Bon on n'a pas défini le format mais d'autres choses ont été faites.
						break;
					else
					throw new Exception('#format: veuillez préciser un format');
				$this->format = $format;
				if(isset($sép))
					$this->sépChamps = stripcslashes($sép); // Pour les \t etc.
				break;
			case '#silence':
				$this->bavard = 0;
				break;
			case '#bavard': $this->bavard = 1; break;
			case '#sortie':
			case '#output':
				if(!isset($ligne[1]) || in_array($ligne[1], array('1', 'stdout')))
					$sortie = Flux::STDOUT;
				else
					$sortie = $this->_sqleur->appliquerDéfs($ligne[1]);
				$this->sortie->basculer($sortie);
				break;
			default: return false;
		}
	}
	
	public function notifDiag($message)
	{
		if(($message = trim($message)) != 'row number 0 is out of range 0..-1') // Message généré par nous lorsque nous accédons aux méta-données (colonnes) avant d'avoir prélevé le contenu, cf. le commentaire sur row number 0 dans ce fichier.
		fprintf(STDERR, "> %s\n", $message);
	}
	
	public function sortie($sortie)
	{
		$this->sortie = new Flux($sortie, true);
	}
	
	public function jouer($chemin)
	{
		$fluxEntrée = new Flux($chemin, false);
		$this->_fichier = is_int($chemin) ? getcwd().'/-' : $chemin;
		$requêtes = $this->decoupeFlux($fluxEntrée->ouvrir());
		$fluxEntrée->fermer();
	}
	
	public function exécuter($sql, $appliquerDéfs = false, $interne = false)
	{
		if($appliquerDéfs)
			$sql = $this->_appliquerDéfs($sql);
		if($this->bavard)
		fprintf(STDERR, "  %s;\n", strtr($sql, array("\n" => "\n  ")));
		try
		{
		$rés = $this->bdd->query($sql);
		}
		catch(Exception $ex)
		{
			require_once dirname(__FILE__).'/SqlUtils.php';
			$u = new SqlUtils();
			throw $u->jolieEx($ex, $sql);
		}
		$rés->setFetchMode(PDO::FETCH_ASSOC);
		// À FAIRE: passer tout ça après le premier fetch(), sans quoi notice "> row number 0 is out of range 0..-1".
		// Au cas où le fetch() renvoie effectivement false on aura toujours le message, mais sinon ça fera plus propre.
		if(!$interne && ($nCols = $rés->columnCount()) > 0)
		{
			$colonnes = array();
			for($numCol = -1; ++$numCol < $nCols;)
			{
				$descrCol = $rés->getColumnMeta($numCol);
				$colonnes[] = $descrCol['name'];
			}
			$this->exporter($rés, $colonnes);
		}
		return $rés;
	}
	
	protected function exporter($résultat, $colonnes = null)
	{
		// La sortie courante a-t-elle déjà été utilisée? Si oui, ce peut être embêtant, car plusieurs requêtes successives n'ont pas forcément le même format de sortie, donc les agréger dans le même fichier de sortie est risqué pour le moins. Si l'on veut une telle chose, mieux vaudra passer par un union SQL (qui garantira que les deux requêtes sortent des colonnes compatibles).
		
		$re = false;
		foreach($this->sortiesDéjàUtilisées as $sortieAncienne)
			if($sortieAncienne === $this->sortie->descr)
			{
				// À FAIRE: ce contrôle à la lecture de la première ligne, afin de ne pas alerter si le nombre de colonnes est identique.
				if($this->sortie->descr !== Flux::STDOUT)
				fprintf(STDERR, '# La sortie "%s" est réutilisée en ayant déjà servi pour l\'export d\'une autre requête. Nous ne garantissons pas que le fichier résultant sera cohérent entre les deux exports qui y sont combinés.'."\n", $sortieAncienne);
				$re = true;
				break;
			}
		
		$this->sortiesDéjàUtilisées[] = $this->sortie->descr;
		
		$this->sortie->ouvrir($re);
		
		if(isset($colonnes) && $this->avecEnTêtes && $colonnes != array('?column?')) // À FAIRE: autres BdD que PostgreSQL.
			$this->exporterLigne($colonnes);
		
		while(($l = $résultat->fetch()) !== false)
			$this->exporterLigne($l);
		
		$this->sortie->fermer();
	}
	
	protected function exporterLigne($l)
	{
		if(isset($this->conversions))
			foreach($l as & $ptrChamp)
				$ptrChamp = strtr($ptrChamp, $this->conversions);
		switch($this->format)
		{
			case JoueurSql::CSV:
				fputcsv($this->sortie->f, $l, $this->sépChamps);
				break;
			case JoueurSql::CSVBRUT:
				fwrite($this->sortie->f, implode($this->sépChamps, $l)."\n");
				break;
		}
	}
}

/**
 * SQL PreProcessor
 */
class SPP extends JoueurSql
{
	public function __construct($sép = null)
	{
		parent::__construct();
		// A priori pour un client non PDO, donc passons un peu plus de temps à découper le plus robustement possible.
		$this->_mode |= Sqleur::MODE_BEGIN_END|Sqleur::MODE_SQLPLUS;
		$this->sépRequêtes = $sép;
	}
	
	public function exécuter($sql, $appliquerDéfs = false, $interne = false)
	{
		if($interne && isset($this->_scénario))
			return $this->_déroulerScénario($sql, $appliquerDéfs);
		
		// Si la dernière ligne est susceptible de masquer notre point-virgule (commentaire, ou autre), on rajoute un retour, que le point-virgule ait sa ligne à part.
		if(preg_match("#(--|\n/)[^\n]*\$#", $sql))
			$sql .= "\n";
		// Notre purge de commentaires et blocs #if inutiles peut avoir laissé des trous peu appréciés de certains (SQL*Plus).
		$sql = preg_replace("#(?:\n\\s*)+\n#", "\n", $sql);
		
		$sép =
			isset($this->sépRequêtes)
			? $this->sépRequêtes
			:
			(
				isset($this->terminaison)
				? $this->terminaison
				: ";\n"
			)
		;
		
		// Lorsque le ; est à la fois
		// - partie intégrante du (pseudo-)SQL (ex.: procédural, avec du "begin fonction; end;", où Oracle braille si le end n'a pas son point-virgule)
		// - et séparateur entre requêtes
		// on fusionne ses deux usages en un seul point-virgule.
		if(substr($sql, -1) == ';' && substr($sép, 0, 1) == ';')
			$sép = substr($sép, 1);
		
		echo $sql.$sép;
	}
	
	protected function _déroulerScénario($sql, $appliquerDéfs = false)
	{
		if(($attendu = array_shift($this->_scénario)) != $sql)
			throw new Exception("Erreur de scénario:\n attendu:\n  $attendu\n reçu:\n  $sql");
		return array_shift($this->_scénario);
	}
}

class JoueurSqlPdo extends JoueurSql
{
	public function __construct($bdd = null)
	{
		$this->bdd = $bdd;
		$this->bdd();
		$pilote = $this->bdd->getAttribute(PDO::ATTR_DRIVER_NAME);
		$this->ajouterDéfs
		(
			array
			(
				':pilote' => $pilote,
				':driver' => $pilote,
			)
		);
		
		parent::__construct();
	}
	
	protected function bdd()
	{
		if(isset($this->bdd))
			return $this->bdd;
		if(($conne = getenv('bdd')) === false)
			throw new Exception('la variable d\'environnement $bdd doit contenir la chaîne de connection à la base');
		$conne = preg_replace('#^([^:]*)://([^@:]*):([^@]*)@([^/:]*)(?::([0-9]+))?/(.*)$#', '\1:host=\4;port=\5;user=\2;password=\3;dbname=\6', $conne);
		$conne = strtr($conne, array(';port=;' => ';'));
		$this->bdd = new PDO($conne);
		$this->bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $this->bdd;
	}
}

class Sql2Csv
{
	public function __construct($argv, $j)
	{
		// Analyse des paramètres.
		
		$entrées = array();
		$sortie = Flux::STDOUT;
		$conversions = array();
		$défs = array();
		$formatSortie = JoueurSql::CSV;
		$sépChamps = ';';
		
		for($i = 0; ++$i < count($argv);)
			switch($argv[$i])
			{
				case '-E': break;
				case '--raw':
					$formatSortie = JoueurSql::CSVBRUT;
					break;
				case '-d':
					++$i;
					$sépChamps = $argv[$i];
					break;
				case '--newline':
					++$i;
					$conversions["\n"] = $argv[$i];
					break;
				case '-t':
					$conversions[$argv[$i + 1]] = $argv[$i + 2];
					$i += 2;
					break;
				case '-o':
					++$i;
					$sortie = $argv[$i];
					break;
				case '--scenario':
					$scénario = file_get_contents($argv[++$i]);
					$scénario = strtr($scénario, array("[\n" => '[', ",\n" => ','));
					if(substr($scénario, -1) != ']') $scénario .= ']';
					$scénario = strtr($scénario, array(",]" => ']'));
					$scénario = json_decode($scénario);
					$j->_scénario = $scénario;
					break;
				default:
					if(preg_match('/^([@:]?[_a-zA-Z0-9]*[@:]?)=(.*)$/', $argv[$i], $allumettes))
						$défs[$allumettes[1]] = $allumettes[2];
					else
					$entrées[] = $argv[$i] === '-' ? Flux::STDIN : $argv[$i];
					break;
			}
		
		if(!count($entrées))
			$entrées[] = Flux::STDIN;
		
		// Si on est sur du brut de chez brut, quelques conversions seront nécessaires pour que la sortie ne soit pas pourrie. On les ajoute en +=, afin que celles demandées via -t soient prioritaires.
		
		if($formatSortie == JoueurSql::CSVBRUT)
			$conversions += array
			(
				"\n" => ' | ',
				$sépChamps => ',',
			);
		
		// On y va!
		
		$j->conversions = isset($conversions) && count($conversions) ? $conversions : null;
		$j->format = $formatSortie;
		$j->sépChamps = $sépChamps;
		$j->ajouterDéfs($défs);
		$j->autoDéfs();
		$j->sortie($sortie);
		foreach($entrées as $entrée)
			$j->jouer($entrée);
	}
}

class Sql2CsvPdo extends Sql2Csv
{
	public function __construct($argv)
	{
		try
		{
			$j = new JoueurSqlPdo();
			parent::__construct($argv, $j);
		}
		catch(Exception $e)
		{
			//fprintf(STDERR, '%s', $this->affex($e));
			//exit(1);
			throw $e;
		}
	}
	
	public function affex($e)
	{
		$aff = '### '.$e->getFile().':'.$e->getLine().': '.get_class($e).': '.$e->getMessage()."\n";
		$aff = $this->rouge($aff);
		foreach(array_slice($e->getTrace(), 0, 8) as $l)
			$aff .= "\t".$l['file'].':'.$l['line'].': '.(isset($l['class']) ? $l['class'].'.' : '').$l['function']."()\n";
		return $aff;
	}
	
	public function rouge($bla)
	{
		$bla = preg_replace('/^/m', '[031m', $bla);
		$bla = preg_replace('/$/m', '[0m', $bla);
		return $bla;
	}
}

if(isset($argv) && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) == __FILE__)
	if(in_array('-E', $argv))
	{
		$sép = null;
		if(($pos = array_search('-0', $argv)) !== false || ($pos = array_search('-print0', $argv)) !== false)
		{
			$sép = "\0";
			array_splice($argv, $pos, 1);
		}
		new Sql2Csv($argv, new SPP($sép));
	}
	else
	new Sql2CsvPdo($argv);

?>
