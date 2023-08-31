<?php
/*
 * Copyright (c) 2020 Guillaume Outters
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

class SqleurPreproCopy extends SqleurPrepro
{
	protected $_préfixes = array('#copy');
	
	public function préprocesse($motClé, $directiveComplète)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$this->_entre($motClé, $directiveComplète);
	}
	
	protected function _entre($motClé, $directiveComplète)
	{
		$this->_pousseur();
		
		$this->_sortieOriginelle = $this->_sqleur->_sortie;
		/* À FAIRE: faire basculer le Sqleur dans un mode ligne à ligne plutôt qu'expression par expression, pour ne pas devoir charger tout le fichier en mémoire avant de l'injecter. */
		$this->_sqleur->_sortie = array($this, '_chope');
		
		$this->_pousseur->init(substr($directiveComplète, 1));
	}
	
	protected function _pousseur()
	{
		if(isset($this->_pousseur))
			return $this->_pousseur;
		
		if(!isset($this->_sqleur->bdd))
			throw new Exception('copy appelé, mais le Sqleur n\'a pas de bdd attachée');
		/* À FAIRE: pgsqlCopyFromArray/File c'est bien gentil mais ça oblige à tout charger en mémoire avant d'appeler.
		 * N'y a-t-il pas une bonne âme pour répliquer ce que font les pg_put_line et pg_end_copy? */
		if(method_exists($this->_sqleur->bdd, 'pgsqlCopyFromFile'))
			$this->_pousseur = new SqleurPreproCopyPousseurPg($this->_sqleur->bdd);
		/* À FAIRE: autres SGBD que PostgreSQL. */
		
		if(!isset($this->_pousseur))
			throw new Exception('le pilote ne dispose d\'aucun moyen de pousser un fichier complet');
		
		return $this->_pousseur;
	}
	
	public function _chope($req)
	{
		/* Pour le moment on ne gère qu'une grosse chaîne de caractères, délimiteur dollar. */
		
		if(!preg_match('/^[$]([^ $]*)[$]\n*/', $req, $rd))
			throw new Exception('copy prend en entrée une chaîne délimitée par dollars');
		if(!preg_match('/\n[$]'.$rd[1].'[$]\n*$/', $req, $rf))
			throw new Exception('copy prend en entrée une chaîne délimitée par dollars et terminée de la même manière');
		
		$bazar = substr($req, strlen($rd[0]), -strlen($rf[0]));
		foreach(explode("\n", $bazar) as $l)
			if($l) // Les lignes vides ne nous intéressent pas.
			$this->_pousseur->ligne($l);
		
		$this->_pousseur->fin();
		
		$this->_sqleur->_sortie = $this->_sortieOriginelle;
		unset($this->_sortieOriginelle);
	}
}

class SqleurPreproCopyPousseurPg
{
	public function __construct($bdd)
	{
		$this->_bdd = $bdd;
	}
	
	public function init($req)
	{
		$expr = 'from (?<from>stdin)|delimiter \'(?<delim>[^\']+)\'';
		
		if(!preg_match("/^copy\\s+(?<t>[a-z0-9_.]+)(?:\\s*\((?<c>[^)]+)\))?(?<p>(?:\\s+(?:$expr))*)\$/i", $req, $r))
			throw new Exception('copy ininterprétable: '.$req);
		preg_match_all("/\\s+(?:$expr)/i", $r['p'], $rpss, PREG_SET_ORDER);
		$rp = array();
		foreach($rpss as $rps)
			foreach($rps as $clé => $val)
				if(!empty($val))
					$rp[$clé] = $val;
		
		$this->_table = $r['t'];
		$this->_champs = preg_split('/\s*,\s*/', $r['c']);
		$this->_sép = empty($rp['delim']) ? "\t" : $rp['delim'];
		
		$this->_données = array();
	}
	
	public function lignes($ls)
	{
		$this->_données = array_merge($this->_données, $ls);
	}
	
	public function ligne($l)
	{
		$this->_données[] = $l;
	}
	
	public function fin()
	{
		if(!count($this->_données)) return;
		
		if(!$this->_bdd->pgsqlCopyFromArray($this->_table, $this->_données, $this->_sép, 'NULL', isset($this->_champs) ? implode(',', $this->_champs) : null))
		{
			$e = $this->_bdd->errorInfo();
			throw new Exception('copy: '.$e[2]);
		}
	}
}

?>
