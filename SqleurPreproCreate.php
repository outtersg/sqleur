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
	 */
	public $lombric;
	
	protected $_préfixes = array('#create');
	
	public function grefferÀ($sqleur)
	{
		parent::grefferÀ($sqleur);
		$sqleur->_fonctions['oracle_in'] = [ $this, '_oracle_in' ];
		$sqleur->_fonctionsInternes['oracle_in'] = true;
		
		/* À FAIRE: consommer aussi les commentaires en début de chaîne. */
		$exprCreateFrom = 'create(?: (?<'.self::TEMP.'>temp|temporary))? table (?<'.self::TABLE.'>__) from (?<'.self::SOURCE.'>__) as(?: (?<n>[1-9][0-9]*))?(?: (?<'.self::REQ.'>[\s\S]*))?';
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
		$this->_nReqs = empty($args['n']) ? 1 : $args['n'];
		
		$this->_params = $args;
		
		if(count($this->_reqs) >= $this->_nReqs)
			$this->_lance();
		else
			$this->_entre();
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
		
		if(!preg_match($this->_exprCreateFrom, ltrim($directiveComplète, '#'), $bouts))
			throw $this->_sqleur->exception("'$directiveComplète' n'est pas une expression de create … from");
		
		$this->traiterCreateFrom($bouts);
	}
	
	protected function _entre()
	{
		$this->_sortieOriginelle = $this->_sqleur->_sortie;
		$this->_sqleur->_sortie = array($this, '_chope');
	}
	
	public function _chope($req)
	{
		/* À FAIRE: accepter le mode chaîne dollar? Notamment les ; y sont passés tels quels. */
		
		if(isset($this->_sqleur->terminaison))
			$req .= $this->_sqleur->terminaison;
		$this->_reqs[] = $req;
		
		if(count($this->_reqs) >= $this->_nReqs)
		{
			$this->_sqleur->_sortie = $this->_sortieOriginelle;
			unset($this->_sortieOriginelle);
			$this->_lance();
		}
	}
	
	protected function _lance()
	{	
		require_once dirname(__FILE__).'/../util/processus.php';
		
		$this->_cheminTemp = tempnam(sys_get_temp_dir(), 'sqleur.createfrom.');
		if(!$this->_cheminTemp) throw new Exception('Impossible de créer un fichier temporaire '.sys_get_temp_dir().DIR_SEP.'sqleur.createfrom.…');
		$this->_temp = fopen($this->_cheminTemp, 'w');
		if(false === $this->_temp) throw new Exception('Impossible de créer un fichier temporaire '.$this->_cheminTemp);
		
		$extracteur = array_filter
		([
			$this->lombric(),
			'sql2table',
			'-b',
			$this->_params[self::SOURCE],
			empty($this->_params[self::TEMP]) ? null : '-t',
			$this->_params[self::TABLE].(isset($this->_sqleur->_defs['stat'][':pilote']) ? ':'.$this->_sqleur->_defs['stat'][':pilote'] : ''),
		]);
		$p = new ProcessusLignes($extracteur, [ $this, '_ligneRés']);
		$r = $p->attendre(implode('', $this->_reqs));
		if($r)
			throw new Exception('Le fournisseur de données est sorti en erreur: '.implode(' ', $extracteur));
		
		fclose($this->_temp);
		
		/* À FAIRE: inclure le fichier généré */
		throw new Exception("Pour le moment je ne sais pas encore prendre en compte le fichier généré");
		
		unlink($this->_cheminTemp);
	}
	
	public function lombric()
	{
		if(isset($this->lombric)) return $this->lombric;
		if(($lombric = getenv('LOMBRIC'))) return $lombric;
		throw new Exception("Impossible de lancer l'extraction, aucun lombric n'est défini (veuillez fournir son chemin dans une variable d'environnement LOMBRIC)");
	}
	
	public function _ligneRés($ligne, $fd)
	{
		switch($fd)
		{
			case 2: fprintf(STDERR, "%s\n", $ligne); break;
			case 1: fwrite($this->_temp, $ligne."\n"); break;
			default: throw new Exception("Le processus m'a causé sur le descripteur de fichier $fd");
		}
	}
	
	protected $_pousseur;
	protected $_sortieOriginelle;
	protected $_exprCreateFrom;
	protected $_params;
	protected $_nReqs;
	protected $_reqs;
	protected $_cheminTemp;
	protected $_temp;
	
	const TEMP = 'temp';
	const TABLE = 'table';
	const SOURCE = 'source';
	const REQ = 'req';
}

?>
