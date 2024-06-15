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
		else if(is_resource($descr))
		{
			$this->f = $descr;
			$this->état = Flux::PERMANENT; // S'il nous est passé déjà ouvert, ce n'est pas à nous de le fermer.
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
	public $sépLignes = "\n";
	
	public $bdd;
	public $_sqleur;
	protected $sortiesDéjàUtilisées = array();
	public $conversions;
	public $format;
	public $sortie;
	public $bavard = 1;
	/** @var Si non nul, le Sqleur y consignera le type de chaque colonne de la prochaine requête. */
	public $typeCols;
	protected $_colsÀTailleVariable;
	protected $avecEnTêtes = true;
	protected $_préproDéf;
	protected $_incises = [];
	
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
		/* À FAIRE: réutiliser dans l'interprétation des paramètres.
		 *          Cette fonction est intéressante, car elle permet des définitions dynamiques;
		 *          utilisée initialement à la place de l'autodefs.sql, elle n'a plus cet usage, mais aurait son utilité dans le traitement des paramètres.
		 */
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
					$sortie = $this->appliquerDéfs($ligne[1]);
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
		$this->ajouterDéfs
		(
			array
			(
				':SCRIPT_FILENAME' => $chemin,
				':SCRIPT_NAME' => basename($chemin),
			)
		);
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
			$rés = $this->_exéc($sql);
		}
		catch(Exception $ex)
		{
			require_once dirname(__FILE__).'/SqlUtils.php';
			$u = new SqlUtils();
			throw $u->jolieEx($ex, $sql);
		}
		/* À FAIRE: ne plus faire de l'ASSOC si on pond vers un CSV: ça décale les colonnes en cas de 2 colonnes identiquement nommées. */
		$rés->setFetchMode(PDO::FETCH_ASSOC);
		// À FAIRE: passer tout ça après le premier fetch(), sans quoi notice "> row number 0 is out of range 0..-1".
		// Au cas où le fetch() renvoie effectivement false on aura toujours le message, mais sinon ça fera plus propre.
		if(isset($this->typeCols) && !$interne)
		{
			$this->typeCols = [];
			unset($this->_colsÀTailleVariable);
			$colsÀTailleVariable = [];
		}
		if(!$interne && ($nCols = $rés->columnCount()) > 0)
		{
			$colonnes = array();
			for($numCol = -1; ++$numCol < $nCols;)
			{
				$descrCol = $rés->getColumnMeta($numCol);
				$colonnes[] = $descrCol['name'];
				if(isset($this->typeCols))
					$this->typeCols[] = $descrCol;
			}
			$this->exporter($rés, $colonnes);
		}
		return $rés;
	}
	
	protected function _exéc($sql)
	{
		$r = $this->bdd->query($sql);
		if(is_object($r) && !method_exists($r, 'setFetchMode'))
		{
			// Pour les alternatives, voir PdoResultat.php:
			// - passer par de la Reflection
			// - travailler non pas sur $this->bdd et sa couche traçante, mais lui demander poliment sa vraie BdD sous-jacente et ne traiter qu'avec cette dernière.
			require_once __DIR__.'/PdoResultat.php';
			$r = new \PdoRésultat($r);
		}
		return $r;
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
		
		$this->_calculerColsÀTailleVariable(); // après la ponte des en-têtes, car la largeur du nom de la colonne n'est pas la largeur de la colonne.
		
		while(($l = $résultat->fetch()) !== false)
			$this->exporterLigne($l);
		
		unset($this->_colsÀTailleVariable);
		
		$this->sortie->fermer();
	}
	
	protected function _calculerColsÀTailleVariable()
	{
		if(isset($this->typeCols))
			foreach($this->typeCols as $numCol => & $ptrDescrCol)
				if(($ptrDescrCol['maxLen'] = $ptrDescrCol['len']) < 0)
				{
					$colsÀTailleVariable[$numCol] = $ptrDescrCol['name'];
					$ptrDescrCol['maxLen'] = 1; // Minimum 1 pour éviter les déplorables tentatives de création de colonne de taille 0.
				}
		if(!empty($colsÀTailleVariable)) $this->_colsÀTailleVariable = $colsÀTailleVariable;
	}
	
	protected function exporterLigne($l)
	{
		if(isset($this->_colsÀTailleVariable))
			foreach($this->_colsÀTailleVariable as $numCol => $nomCol)
				if(strlen($l[$nomCol]) > $this->typeCols[$numCol]['maxLen'])
					$this->typeCols[$numCol]['maxLen'] = strlen($l[$nomCol]);
		if(isset($this->conversions))
			foreach($l as & $ptrChamp)
				$ptrChamp = strtr($ptrChamp, $this->conversions);
		switch($this->format)
		{
			case JoueurSql::CSV:
				fputcsv($this->sortie->f, $l, $this->sépChamps);
				break;
			case JoueurSql::CSVBRUT:
				fwrite($this->sortie->f, implode($this->sépChamps, $l).$this->sépLignes);
				break;
		}
	}
	
	public function commencerIncise($chemin, $f = null, $format = JoueurSql::CSV, $sépChamps = null, $sépLignes = null, $avecEnTêtes = null)
	{
		$incise = [ $this->sortie->descr, $this->sortie->f, $this->format, $this->sépChamps, $this->sépLignes, $this->avecEnTêtes ];
		$this->_incises[] = $incise;
		$this->sortie->descr = null; // Pour qu'il ne la ferme pas.
		$this->sortie->basculer($f);
		$this->sortie->descr = isset($chemin) ? $chemin : ''.$f;
		$this->format = $format;
		$this->sépChamps = isset($sépChamps) ? $sépChamps : ';';
		$this->sépLignes = isset($sépLignes) ? $sépLignes : "\n";
		if(isset($avecEnTêtes))
			$this->avecEnTêtes = $avecEnTêtes;
	}
	
	public function terminerIncise()
	{
		list($ancDescr, $ancF, $this->format, $this->sépChamps, $this->sépLignes, $this->avecEnTêtes) = array_pop($this->_incises);
		$this->sortie->descr = null;
		$this->sortie->basculer($ancF);
		$this->sortie->descr = $ancDescr;
	}
}

/**
 * SQL PreProcessor
 */
class SPP extends JoueurSql
{
	public $sépRequêtes;
	public $format;
	public $sortie;
	
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
		
		parent::__construct();
		
		$pilote = $this->bdd->getAttribute(PDO::ATTR_DRIVER_NAME);
		$this->ajouterDéfs
		(
			array
			(
				':pilote' => $pilote,
				':driver' => $pilote,
			),
			true
		);
		
		/* NOTE: ATTR_EMULATE_PREPARES
		 * Pour pdo_pgsql (reste à tester d'autres moteurs), contrairement à ce qu'indique son nom, ATTR_EMULATE_PREPARES rend l'interface *plus* transparente que sans.
		 * PGSQL_ATTR_DISABLE_PREPARES, elle, n'a aucun effet.
		 * Ainsi les deux exécutions suivantes foirent-elles sans, car le ? est remplacé par $1:
		 *   echo "select '{\"u\":1}'::jsonb ? 'u';" | bdd=pgsql:dbname=test php sql2csv.php # → erreur de syntaxe SQL
		 *   echo 'select $$coucou ? oui$$;' | bdd=pgsql:dbname=test php sql2csv.php # Le contenu de la chaîne à dollars est modifié.
		 * https://stackoverlow.com/a/36177073
		 * https://github.com/php/php-src/issues/14244
		 */
		$this->bdd->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1); // /!\ Pas mal de questions sur la sécurité et les perfs.
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

/**
 * Joueur Asservi de Requêtes SQL
 * aussi appelé JoueurSqlExéc
 */
class Jars extends JoueurSql
{
	public function __construct($commande)
	{
		require_once __DIR__.'/../util/processus.php';
		
		parent::__construct();
		
		$boutsCommande = explode(' ', $commande);
		switch($boutsCommande[0])
		{
			case '@sqlite': // SQLite en ligne de commande, principalement pour test.
				// echo "select 1 prems, 'miam' facon union select 2, 'ou'; select 3 deuz; selec 'flu';" | php sql2csv.php -e "@sqlite /tmp/0.sqlite3"
				$bdd = isset($boutsCommande[1]) ? $boutsCommande[1] : getenv('bdd');
				$commande =
				[
					'sh', '-c',
					"
						tr -u '\\000\\012' '\\012 ' | while read req
						do
							sqlite3 '$bdd' '.head on' '.mode tabs' \"\$req\" && printf '\\035' || kill \$\$
						done | tr -u '\\012\\011' '\\036\\037'
					"
				];
				break;
			case '@sqlminus':
				$d = __DIR__;
				// echo "select 1 prems, 'miam' facon from dual union select 2, 'ou' from dual; select 3 deuz from dual; select 'flu' from dudu;" | bdd="<id>/<mdp>@localhost:1521:<bdd>" php sql2csv.php -e "@sqlminus"
				/* À FAIRE: ne pas coder en dur Oracle. Par exemple via une syntaxe @sqlminus:ojdbc8.jar (avec une recherche des .jar dans le chemin courant, à défaut $d). */
				$commande = "java -classpath $d/sqlminus.jar:$d/opencsv.jar:$d/ojdbc8.jar eu.outters.sqleur.SqlMinus -0 --serie ".getenv('bdd');
				break;
		}
		
		$this->bdd = $this;
		
		if(is_string($commande))
		$commande = explode(' ', $commande);
		$this->_jars = new ProcessusLignes($commande, [ $this, 'engrangerLigne' ]);
		$this->_jars->finDeLigne("#[\035\036]#"); # Group Separator et Record Separator.
	}
	
	public function query($sql)
	{
		$this->_rés = new RésultatJars;
		
		$r = $this->_jars->attendreQuelqueChose($sql.chr(0));
		if($r !== true && is_numeric($r) && $r != 0)
			throw new Exception('le connecteur externe est sorti en erreur '.$r);
		
		/* À FAIRE: un mode où l'appelant fasse du fetch() sans attendreQuelqueChose()
		 * Que ce soit l'engrangerLigne qui invoque directement le corps de boucle du fetch, plutôt que l'ordonnanceur qui attende qu'on renvoie quelque chose avant de commencer à tourner sur le fetch.
		 * Soit par adaptation dédiée, soit par attendreQuelqueChose dans le fetch() plutôt que dans le query().
		 * Mais alors attention à la gestion d'exceptions!
		 */
		return $this->_rés;
	}
	
	public function engrangerLigne($ligne, $fd, $finDeLigne)
	{
		if($fd == 2)
			fprintf(STDERR, "%s%s", $ligne, $finDeLigne);
		else
		switch($finDeLigne)
		{
			case "\036":
				$ligne = explode("\037", $ligne);
				$this->_rés->engrangerLigne($ligne);
				break;
			case "\035":
				return true;
		}
	}
	
	protected $_jars;
	protected $_rés;
}

class RésultatJars
{
	public function engrangerLigne($ligne)
	{
		if(!isset($this->colonnes))
			$this->colonnes = $ligne;
		else
			$this->données[] = $ligne;
	}
	
	public function setFetchMode() {}
	public function columnCount() { return isset($this->colonnes) ? count($this->colonnes) : 0; }
	public function getColumnMeta($numCol) { return [ 'name' => $this->colonnes[$numCol] ]; }
	public function fetch()
	{
		if(!count($this->données)) return false;
		
		return array_combine($this->colonnes, array_shift($this->données));
	}
	
	protected $colonnes;
	protected $données;
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
		$défs = $this->tradDéfs($défs);
		$j->ajouterDéfs($défs);
		if(count($défsMoteur = array_intersect_key($défs, $this->_défsMoteur)))
			$j->ajouterDéfs($défsMoteur, true);
		$j->sortie($sortie);
		if(file_exists($autoDéfs = dirname(__FILE__).'/sql2csv.autodefs.sql'))
			array_unshift($entrées, $autoDéfs);
		foreach($entrées as $entrée)
			$j->jouer($entrée);
	}
	
	function tradDéfs($défs)
	{
		$éqs = array
		(
			array(':pilote', ':driver'),
		);
		foreach($éqs as $éq)
		{
			if(count($trous = array_diff_key($cléq = array_flip($éq), $défs))) // Si toutes les clés équivalentes ne sont pas définies.
				if(count($prems = array_intersect_key($défs, $cléq)))
					$défs += array_fill_keys(array_keys($trous), array_shift($prems));
		}
		return $défs;
	}
	
	protected $_défsMoteur = [ ':pilote' => true, ':driver' => true ];
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
	else if(($pos = array_search('-e', $argv)) !== false)
	{
		$moinsE = array_splice($argv, $pos, 2);
		new Sql2Csv($argv, new Jars($moinsE[1]));
	}
	else
	new Sql2CsvPdo($argv);

?>
