<?php
/*
 * Copyright (c) 2023 Guillaume Outters
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

require_once __DIR__.'/SqleurPrepro.php';

/**
 * Interprète les "create [temp] table x from baseDistante as select * from tableDeLaBaseDistante".
 * Requiert la présence d'un lombric pour produire les données intermédiaires.
 */
class SqleurPreproCreate extends SqleurPrepro
{
	/**
	 * Nom de l'exécutable à invoquer pour lancer l'extraction; d'autres moyens d'action existent:
	 * - définir la variable préproc #define LOMBRIC
	 * - définir la variable d'environnement LOMBRIC
	 * - laisser utiliser le lombric fourni avec Sqleur, mais définir la variable d'environnement LOMBRICRC qui désignera un fichier (shell) chargé par lombric
	 * - laisser utiliser le lombric par défaut en ayant défini son paramétrage des différentes bases dans ~/.lombricrc
	 */
	public $lombric;
	
	protected $_préfixes = array('#create');
	
	public function grefferÀ($sqleur)
	{
		parent::grefferÀ($sqleur);
		$sqleur->_fonctions['oracle_in'] = [ $this, '_oracle_in' ];
		$sqleur->_fonctionsInternes['oracle_in'] = true;
		
		/* À FAIRE: consommer aussi les commentaires en début de chaîne. */
		$exprCreateFrom = 'create(?: (?<'.self::TEMP.'>temp|temporary))?(?: (?<'.self::BINAIRE.'>bin|binary))? table (?<'.self::TABLE.'>__) (?<'.self::SENS.'>from|into) (?<'.self::SOURCE.'>__) as(?: (?<n>[1-9][0-9]*|<<__))?(?: (?<'.self::REQ.'>[\s\S]*))?';
		$this->_exprCreateFrom = '/^'.strtr($exprCreateFrom, [ ' ' => '[\s\r\n]+', '__' => '[^\s]+' ]).'$/i';
		// N.B.: la définition suivante ne marche pas, car les expressions n'étant pas prévues pour être multilignes, notre expression est appelée au premier retour à la ligne: si le select est ligne suivante il ne nous est pas transmis.
		// À FAIRE: dans le SQLeur, permettre à certaines expressions de se déclarer intéressées par une exécution tardive (sur ; plutôt que sur \n).
		// En attendant, utiliser la forme préproc #create
		// On pourrait aussi intercepter la requête suivante, comme le fait le préproc (mais il faudrait donc être appelés strictement comme le prépro: notre create sur une seule ligne jusqu'au as, et la requête distante sur une ligne suivante.
		$sqleur->ajouterDéfs([ $this->_exprCreateFrom => [ $this, 'traiterCreateFrom' ] ]);
	}
	
	public function traiterCreateFrom($args)
	{
		$this->_reqs = [];
		if(!empty($args[self::REQ]))
			$this->_reqs[] = $args[self::REQ];
		$nReqs = empty($args['n']) ? 1 : (is_int($args['n']) ? (int)$args['n'] : preg_replace('/^<< */', '', $args['n']));
		
		$this->_params = $args;
		$this->_finDeLigne = (!empty($args[self::BINAIRE])) ? "\036" : null;
		
		if(is_numeric($nReqs) && 0 >= ($nReqs -= count($this->_reqs)))
			$this->_lance();
		else
			$this->_préempterSql($nReqs);
	}
	
	public function _oracle_in($col, $vals, $n = null)
	{
		if(!count($vals)) return '1 = 0';
		
		if(!isset($n)) $n = 1000;
		
		$vals = array_map(function($x) { return "'".strtr($x, [ "'" => "''" ])."'"; }, $vals);
		
		if(preg_match('/^[1-9][0-9]*[bc]$/', $n))
		{
			$n = 0 + substr($n, 0, -1);
			$n -= 3; // Un peu de place pour les or en début de ligne.
			$rs = [];
			$r = '';
			foreach($vals as $val)
			{
				if($r && strlen($r) + strlen($val) > $n) // L'if($r) force au moins une valeur par ligne si la limite a été vue vraiment trop basse.
				{
					$rs[] = $r.')';
					$r = '';
				}
				$r .= ($r ? ',' : $col.' in (').$val;
			}
			if(strlen($r)) $rs[] = $r. ')';
		}
		else
			$rs = array_map(function($x) use($col) { return $col.' in ('.implode(',', $x).')'; }, array_chunk($vals, $n));
		$r = count($rs) <= 1 ? $rs[0] : "(\n".implode("\nor ", $rs)."\n)";
		return $r;
	}
	
	public function préprocesse($motClé, $directiveComplète)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$directiveComplète = $this->_sqleur->appliquerDéfs($directiveComplète);
		
		if(!preg_match($this->_exprCreateFrom, ltrim($directiveComplète, '#'), $bouts))
			throw $this->_sqleur->exception("'$directiveComplète' n'est pas une expression de create … from / into");
		
		$this->traiterCreateFrom($bouts);
	}
	
	public function _chope($req)
	{
		/* À FAIRE: accepter le mode chaîne dollar? Notamment les ; y sont passés tels quels. */
		
		if($req)
		{
		if(isset($this->_sqleur->terminaison))
			$req .= $this->_sqleur->terminaison;
		$this->_reqs[] = $req;
		}
		
		if(!$this->_nReqsÀChoper)
			$this->_lance();
	}
	
	protected function _lance()
	{	
		require_once dirname(__FILE__).'/../util/processus.php';
		
		$this->_cheminTemp = tempnam(sys_get_temp_dir(), 'sqleur.createfrom.');
		if(!$this->_cheminTemp) throw new Exception('Impossible de créer un fichier temporaire '.sys_get_temp_dir().DIR_SEP.'sqleur.createfrom.…');
		$this->_temp = fopen($this->_cheminTemp, 'w');
		if(false === $this->_temp) throw new Exception('Impossible de créer un fichier temporaire '.$this->_cheminTemp);
		
		if(in_array(strtolower($this->_params[self::SENS]), [ 'into', 'to' ]))
			$this->_pousse();
		else
			$this->_tire();
	}
	
	protected function _tire()
	{
		$extracteur = array_filter
		([
			$this->lombric(),
			'sql2table',
			'-b',
			$this->_params[self::SOURCE],
			'-s', "\003",
			isset($this->_finDeLigne) ? '-l' : null, $this->_finDeLigne,
			empty($this->_params[self::TEMP]) ? '-c' : '-t', // -c en création, -t en create temporary
			$this->_params[self::TABLE].(isset($this->_sqleur->_defs['moteur'][':pilote']) ? ':'.$this->_sqleur->_defs['moteur'][':pilote'] : ''),
			'-',
		]);
		$p = new ProcessusLignes($extracteur, [ $this, '_ligneRés']);
		$r = $p->attendre(implode('', $this->_reqs));
		if($r)
			throw new Exception('Le fournisseur de données est sorti en erreur: '.implode(' ', $extracteur));
		
		fclose($this->_temp);
		
		$this->_sqleur->éphéméride[] = [ $this, '_injecte', $this->_cheminTemp ];
	}
	
	public function _injecte($fichierTemp)
	{
		$this->_sqleur->_découpeFichier($fichierTemp);
		unlink($fichierTemp);
	}
	
	protected function _pousse()
	{
		if(!class_exists('JoueurSql') || !$this->_sqleur instanceof JoueurSql) throw new Exception("Le create into n'est accessible que depuis un JoueurSql de sql2csv.php");
		
		$sortie = $this->_sqleur->sortie;
		$this->_sqleur->commencerIncise($this->_cheminTemp, $this->_temp, JoueurSql::CSVBRUT, "\003", $this->_finDeLigne, false);
		$this->_sqleur->typeCols = [];
		foreach($this->_reqs as $req)
			$this->_sqleur->exécuter($req, false, false);
		// À FAIRE: pondre un premier jet de la description des colonnes, afin que les pondeurs qui n'ont pas besoin du maxLen (ceux qui acceptent du text) puissent traiter le fichier sortie au fil de l'eau (à chaque exporterLigne()) plutôt que d'attendre la ponte de la description finale.
		$typeCols = $this->_sqleur->typeCols;
		$this->_sqleur->typeCols = null;
		$this->_sqleur->terminerIncise();
		fclose($this->_temp);
		$this->_temp = STDOUT; // Pour si le processus invoqué en sous-jacent décide de nous causer.
		
		$descrCols = [];
		foreach($typeCols as $numCol => $descrCol)
		{
			switch($descrCol['pdo_type'])
			{
				case PDO::PARAM_BOOL:
					$descrType = 'boolean';
					break;
				case PDO::PARAM_INT:
					$descrType = 'integer';
					break;
				case PDO::PARAM_STR:
					switch($descrCol['native_type'])
					{
						case 'timestamp': $descrType = 'timestamp'; break;
						case 'date': $descrType = 'date'; break;
						default:
					$taille = -1;
					if($taille <= 0 && isset($descrCol['maxLen'])) $taille = $descrCol['maxLen'];
					if($taille <= 0 && isset($descrCol['len'])) $taille = $descrCol['len'];
					$descrType = $taille > 0 ? 'varchar('.$taille.')' : 'text';
							break;
					}
					break;
				case PDO::PARAM_LOB:
					$descrType = 'clob';
					break;
				default:
					throw new Exception('Je ne sais pas exprimer le type PDO '.$descrCol['pdo_type']);
			}
			$descrCols[] = $descrCol['name'].' '.$descrType;
		}
		$descrCols = implode(",\n", $descrCols)."\n";
		file_put_contents($this->_cheminTemp.'.descr', $descrCols);
		
		/*- Lancement -*/
		
		$intracteur = array_filter
		([
			$this->lombric(),
			'csv2table',
			'-b',
			$this->_params[self::SOURCE],
			'-s', "\003",
			isset($this->_finDeLigne) ? '-l' : null, $this->_finDeLigne,
			'--drop',
			$this->_params[self::TABLE],
			$this->_cheminTemp.'.descr',
			$this->_cheminTemp,
		]);
		$p = new ProcessusLignes($intracteur, [ $this, '_ligneRés']);
		$r = $p->attendre(implode('', $this->_reqs));
		if($r)
			throw new Exception('Le fournisseur de données est sorti en erreur: '.implode(' ', $intracteur));
		
		unlink($this->_cheminTemp);
		unlink($this->_cheminTemp.'.descr');
	}
	
	public function lombric()
	{
		if(isset($this->lombric)) return $this->lombric;
		if(($lombric = getenv('LOMBRIC'))) return $lombric;
		return __DIR__.'/lombric';
		throw new Exception("Impossible de lancer l'extraction, aucun lombric n'est défini (veuillez fournir son chemin dans une variable d'environnement LOMBRIC)");
	}
	
	public function _ligneRés($ligne, $fd)
	{
		switch($fd)
		{
			case 2: fprintf(STDERR, "%s\n", $ligne); break;
			case 1: fwrite($this->_temp, $ligne.(isset($this->_finDeLigne) ? $this->_finDeLigne : "\n")); break;
			default: throw new Exception("Le processus m'a causé sur le descripteur de fichier $fd");
		}
	}
	
	protected $_pousseur;
	protected $_exprCreateFrom;
	protected $_params;
	protected $_reqs;
	protected $_cheminTemp;
	protected $_temp;
	protected $_finDeLigne;
	
	const TEMP = 'temp';
	const BINAIRE = 'bin';
	const TABLE = 'table';
	const SOURCE = 'source';
	const REQ = 'req';
	const SENS = 'sens';
}

?>
