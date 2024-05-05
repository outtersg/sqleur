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
		$commande = $this->_sqleur->appliquerDéfs($commande);
		
		$optionsTable =
		[
			'temp' => [ self::PES_TEMP => true ],
			'statique|static|persis(?:tente?|ing)' => [ self::PES_TEMP => false ],
			'mono|brute?|raw' => [ self::PES_F => self::F_AGRÉG ],
			'multi' => [ self::PES_F => self::F_ÉCLAT ],
		];
		$exprDest = '(?:(?:'.implode('|', array_keys($optionsTable)).') +)*_';
		$exprBouts =
		[
			'(?<op>'.implode('|', $this->_préfixes).') ',
			"(?<vers>vers|into) (?<stdout>$exprDest)(?:, (?<stderr>$exprDest))?",
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
				case isset($r['vers']) && strlen($r['vers']):
					foreach([ 1 => 'stdout', 2 => 'stderr' ] as $nes => $nom)
					{
						if(!isset($r[$nom]) || !strlen($r[$nom])) continue;
						if(isset($p[self::P_ES][$nes])) throw $this->_sqleur->exception('sortie '.$nes.' ambiguë');
						$es = [];
						foreach(preg_split('/\s+/', $r[$nom]) as $bout)
						{
							// Est-ce une option?
							
							foreach($optionsTable as $exprOption => $paramétrageOption)
								if(preg_match('#^(?:'.$exprOption.')$#i', $bout))
								{
									if(count(array_intersect_key($es, $paramétrageOption))) throw $this->_sqleur->exception('option '.$bout.' redondante ou contradictoire avec une des précédentes');
									$es = $paramétrageOption + $es;
									continue 2;
								}
							
							// Sinon le nom de table.
							
							if(isset($es[self::PES_TABLE])) throw $this->_sqleur->exception($r[$nom].': 1 seule table de sortie SVP');
							$es[self::PES_TABLE] = $bout;
						}
						if(!isset($es[self::PES_TABLE])) throw $this->_sqleur->exception($r[$nom].': nom de table manquant');
						$p[self::P_ES][$nes] = $es + [ self::PES_F => self::F_ÉCLAT, self::PES_TEMP => true ];
					}
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
		
		new SqleurPréproExécLanceur($this->_sqleur, $p, $c);
	}
	
	const PES_F = 'format';
	const F_AGRÉG = '1';
	const F_ÉCLAT = 'n';
	const PES_TEMP = 'temp';
	const PES_TABLE = 'table';
	const P_PID = 'pid';
	const P_ES = 'es'; // Entrées - Sorties
}

/**
 * Préparateur des tables réceptacles, et lanceur des exécutants.
 */
class SqleurPréproExécLanceur
{
	public function __construct($sqleur, $params, $commande)
	{
		$this->_sqleur = $sqleur;
		
		$this->_initSorties($params);
		
		$this->_lancer($params, $commande);
	}
	
	protected function _initSorties($params)
	{
		$reqs = [];
		
		$parDéfautMaintenant = $pdm = " default MAINTENANT()";
		if(($parDéfautMaintenant = $this->_sqleur->appliquerDéfs($pdm)) == $pdm) $parDéfautMaintenant = '';
		
		/* À FAIRE: permettre la paramétrisation du nom de la table des processus */
		$tPid = $this->_tablePid;
		$reqs[] = "create temp table if not exists $tPid (pid varchar(127), r integer)";
		
		$déjà = [];
		foreach($params[SqleurPreproExec::P_ES] as $nes => $es)
		{
			if(isset($déjà[$es[SqleurPreproExec::PES_TABLE]])) continue;
			
			$reqs[] = "create".($es[SqleurPreproExec::PES_TEMP] ? ' temp' : '')." table if not exists ".$es[SqleurPreproExec::PES_TABLE]." (pid varchar(127), d integer, h timestamp$parDéfautMaintenant, l integer, t text)";
			$déjà[$es[SqleurPreproExec::PES_TABLE]] = true;
			
			if($es[SqleurPreproExec::PES_F] == SqleurPreproExec::F_AGRÉG)
				$this->_tampon[$nes] = [];
			$this->_nl[$nes] = [];
		}
		
		foreach($reqs as $req)
			$this->_sqleur->exécuter($this->_sqleur->appliquerDéfs($req), false, true);
		
		$this->_es = $params[SqleurPreproExec::P_ES];
	}
	
	protected function _lancer($params, $commande)
	{
		/* Obtention de l'environnement propre à cette instance (PID, etc.) */
		
		$pidi = count($this->_pids);
		
		if(isset($params[SqleurPreproExec::P_PID]))
			$pid = $params[SqleurPreproExec::P_PID];
		else
		{
			/* À FAIRE: optimiser pour les BdD ayant des regex: ~ '^[0-9]+$' */
			$seultNbres = "pid";
			for($i = 10; --$i >= 0;) $seultNbres = "replace($seultNbres,'$i','')";
			$seultNbres = "length(pid) > 0 and length($seultNbres) = 0";
			$tPid = $this->_tablePid;
			$requIns = "insert into $tPid (pid) select 1 + coalesce(max(cast(pid as integer)), 0) pid from $tPid where $seultNbres returning pid";
			$pids = $this->_sqleur->exécuter($requIns, false, true);
			$pid = $pids->fetchAll();
			$pid = $pid[0]['pid'];
		}
		$this->_pids[$pidi] = $pid;
		
		foreach($this->_tampon as $nes => $bla)
			$this->_tampon[$nes][$pidi] = '';
		
		/* Instanciation */
		
		require_once __DIR__.'/../util/processus.php';
		
		$p = new ProcessusLignes($commande, [ $this, '_ligneRés', $pidi ]);
		$r = $p->attendre(/* À FAIRE: ici le stdin s'il est déjà connu */);
		
		/* Ménage */
		
		foreach($this->_tampon as $nes => $bla)
			if($this->_tampon[$nes][$pidi] !== '')
				$this->_consigner($nes, $pidi, $this->_tampon[$nes][$pidi]);
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
		$texte = "'".strtr($texte, [ "'" => "''" ])."'";
		$pid = "'".strtr($pid, [ "'" => "''" ])."'";
		
		$requIns = "insert into $table (pid, d, l, t) values ($pid, $nes, $numL, $texte)";
		
		$this->_sqleur->exécuter($requIns, false, true);
	}
	
	protected $_sqleur;
	protected $_tablePid = 'pid';
	protected $_es;
	protected $_pids = [];
	protected $_tampon = []; // Enregistrement des données à sortir en une seule fois en fin de processus.
	protected $_nl = []; // Numéro de ligne.
}

?>
