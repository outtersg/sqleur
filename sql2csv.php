<?php
/*
 * Copyright (c) 2017,2020-2021 Guillaume Outters
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
		$this->sortieSinonEntrée = $sortieSinonEntrée;
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
	protected $bavard = 1;
	protected $avecEnTêtes = true;
	
	public function __construct()
	{
		// À FAIRE: permettre des directives #output dans le SQL pour changer de fichier de sortie; cela permettrait de caser plusieurs exports dans le même .sql, en séparant chaque export par cette directive.
		$prépros = array
		(
			new SqleurPreproIncl(),
			new SqleurPreproDef(),
			new SqleurPreproPrepro(),
			$this,
		);
		parent::__construct(array($this, 'exécuter'), $prépros);
		if(method_exists($this->bdd, 'pgsqlSetNoticeCallback'))
			$this->bdd->pgsqlSetNoticeCallback(array($this, 'notifDiag'));
	}
	
	public function préprocesse($instr, $ligne)
	{
		switch($instr)
		{
			case '#format':
				$this->avecEnTêtes = true;
				$ligne = preg_split('/[ \t]+/', $ligne);
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
			default: return false;
		}
	}
	
	public function notifDiag($message)
	{
		fprintf(STDERR, '> '.trim($message)."\n");
	}
	
	public function sortie($sortie)
	{
		$this->sortie = new Flux($sortie, true);
	}
	
	public function jouer($chemin)
	{
		$fluxEntrée = new Flux($chemin, false);
		$requêtes = $this->decoupeFlux($fluxEntrée->ouvrir());
		$fluxEntrée->fermer();
	}
	
	public function exécuter($sql, $appliquerDéfs = false, $interne = false)
	{
		if($appliquerDéfs)
			$sql = $this->_appliquerDéfs($sql);
		if($this->bavard)
		fprintf(STDERR, "  %s;\n", strtr($sql, array("\n" => "\n  ")));
		$rés = $this->bdd->query($sql);
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
	public function exécuter($sql, $appliquerDéfs = false, $interne = false)
	{
		echo $sql.";\n";
	}
}

class JoueurSqlPdo extends JoueurSql
{
	public function __construct()
	{
		$this->bdd();
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
				default:
					if(preg_match('/^(:?[_a-zA-Z0-9]*)=(.*)$/', $argv[$i], $allumettes))
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
		new Sql2Csv($argv, new SPP());
	else
	new Sql2CsvPdo($argv);

?>
