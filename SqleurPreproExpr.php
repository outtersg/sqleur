<?php
/*
 * Copyright (c) 2016-2017,2019 Guillaume Outters
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

class SqleurPreproExpr
{
	public function decouper($expr)
	{
		$bouts = array();
		
		preg_match_all('# +|[,"!]|==#', $expr, $découpe, PREG_OFFSET_CAPTURE);
		$pos = 0;
		foreach($découpe[0] as $découpé)
		{
			if($découpé[1] > $pos)
				$bouts[] = substr($expr, $pos, $découpé[1] - $pos);
			$bouts[] = $découpé[0];
			$pos = $découpé[1] + strlen($découpé[0]);
		}
		if(strlen($fin = substr($expr, $pos)))
			$bouts[] = $fin;
		
		return $bouts;
	}
	
	public function compiler($expr)
	{
		$bouts = $this->decouper($expr);
		$racine = $this->arborer($bouts);
		while(is_array($racine) && count($racine) == 1)
			$racine = array_shift($racine);
		
		return $racine;
	}
	
	public function arborer($bouts)
	{
		$recherchés = array
		(
			array
			(
				',' => 'bi',
				'!' => 'devant',
				'not' => 'devant',
				'defined' => 'devant',
				'in' => 'bimulti',
				'==' => 'bi',
				'"' => 'chaîne',
			),
		);
		
		foreach($recherchés as $plan => $recherchésPlan)
			foreach($bouts as $num => $bout)
				if(is_string($bout) && isset($recherchésPlan[$bout]))
				{
					switch($recherchésPlan[$bout])
					{
						case 'bimulti':
						case 'bi':
							$racine = new NœudPrepro($bout);
							$fils = array(array_slice($bouts, 0, $num), array_slice($bouts, $num + 1));
							foreach($fils as $fil)
							{
								$fil = $this->arborer($fil);
								if(is_array($fil))
								{
									if(count($fil) != 1)
										throw new Exception('L\'opérateur binaire '.$bout.' attend deux membres de part et d\'autre');
									$fil = array_shift($fil);
								}
								$racine->f[] = $fil;
							}
							if($recherchésPlan[$bout] == 'bimulti')
								$racine->f[1] = $this->listerVirgules($racine->f[1]);
							return $racine;
						case 'devant':
							array_splice($bouts, $num, 1);
							$racine = new NœudPrepro($bout, $this->arborer($bouts));
							return $racine;
						case 'chaîne':
							$chaînes = array();
							for($fin = $num; ++$fin < count($bouts);)
								if($bouts[$fin] == '"')
									break;
								else if(!is_string($bouts[$fin]))
									throw new Exception('Erreur interne du préprocesseur, une chaîne contient un bout déjà interprété'); // À FAIRE?: permettre l'inclusion de variables dans la chaîne (f deviendrait alors un tableau d'éléments chaîne ou Nœud, et la constitution finale de la chaîne ne serait faite qu'au calcul.
								else
									$chaînes[] = $bouts[$fin];
							if($fin == count($bouts))
								throw new Exception('Chaîne non terminée');
							$nœud = new NœudPrepro('"', $chaînes);
							array_splice($bouts, $num, $fin - $num + 1, array($nœud));
							return $this->arborer($bouts);
					}
				}
		$trucs = array();
		foreach($bouts as $truc)
			if(is_object($truc))
				$trucs[] = $truc;
			else if(strlen(trim($truc)))
				$trucs[] = new NœudPrepro('mot', $truc);
		return $trucs;
	}
	
	public function listerVirgules($expr)
	{
		if(!($expr instanceof NœudPrepro))
			throw new Exception('Truc improbable après une virgule');
		
		if($expr->t != ',')
			return array($expr);
		
		$r = array_merge(array($expr->f[0]), $this->listerVirgules($expr->f[1]));
		
		return $r;
	}
	
	public function calculer($expr, $contexte)
	{
		$racine = $this->compiler($expr);
	
		if(!($racine instanceof NœudPrepro))
			throw new Exception('Expression ininterprétable: '.$expr);
		
		return $racine->exécuter($contexte);
	}
}

class NœudPrepro
{
	public function __construct($type, $fils = null)
	{
		$this->t = $type;
		$this->f = $fils;
	}
	
	public function exécuter($contexte)
	{
		switch($this->t)
		{
			case '!':
			case 'not':
				// Si notre 'not' est appelé avant une valeur (not 1) plutôt qu'avant un autre opérateur booléen (not in), on prend la valeur comme booléen à passer au 'not'.
				// À FAIRE: en vrai de vrai ça ne marche que dans le cas "une seule valeur", car j'ai foiré ma priorité des opérateurs, et 1, not 2, 3 est vu comme 1, not (2, 3) au lieu de A, (not 2), 3.
				$val = $this->f;
				while(is_array($val) && count($val) == 1)
					$val = array_shift($val);
				return !$this->_contenu($val, $contexte);
			case 'mot':
				if(preg_match('/^[0-9]*$/', $this->f))
					return 0 + $this->f;
				else if(!array_key_exists($this->f, $contexte->_defs))
					throw new Exception("Variable de préproc '".$this->f."' indéfinie");
				return $contexte->_defs[$this->f];
			case '"':
				$r = '';
				foreach($this->f as $f)
					if(is_string($f))
						$r .= $f;
					else
						$r .= $this->_contenu($f, $contexte);
				return $r;
			case '==':
				$fils = $this->_contenus($this->f, $contexte, 2);
				return $fils[0] == $fils[1];
			case 'in':
				$gauche = $this->_contenu($this->f[0], $contexte);
				$droite = $this->_contenus($this->f[1], $contexte);
				return in_array($gauche, $droite);
			case 'defined':
				$var = is_array($this->f) && count($this->f) == 1 && isset($this->f[0]) && is_object($this->f[0]) && $this->f[0] instanceof NœudPrepro && $this->f[0]->t == 'mot' && is_string($this->f[0]->f) ? $this->f[0]->f : $this->_contenu($this->f[0], $contexte);
				return array_key_exists($var, $contexte->_defs);
			default:
				throw new Exception('Je ne sais pas gérer les '.$this->t);
		}
	}
	
	protected function _contenu($chose, $contexte)
	{
		if(!($chose instanceof NœudPrepro))
			throw new Exception($this->t.': requièrt un nœud fils');
		return $chose->exécuter($contexte);
	}
	
	protected function _contenus($tableau, $contexte, $n = false)
	{
		$fils = array();
		if(!is_array($tableau))
			throw new Exception($this->t.': liste attendue');
		foreach($tableau as $f)
			if(!($f instanceof NœudPrepro))
				throw new Exception('Impossible d\'interpréter '.print_r($this, true));
			else
				$fils[] = $f->exécuter($contexte);
		if($n !== false && count($fils) != $n)
			throw new Exception($this->t.': '.$n.' nœuds fils attendus');
		return $fils;
	}
}

?>
