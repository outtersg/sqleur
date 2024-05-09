<?php
/*
 * Copyright (c) 2024 Guillaume Outters
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

/* À FAIRE: mutualiser avec SqleurPreproCreate. */

require_once __DIR__.'/SqleurPrepro.php';

/**
 * Interprète les #exec, lançant un processus fils pour produire de la donnée ensuite rentrée en base.
 * Les tables produites ont conventionnellement les colonnes suivantes:
 * - d Descripteur
 * - h Horodatage
 * - l Ligne (numéro)
 * - t Texte
 */
class SqleurPreproExec extends SqleurPrepro
{
	protected $_préfixes = [ '#exec' ];

	public function préprocesse($motClé, $directiveComplète)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$this->_interpréterDirective($directiveComplète);
	}
	
	protected function _interpréterDirective($commande)
	{
		if(isset($this->_lanceur)) throw $this->_sqleur->exception('Impossible de lancer une opération alors que la dernière tourne encore');
		
		$commande = $this->_sqleur->appliquerDéfs($commande);
		
		$exprDest = '(?:(?:'.implode('|', array_keys(self::$OptionsTable)).') +)*_';
		$exprBouts =
		[
			'(?<op>'.implode('|', $this->_préfixes).') ',
			"(?<vers>vers|into) (?<stdout>$exprDest)(?:, (?<stderr>$exprDest))?",
			"(?<redire>|[1-9][0-9]*|[?])(?<redirt>>>?) (?<redir>$exprDest)",
			"< `(?<req0>[^`]*)`",
		];
		$exprBouts = '@^(?:'.implode('|', $exprBouts).')@i';
		$exprBouts = strtr($exprBouts, [ ' ' => '[\s\r\n]+', '__' => '[^\s]+', '_' => '\w+' ]);
		
		$p = [];
		
		while(preg_match($exprBouts, $commande, $r))
		{
			switch(true)
			{
				case isset($r['op']) && strlen($r['op']):
					if(isset($p['op'])) throw $this->_sqleur->exception('2 opérations mentionnées');
					$p['op'] = $r['op'];
					break;
				case isset($r['redir']) && strlen($r['redir']):
					$this->_interpréterSortie($r['redire'] ? $r['redire'] : 1, ($r['redirt'] == '>>' ? 'cumul' : 'init').' '.$r['redir'], /*&*/ $p);
					break;
				case isset($r['vers']) && strlen($r['vers']):
					foreach([ 1 => 'stdout', 2 => 'stderr' ] as $nes => $nom)
					{
						if(!isset($r[$nom]) || !strlen($r[$nom])) continue;
						$this->_interpréterSortie($nes, $r[$nom], /*&*/ $p);
					}
					break;
				case isset($r['req0']) && strlen($r['req0']):
					$p[self::P_E_REQ] = $r['req0'];
					break;
			}
			$commande = ltrim(substr($commande, strlen($r[0])));
		}
		
		$exprCommande =
		[
			'(?<direct>[^`"\'\s]+)',
			"(?<apos>'(?:[^']+|\\\\.)*')",
			'(?<guill>"(?:[^"]+|\\\\.)*")',
		];
		$exprCommande = '#^(?:'.implode('|', $exprCommande).')#';
		
		$c = [];
		
		while(preg_match($exprCommande, $commande, $r))
		{
			switch(true)
			{
				case isset($r['direct']) && strlen($r['direct']):
					$c[] = $r[0];
					break;
				case isset($r['apos']) && strlen($r['apos']):
				case isset($r['guill']) && strlen($r['guill']):
					$c[] = preg_replace('/\\\\(.)/', '\1', substr($r[0], 1, -1));
					break;
			}
			$commande = ltrim(substr($commande, strlen($r[0])));
		}
		
		if(strlen($commande)) throw $this->_sqleur->exception('reste ininterprétable: '.$commande);
		
		$lanceur = new SqleurPréproExécLanceur($this->_sqleur, $p, $c);
		
		// Si la ligne de commande n'est pas précisée, c'est que la requête suivante doit nous la fournir.
		if(!count($c))
		{
			$this->_lanceur = $lanceur;
			$this->_préempterSql();
		}
	}
	
	protected function _chope($req)
	{
		$récup = $this->_sqleur->exécuter($req, true, true);
		$this->_lanceur->lancers($récup);
		$this->_lanceur = null;
	}
	
	protected function _interpréterSortie($nes, $descr, &$p)
	{
		if(isset($p[self::P_ES][$nes])) throw $this->_sqleur->exception('sortie '.$nes.' ambiguë');
		$es = [];
		foreach(preg_split('/\s+/', $descr) as $bout)
		{
			// Est-ce une option?
			
			foreach(self::$OptionsTable as $exprOption => $paramétrageOption)
				if(preg_match('#^(?:'.$exprOption.')$#i', $bout))
				{
					if(count(array_intersect_key($es, $paramétrageOption))) throw $this->_sqleur->exception('option '.$bout.' redondante ou contradictoire avec une des précédentes');
					$es = $paramétrageOption + $es;
					continue 2;
				}
			
			// Sinon le nom de table.
			
			if(isset($es[self::PES_TABLE])) throw $this->_sqleur->exception($descr.': 1 seule table de sortie SVP');
			$es[self::PES_TABLE] = $bout;
		}
		if(!isset($es[self::PES_TABLE])) throw $this->_sqleur->exception($descr.': nom de table manquant');
		$p[self::P_ES][$nes] = $es + [ self::PES_F => self::F_ÉCLAT, self::PES_TEMP => true, self::PES_SEUL_AU_MONDE => false ];
	}
	
	protected $_lanceur;
	
	const PES_F = 'format';
	const F_AGRÉG = '1';
	const F_ÉCLAT = 'n';
	const PES_TEMP = 'temp';
	const PES_SEUL_AU_MONDE = 'vide';
	const PES_TABLE = 'table';
	const P_PID = 'pid';
	const P_E_REQ = 'entrée_requête';
	const P_E_VAL = 'entrée_contenu';
	const P_ES = 'es'; // Entrées - Sorties
	
	protected static $OptionsTable =
	[
		'temp' => [ self::PES_TEMP => true ],
		'statique|static|persis(?:tente?|ing)' => [ self::PES_TEMP => false ],
		'init|vide|new' => [ self::PES_SEUL_AU_MONDE => true ],
		'cumul|append' => [ self::PES_SEUL_AU_MONDE => false ],
		'mono|brute?|raw' => [ self::PES_F => self::F_AGRÉG ],
		'multi' => [ self::PES_F => self::F_ÉCLAT ],
	];
}

/**
 * Préparateur des tables réceptacles, et lanceur des exécutants.
 */
class SqleurPréproExécLanceur
{
	public function __construct($sqleur, $params, $commande = null)
	{
		$this->_sqleur = $sqleur;
		$this->_params = $params;
		
		$this->_initSorties($params);
		
		if($commande && count($commande))
		$this->_lancer($params, $commande);
	}
	
	protected function _initSorties($params)
	{
		$reqs = [];
		
		$parDéfautMaintenant = $pdm = " default MAINTENANT()";
		if(($parDéfautMaintenant = $this->_sqleur->appliquerDéfs($pdm)) == $pdm) $parDéfautMaintenant = '';
		
		if(isset($params[SqleurPreproExec::P_ES]['?'][SqleurPreproExec::PES_TABLE]))
			$this->_tablePid = $params[SqleurPreproExec::P_ES]['?'][SqleurPreproExec::PES_TABLE];
		$tPid = $this->_tablePid;
		$reqs[] = "create temp table if not exists $tPid (id integer, pid varchar(127), r integer)";
		
		$déjà = [ $tPid => true ];
		foreach($params[SqleurPreproExec::P_ES] as $nes => $es)
		{
			if(isset($déjà[$es[SqleurPreproExec::PES_TABLE]])) continue;
			
			$reqs[] = "create".($es[SqleurPreproExec::PES_TEMP] ? ' temp' : '')." table if not exists ".$es[SqleurPreproExec::PES_TABLE]." (pid varchar(127), d integer, h timestamp$parDéfautMaintenant, l integer, t text)";
			if($es[SqleurPreproExec::PES_SEUL_AU_MONDE])
				$reqs[] = "delete from ".$es[SqleurPreproExec::PES_TABLE];
			$déjà[$es[SqleurPreproExec::PES_TABLE]] = true;
			
			if($es[SqleurPreproExec::PES_F] == SqleurPreproExec::F_AGRÉG)
				$this->_tampon[$nes] = [];
			$this->_nl[$nes] = [];
		}
		
		foreach($reqs as $req)
			$this->_sqleur->exécuter($req, true, true);
		
		$this->_es = $params[SqleurPreproExec::P_ES];
	}
	
	public function lancers($commandeur)
	{
		foreach($commandeur->fetchAll(PDO::FETCH_NUM) as $l)
		{
			// Sur la première ligne, on repère les colonnes spéciales (personnalisant l'instance).
			
			if(!isset($spéciales))
			{
				$spéciales = [];
				foreach($l as $col => $val)
				{
					$descr = $commandeur->getColumnMeta($col);
					switch(strtolower($descr['name']))
					{
						case 'pid': $spéciales[$col] = SqleurPreproExec::P_PID; break;
						case '<': $spéciales[$col] = SqleurPreproExec::P_E_VAL; break;
					}
				}
			}
			
			// Allez hop, au boulot.
			
			$params = $this->_params;
			foreach($spéciales as $col => $param)
				$params[$param] = $l[$col];
			$l = array_diff_key($l, $spéciales);
			
			$this->_lancer($params, $l);
		}
	}
	
	protected function _lancer($params, $commande)
	{
		if(!isset($params))
			$params = $this->_params;
		$tPid = $this->_tablePid;
		
		/* Obtention de l'environnement propre à cette instance (PID, etc.) */
		
		$pidi = ++self::$DernierId;
		
		if(isset($params[SqleurPreproExec::P_PID]))
		{
			$pid = $params[SqleurPreproExec::P_PID];
			$pidc = $this->_cs($pid);
		}
		else
		{
			/* À FAIRE: optimiser pour les BdD ayant des regex: ~ '^[0-9]+$' */
			$seultNbres = "pid";
			for($i = 10; --$i >= 0;) $seultNbres = "replace($seultNbres,'$i','')";
			$seultNbres = "length(pid) > 0 and length($seultNbres) = 0";
			$pidc = "1 + coalesce(max(case when $seultNbres then cast(pid as integer) end), 0)";
		}
			
		$pidimax = "coalesce(max(case when id >= $pidi then id + 1 end), $pidi) id";
		$requIns = "insert into $tPid (id, pid) select $pidimax, $pidc pid from $tPid returning id, pid";
			$pids = $this->_sqleur->exécuter($requIns, false, true);
			$pid = $pids->fetchAll();
		if($pid[0]['id'] > $pidi)
			self::$DernierId = $pidi = $pid[0]['id'];
			$pid = $pid[0]['pid'];
		$this->_pids[$pidi] = $pid;
		
		foreach($this->_tampon as $nes => $bla)
			$this->_tampon[$nes][$pidi] = '';
		
		// En cas d'entrée de type < `select …`, celle-ci est calculée une fois pour être passée à l'identique à chacune des instances.
		
		$stdinPrécalc = null;
		if(isset($params[SqleurPreproExec::P_E_REQ]))
		{
			$récupEntrée = $this->_sqleur->exécuter($params[SqleurPreproExec::P_E_REQ], true, true);
			/* À FAIRE: pour les processus longs, du fetch() au fur et à mesure.
			 * Difficulté: si derrière nous avons n instances, la 1ère aura certes son stdin au fur et à mesure, par contre nous devrons mémoriser l'intégralité pour la repasser aux instances suivantes.
			 * Ceci dit dans le cas conventionnel "1 gros stdin passé à une seule instance d'une processus", ça marchera.
			 * On pourrait donc avoir un objet (répondant à l'interface de Processus._source: lire() et poursuivre()),
			 * pointant sur le PDOStatement mutualisé (ce n'est pas la première instance de processus qui a le Statement et les autres la mémoire: en cas de lancement parallèle, ce n'est pas nécessairement la première instance qui parcourra le plus rapidement l'entrant. Donc toutes se partagent le Statement, chacun avec son compteur de ligne, et la première à demander une ligne non encore récupérée déclenche le fetch(). Idéalement on sait aussi à l'avance combien d'instances on va lancer, de manière à pouvoir libérer les lignes une fois ingurgitées par l'ensemble des instances).
			 */
			$résEntrée = $récupEntrée->fetchAll(PDO::FETCH_NUM);
			/* À FAIRE: gérer les différents formats. */
			$stdinPrécalc = '';
			foreach($résEntrée as $l)
				$stdinPrécalc .= implode("\t", $l)."\n";
		}
		else if(isset($params[SqleurPreproExec::P_E_VAL]))
			$stdinPrécalc = $params[SqleurPreproExec::P_E_VAL];
		
		/* Instanciation */
		
		require_once __DIR__.'/../util/processus.php';
		
		$p = new ProcessusLignes($commande, [ $this, '_ligneRés', $pidi ]);
		$r = $p->attendre($stdinPrécalc);
		
		/* Ménage */
		
		foreach($this->_tampon as $nes => $bla)
			if($this->_tampon[$nes][$pidi] !== '')
				$this->_consigner($nes, $pidi, $this->_tampon[$nes][$pidi]);
		
		// Gestion d'erreur: en fonction de s'il a été déclaré une table de recueil (et donc de rattrapage) des erreurs.
		
		$requIns = "update $tPid set r = $r where id = $pidi";
		$this->_sqleur->exécuter($requIns, false, true);
		if($r && !isset($params[SqleurPreproExec::P_ES]['?']))
			throw new Exception("Le processus est sorti en erreur $r");
	}
	
	public function _ligneRés($ligne, $nes, $finDeLigne, $pidi)
	{
		if($ligne === '' && $finDeLigne === '') return; // Juste pour nous dire qu'on a terminé.
		if(!isset($this->_es[$nes])) throw new Exception("Le processus m'a causé sur le descripteur de fichier $nes: $ligne");
		
		/* À FAIRE: en mode mono, pas la peine de laisser le ProcessusLignes découper ligne à ligne pour ensuite reconstituer ici. */
		
		if(isset($this->_tampon[$nes]))
			$this->_tampon[$nes][$pidi] .= $ligne.$finDeLigne;
		else
			$this->_consigner($nes, $pidi, $ligne);
	}
	
	protected function _consigner($nes, $pidi, $texte)
	{
		$table = $this->_es[$nes][SqleurPreproExec::PES_TABLE];
		
		$pid = $this->_pids[$pidi];
		
		if(!isset($this->_nl[$nes][$pidi])) $this->_nl[$nes][$pidi] = 0;
		$numL = ++$this->_nl[$nes][$pidi];
		
		// Évidemment des requêtes paramétrées eussent été plus propres, mais bon le Sqleur est fait pour du texte à la chaîne.
		$texte = $this->_cs($texte);
		$pid = $this->_cs($pid);
		
		$requIns = "insert into $table (pid, d, l, t) values ($pid, $nes, $numL, $texte)";
		
		$this->_sqleur->exécuter($requIns, false, true);
	}
	
	/**
	 * Chaîne SQL
	 */
	protected function _cs($val)
	{
		return $val === null ? 'null' : "'".strtr($val, [ "'" => "''" ])."'";
	}
	
	protected $_sqleur;
	protected $_params;
	protected $_tablePid = 'pid';
	protected $_es;
	protected $_pids = [];
	protected static $DernierId = 0;
	protected $_tampon = []; // Enregistrement des données à sortir en une seule fois en fin de processus.
	protected $_nl = []; // Numéro de ligne.
}

?>
