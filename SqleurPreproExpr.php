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
	public static $Ops = array
	(
		'and', '&&', 'or', '||',
		'==', '=', '!=', '~', '<', '<=', '>=', '>',
		'+', '-', '*', '/',
		'in',
	);
	
	public function decouper($expr)
	{
		$bouts = array();
		
		preg_match_all('# +|[<=>]=|[,"\'`!/()<=>]#', $expr, $découpe, PREG_OFFSET_CAPTURE); # Bien penser à mettre les expressions les plus longues (<=) avant ses sous-ensembles (<), sans quoi c'est la seconde qui est prise à la place de la première.
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
		$this->_parenthèses = array();
		$racine = $this->arborer($bouts);
		while(is_array($racine) && count($racine) == 1)
			$racine = array_shift($racine);
		
		return $racine;
	}
	
	/* NOTE: ,
	 * La virgule peut intervenir sur deux plans.
	 * Classiquement, elle est sur un plan prioritaire. Ainsi:
	 *   fonction(machin or truc, bidule) 
	 * se groupe:
	 *   fonction({machin or truc}, {bidule})
	 * Cependant, après le mot-clé "in", on accepte une liste à virgules (historiquement pour une question de simplicité d'écriture).
	 * Ainsi:
	 *   fonction(machin in "truc", "bidule")
	 * se lit:
	 *   fonction({machin} in {"truc", "bidule"})
	 * et non:
	 *   fonction({machin in "truc"}, {"bidule"})
	 * Pour gérer cette particularité, la virgule est placée sur un plan prioritaire; lorsqu'elle est appelée dans ce plan, elle regarde ce qui la précède, et, si elle trouve un "in", passe son tour, pour se faire retraiter plus tard sur le plan moins prioritaire.
	 * N.B.: afin de rester sur la simplicité d'une analyse par la gauche, on ne gérera que les cas simples: "… in <mot>, <mot>, <mot>" ne sera compris que pour des <mot>s simples, et non des expressions. Ainsi, "var in 3 * 2, 4" sera interprété comme "((var in 3) * 2), 4". Si vous n'êtes pas contents utilisez des parenthèses.
	 * N.B.: l'analyse se fait avant arborisation de la partie gauche, afin que la virgule de "a not in 1, 2" détecte l'"in" dans sa partie gauche brute; si on arborait, le not deviendrait la racine de la partie gauche à la place du in.
	 */
	public static $Prios = array
	(
		array
		(
			"'" => 'chaîne',
			'"' => 'chaîne',
			'`' => 'chaîne',
			'/' => 'chaîne',
		),
		array
		(
			'(' => '(',
			')' => ')',
		),
		array
		(
			',' => ',',
		),
		array
		(
			'!' => 'devant',
			'not' => 'devant',
			'and' => 'bi',
			'or' => 'bi',
		),
		array
		(
			'+' => 'bi',
			'-' => 'bi',
		),
		array
		(
			'*' => 'bi',
		),
		array
		(
			'in' => 'bimulti',
			',,' => ',', // La , du in, créée par celle de niveau plus haut.
			'==' => 'bi',
			'=' => 'bi',
			'!=' => 'bi',
			'~' => 'bi',
			'<' => 'bi',
			'<=' => 'bi',
			'>=' => 'bi',
			'>' => 'bi',
		),
		array
		(
			'defined' => 'devant',
		),
	);
	public static $Fermantes = array
	(
		'(' => ')',
		'[' => ']',
		'{' => '}',
	);
	
	public function arborer($bouts, $positions = null, $exprComplète = null)
	{
		if(!isset($positions))
		{
			$exprComplète = '';
			$pos = 0;
			$positions = array();
			foreach($bouts as $num => $bout)
			{
				$positions[$num] = $pos;
				if(is_string($bout))
					$exprBout = $bout;
				else if(is_object($bout) && $bout instanceof NœudPrepro && isset($bout->expr))
					$exprBout = $bout->expr;
				else
					return $this->_arborer($bouts, null, null);
				$pos += strlen($exprBout);
				$exprComplète .= $exprBout;
			}
		}
		else if(count($bouts) > 0)
		{
			$numBout = count($bouts) - 1;
			$bout = $bouts[$numBout];
			if(is_string($bout))
				$exprBout = $bout;
			else if(is_object($bout) && $bout instanceof NœudPrepro && isset($bout->expr))
				$exprBout = $bout->expr;
			if(isset($exprBout))
				$exprComplète = substr($exprComplète, 0, $positions[$numBout] + strlen($exprBout));
			else
				$exprComplète = null;
		}
		else
			$exprComplète = '';
			
		try
		{
			$r = $this->_arborer($bouts, $positions, $exprComplète);
			if(is_object($r) && $r instanceof NœudPrepro && !isset($r->expr) && !isset($r->pos))
				$r->infosPosition($bouts, $positions, 0, count($bouts) - 1, $exprComplète);
			return $r;
		}
		catch(ErreurExpr $ex)
		{
			if(isset($ex->pos))
			{
				if(!isset($ex->_dernierPassage))
					$ex->setMessage($ex->getMessage()."\n".$this->_diag('  expr('.$ex->pos.') ', $exprComplète, $ex->pos));
				else if($ex->_dernierPassage != $exprComplète)
					$ex->setMessage($ex->getMessage()."\n  dans ".$exprComplète);
				$ex->_dernierPassage = $exprComplète;
			}
			throw $ex;
		}
	}
	
	public function _arborer($bouts, $positions, $exprComplète)
	{
		foreach(static::$Prios as $plan => $recherchésPlan)
		{
			foreach($bouts as $num => $bout)
				if(is_string($bout) && isset($recherchésPlan[$bout]))
				{
					switch($recherchésPlan[$bout])
					{
						case ',':
						case 'bimulti':
						case 'bi':
							// Détection du cas particulier de la , du in.
							if($this->_virguleRétrogradée($bouts, $num))
								break;
							// Autres cas.
							$racine = new NœudPrepro($bout);
							$fils = array(array_slice($bouts, 0, $num), array_slice($bouts, $num + 1));
							if(isset($positions))
								$positionsFils = array(array_slice($positions, 0, $num), array_slice($positions, $num + 1));
							foreach($fils as $numFil => $fil)
							{
								$fil = $this->arborer($fil, isset($positionsFils) ? $positionsFils[$numFil] : null, $exprComplète);
								if(is_array($fil))
								{
									// À FAIRE: permettre la dernière virgule vide.
									if(count($fil) != 1)
										throw new ErreurExpr('L\'opérateur binaire '.$bout.' attend deux membres de part et d\'autre; reçu à '.($numFil ? 'droite' : 'gauche').":\n".print_r($fil, true), $positions, $num);
									$fil = array_shift($fil);
								}
								$racine->f[] = $fil;
							}
							switch($recherchésPlan[$bout])
							{
								case ',':
									// Les virgules successives sont agrégées.
									if(is_object($racine->f[1]) && $racine->f[1] instanceof NœudPrepro && in_array($racine->f[1]->t, array(',', ',,')))
										array_splice($racine->f, 1, 1, $racine->f[1]->f);
									break;
								case 'bimulti':
									// Les opérateurs qui acceptent à droite une liste à virgules la voient transformée en tableau.
									if(is_array($racine->f[1])) {}
									else if(is_object($racine->f[1]) && $racine->f[1] instanceof NœudPrepro && in_array($racine->f[1]->t, array(',,', ','))) // Les deux types de liste conviennent: soit « toto in "a", "b" » dont la virgule se sera transformée en ,, lorsqu'elle se sera vue suivant un bimulti; soit « toto in ("a", "b") » qui aura généré de la vraie virgule (plus exactement une parenthèse contenant de la virgule, mais la parenthèse se sera effacée au profit de son contenu).
										$racine->f[1] = $racine->f[1]->f;
									else
										$racine->f[1] = array($racine->f[1]);
									break;
							}
							return $racine;
						case 'devant':
							$this->_splice($bouts, $positions, $num, 1);
							$racine = new NœudPrepro($bout, $this->arborer($bouts, $positions, $exprComplète));
							return $racine;
						case ')':
							// On vérifie qu'on est appelés au bon endroit.
							if(!($ouvrante = array_pop($this->_parenthèses)))
								throw new ErreurExpr($bout.' sans son ouverture', $positions, $num);
							if($bout != static::$Fermantes[$ouvrante])
								throw new ErreurExpr($bout.' trouvé, '.static::$Fermantes[$ouvrante].' attendu', $positions, $num);
							// On ne s'embête pas: l'arboraison sera faite par la parenthèse ouvrante correspondante.
							$racine = new NœudPrepro($bout, array(array_slice($bouts, 0, $num), array_slice($bouts, $num + 1)));
							return $racine;
						case '(':
							$this->_parenthèses[] = $bout;
							$dedansEtAprès = $this->arborer(array_slice($bouts, $num + 1), $positions ? array_slice($positions, $num + 1) : null, $exprComplète);
							if(!is_object($dedansEtAprès) || ! $dedansEtAprès instanceof NœudPrepro || $dedansEtAprès->t != static::$Fermantes[$bout])
								throw new ErreurExpr($bout.' sans son '.static::$Fermantes[$bout], $positions, $num);
							$dedans = new NœudPrepro($bout, $this->arborer($dedansEtAprès->f[0]));
							$après = $dedansEtAprès->f[1];
							$this->_splice($bouts, $positions, $num, count($bouts), array_merge(array($dedans), $après));
							// On ne partira pas d'ici sans avoir déterminé l'usage de cette parenthèse: ouvre-t-elle une liste d'arguments de fonction, ou bien sert-elle simplement à regrouper des trucs pour une question de priorité d'opérateurs?
							$this->_usageParenthèse(/*&*/ $bouts, /*&*/ $positions, /*&*/ $num);
							// Réarborons le tout!
							return $this->arborer($bouts, $positions, $exprComplète);
						case 'chaîne':
							$chaînes = array();
							for($fin = $num; ++$fin < count($bouts);)
								if($bouts[$fin] == $bout)
								{
									// Si le caractère qui nous précède est un antislash, on ne sort pas, finalement.
									if(($dernière = count($chaînes) - 1) >= 0 && is_string($chaînes[$dernière]) && substr($chaînes[$dernière], -1) == '\\')
										$chaînes[$dernière] = substr($chaînes[$dernière], 0, -1).$bout;
									// Sinon, ce premier caractère rencontré identique à celui de début marque la fin.
									else
									break;
								}
								else if(!is_string($bouts[$fin]))
									throw new Exception('Erreur interne du préprocesseur, une chaîne contient un bout déjà interprété'); // À FAIRE?: permettre l'inclusion de variables dans la chaîne (f deviendrait alors un tableau d'éléments chaîne ou Nœud, et la constitution finale de la chaîne ne serait faite qu'au calcul.
								else
									$chaînes[] = $bouts[$fin];
							if($fin == count($bouts))
								throw new Exception('Chaîne non terminée');
							$chaînes = implode('', $chaînes);
							if($bout == '/')
								$chaînes = $this->_regex($chaînes);
							$nœud = new NœudPrepro($bout == "'" ? '"' : $bout, $chaînes);
							$this->_splice($bouts, $positions, $num, $fin - $num + 1, array($nœud));
							return $this->arborer($bouts, $positions, $exprComplète);
					}
				}
		}
		$trucs = array();
		foreach($bouts as $num => $truc)
			if(is_object($truc) || is_array($truc))
				$trucs[] = $truc;
			else if(strlen(trim($truc)))
				$trucs[] = new NœudPrepro('mot', $truc);
		return $trucs;
	}
	
	protected function _diag($prologue, $expr, $posErr, $tailleMaxExtrait = 80)
	{
		if(($tExpr = strlen($expr)) > $tailleMaxExtrait)
		{
			$ellipse = '…';
			$tEllipse = 1;
			$posErrExtrait = $posErr - $tailleMaxExtrait / 2;
			if($posErrExtrait <= 0)
			{
				$posErrExtrait = 0;
				$expr = substr($expr, 0, $tailleMaxExtrait).$ellipse;
			}
			else
			{
				$expr = $ellipse.substr($expr, $posErrExtrait, $tailleMaxExtrait);
				if($posErrExtrait + $tailleMaxExtrait < $tExpr)
					$expr .= $ellipse;
				$posErrExtrait += $tEllipse;
			}
		}
		else
			$posErrExtrait = $posErr;
		return $prologue.$expr."\n".str_repeat(' ', strlen($prologue) + $posErrExtrait).'^';
	}
	
	protected function _splice(& $bouts, & $positions, $depuis, $combien, $rempl = null)
	{
		array_splice($bouts, $depuis, $combien, $rempl);
		if($positions)
		{
		array_splice($positions, $depuis, $combien, $rempl ? array_fill(0, count($rempl), null) : null);
			// Si l'on trouve des infos de positions dans $rempl, on les reporte dans $positions, ce qui sera bien plus propre que les null de ci-dessus.
			if(is_array($rempl))
			{
				$i = $depuis;
				foreach($rempl as $bidule)
				{
					if(is_object($bidule) && $bidule instanceof NœudPrepro && isset($bidule->pos))
						$positions[$i] = $bidule->pos;
					++$i;
				}
			}
		}
	}
	
	public function _regex($regex)
	{
		foreach(array('/', '#', '!', '$', '"', '&', '@', "\003", null) as $encadreur)
			if(!isset($encadreur)) // Eh ben, l'expression aura réussi à épuiser toutes nos ressources!
				$this->_err($regex, 'tous les caractères spéciaux sont pris, impossible de constituer une regex');
			else if(strpos($regex, $encadreur) === false)
				break;
		return $encadreur.$regex.$encadreur;
	}
	
	public function listerVirgules($expr)
	{
		if(!($expr instanceof NœudPrepro))
			throw new Exception('Truc improbable après une virgule');
		
		if(!in_array($expr->t, array(',', ',,')))
			return array($expr);
		
		$r = array_merge(array($expr->f[0]), $this->listerVirgules($expr->f[1]));
		
		return $r;
	}
	
	protected function _virguleRétrogradée(& $bouts, $num)
	{
		// Bon déjà celui-ci est-il une virgule?
		if(!is_string($bouts[$num]) || $bouts[$num] != ',')
			return false;
		// Le précédent (hors espaces) est-il un mot simple?
		if(($numPréc = $this->_précédentNonVide($bouts, $num, static::PREC_MOT_SIMPLE)) === false)
			return false;
		// Le pénultième est-il un bimulti (ou bien une ",," précédemment posée)?
		if(($numPréc = $this->_précédentNonVide($bouts, $numPréc, static::PREC_BIMULTI)) === false)
			return false;
		// C'est gagné!
		$bouts[$num] = ',,';
		return true;
	}
	
	protected function _simplifierParenthèseGroupement($bout, $positions, $num)
	{
		if(is_object($bout->f) && $bout->f instanceof NœudPrepro)
			return $bout->f;
		else if(is_array($bout->f) && count($bout->f) == 1)
			return $bout->f[0];
		throw new ErreurExpr("le contenu de la parenthèse groupante doit être atomique", $positions, $num);
	}
	
	/**
	 * S'assure qu'une parenthèse a un usage parmi ceux identifiés (soit pour ouvrir la liste de paramètres d'une fonction, avec des virgules dedans, soit pour constituer un groupe, sans virgule (ex.: "truc or (machin and bidule)")).
	 */
	protected function _usageParenthèse(& $bouts, & $positions, & $num)
	{
		$bout = $bouts[$num];
		if(!is_object($bout) || ! $bout instanceof NœudPrepro || $bout->t != '(')
			throw new Exception("_usageParenthèse() appelée sur un nœud non parenthèse");
		if(($numPréc = $this->_précédentNonVide($bouts, $num, static::PREC_SAUF_OP | static::PREC_BI | static::PREC_BIMULTI)) !== false)
		{
			if($this->_estBimulti($bouts[$numPréc]))
			{
				// Les opérateurs bimulti (<machin> <bimulti> <liste>) fonctionnent selon deux modes:
				// - <machin> <bimulti> <valeur> , <valeur> , <valeur>
				// - <machin> <bimulti> ( <valeur , <valeur> , <valeur> )
				// Nous sommes dans le second cas.
				// Nous travaillons maintenant sur la liste de valeurs: nous la transformons en quelque chose qui ressemble au premier cas.
				// À la différence de la fonction, où nous avons tout sous la main ("f(x)" ne dépend pas de ce qui précède), pour un bimulti "… in (x, y)" le in dépend des …, potentiellement complexes; nous la parenthèse n'essaierons donc pas de nous immiscer dans le calcul de l'arbre du bimulti, et nous contenterons donc de nous réécrire (le nœud parenthèse) de manière à ce que plus tard, lorsque le bimulti calculera son arbre, il nous trouve sous une forme qu'il lui soit aisé de traiter.
				++$numPréc;
				if(is_object($bout->f) && $bout->f instanceof NœudPrepro && $bout->f->t == ',')
					$bout = $bout->f;
				else if(!is_array($bout->f))
					throw new ErreurExpr("Erreur interne: opérateur à parenthèse de contenu incompilable", $positions, $num);
				$bout->t = ',,';
			}
			else if($this->_estOp($bouts[$numPréc]))
			{
				// Parenthèse de regroupement: "2 * (3 + 4). On est
				// À FAIRE
				++$numPréc;
				$bout = $this->_simplifierParenthèseGroupement($bout, $positions, $num);
			}
			else
			{
				$bout = new NœudPrepro('f', array($bouts[$numPréc], $bout));
				if(is_object($bout->f[1]) && $bout->f[1] instanceof NœudPrepro && $bout->f[1]->t == '(')
					$bout->f[1] = $bout->f[1]->f;
				if(is_object($bout->f[1]) && $bout->f[1] instanceof NœudPrepro && $bout->f[1]->t == ',')
					$bout->f[1] = $bout->f[1]->f;
			}
			// On _splice de toute manière, pour écrabouiller les éventuels espaces entre l'élément significatif et sa parenthèse.
			$this->_splice($bouts, $positions, $numPréc, $num - $numPréc + 1, array($bout));
			$num = $numPréc;
		}
		else if(($numPréc = $this->_précédentNonVide($bouts, $num, static::PREC_MOT_SIMPLE)) === false)
		{
			// En début d'expression, une parenthèse ne devrait servir qu'à constituer un groupement: "(2 + 3) * 2".
			$bouts[$num] = $this->_simplifierParenthèseGroupement($bout, $positions, $num);
		}
		else if(count($bout->f) == 1)
			$this->_splice($bouts, $positions, $num, 1, array($bout->f));
		else
			throw new ErreurExpr("Erreur interne: parenthèse ouvrante qui n'est ni fonction, ni regroupement d'expressions", $positions, $num);
	}
	
	const PREC_MOT_SIMPLE = 0x01;
	const PREC_BI         = 0x08;
	const PREC_BIMULTI    = 0x02;
	const PREC_SAUF_OP    = 0x04;
	
	protected function _précédentNonVide($bouts, $num, $mode)
	{
		while(--$num >= 0)
		{
			$bout = $bouts[$num];
			
			// Chaîne vide, ne compte pas.
			if(is_string($bout) && !trim($bout))
				continue;
			
			// Un des critères suffit.
			if($mode & static::PREC_BI)
				if($this->_estOp($bouts[$num]) && !$this->_estBimulti($bouts[$num]))
					return $num;
			if($mode & static::PREC_SAUF_OP) // Le bimulti (mot-clé "in" par exemple) est considéré comme un opérateur.
				if((is_string($bouts[$num]) && !$this->_estBimulti($bouts[$num]) && !in_array($bouts[$num], self::$Ops)) || (is_object($bouts[$num]) && $bouts[$num] instanceof NœudPrepro && $bouts[$num]->t != 'op'))
					return $num;
			if($mode & static::PREC_MOT_SIMPLE)
				if(is_string($bout) || (is_object($bout) && $bout instanceof NœudPrepro && $bout->t == '"'))
					return $num;
			if($mode & static::PREC_BIMULTI)
				if($bout == ',,' || $this->_estBimulti($bout))
					return $num;
			
			// Sinon c'est une chaîne non vide, mais qui ne répond à aucun des critères.
			return false;
		}
		return false;
	}
	
	protected function _estOp($bout)
	{
		if(is_string($bout) && in_array($bout, self::$Ops))
			return $bout;
		if(is_object($bout) && $bout instanceof NœudPrepro)
			if($bouts[$num]->t == 'op')
				return $bout->op;
			else if(in_array($bout->t, self::$Ops))
				return $bout->t;
	}
	
	protected function _estBimulti($bout)
	{
		if(($bout = $this->_estOp($bout)))
		foreach(static::$Prios as $symbolesNiveau)
			foreach($symbolesNiveau as $symbole => $cat)
				if($symbole === $bout)
					return $cat == 'bimulti';
	}
	
	public function calculer($expr, $contexte, $multi = false, $exécMultiRés = null)
	{
		$racine = $this->compiler($expr);
	
		$r = $multi && is_array($racine) ? $racine : array($racine);
		$rés = array();
		foreach($r as $num => $racine)
		{
		if(!($racine instanceof NœudPrepro))
			throw new Exception('Expression ininterprétable: '.$expr);
		
			$racine = $racine->exécuter($contexte, $exécMultiRés);
			if(!$multi)
				return $racine;
			
			/* Les expressions renvoyant un tableau sont aplaties, au même niveau que les autres.
			 * Ex.:
			 *   `select 'pomme' union select 'banane'` "chou-fleur"
			 * donnera:
			 *   [ pomme, banane, chou-fleur ]
			 * et non:
			 *   [ [ pomme, banane ], chou-fleur ]
			 */
			if(is_array($racine))
				$rés = array_merge($rés, $racine);
			else
				$rés[] = $racine;
		}
		
		return $rés;
	}
	
	public function aff($truc, $délimiteurs = '[]')
	{
		if(is_object($truc) && $truc instanceof NœudPrepro)
			switch($truc->t)
			{
				case '"':
				case 'mot':
					return $this->affChaîne($truc->f);
				case 'f':
					$r = $this->affChaîne($truc->f[0]);
					$r .= isset($truc->f[1]) ? $this->aff($truc->f[1], '()') : '()';
					return $r;
				case 'op':
					return implode(' '.$t->op.' ', $t->f);
					return $r;
				default:
					$r = $this->affChaîne($truc->t);
					if(is_array($truc->f))
						$r .= $this->aff($truc->f, '()');
					else
						$r .= '{'.serialize($truc->f).'}';
					return $r;
			}
		else if(is_array($truc))
			return substr($délimiteurs, 0, 1).implode(',', array_map(array($this, 'aff'), $truc)).substr($délimiteurs, 1, 1);
		return serialize($truc);
	}
	
	public function affChaîne($chaîne)
	{
		if(strlen($chaîne) > strlen(preg_replace("/[;,()\n]/", '', $chaîne)))
			return '"'.$chaîne.'"';
		return $chaîne;
	}
}

class ErreurExpr extends Exception
{
	public function __construct($message, $posOuCorrPos = null, $num = null)
	{
		parent::__construct($message);
		if(isset($posOuCorrPos))
			if(is_object($posOuCorrPos) && $posOuCorrPos instanceof NœudPrepro && isset($posOuCorrPos->pos))
				$this->pos = $posOuCorrPos->pos;
			else if(is_array($posOuCorrPos) && isset($num) && isset($posOuCorrPos[$num]))
				$this->pos = $posOuCorrPos[$num];
			else if(is_int($posOuCorrPos))
				$this->pos = $posOuCorrPos;
		else
			$this->pos = $num;
	}
	
	public function setMessage($m)
	{
		$this->message = $m;
	}
}

class NœudPrepro
{
	public function __construct($type, $fils = null)
	{
		$this->t = $type;
		$this->f = $fils;
	}
	
	public function exécuter($contexte, $exécMultiRés = null)
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
				else if(!$contexte->_defined($this->f))
				{
					if(isset($contexte->motsChaînes) && $contexte->motsChaînes)
						return $this->f;
					else
					throw new Exception("Variable de préproc '".$this->f."' indéfinie");
				}
				return $contexte->_defs['stat'][$this->f];
			case '"':
				if(is_string($this->f))
					return $this->f;
				$r = '';
				foreach($this->f as $f)
					if(is_string($f))
						$r .= $f;
					else
						$r .= $this->_contenu($f, $contexte);
				return $r;
			case '`':
				$rés = $contexte->exécuter($this->f, true, true);
				if(is_object($rés) && $rés instanceof PDOStatement)
				{
					if(count($ls = $rés->fetchAll(PDO::FETCH_ASSOC)) != 1 && !isset($exécMultiRés))
						throw new ErreurExpr('`'.$this->f.'` renvoie '.count($ls).' résultats');
					$r = array();
					foreach($ls as & $l)
					{
					if(count($l) != 1)
						throw new ErreurExpr('`'.$this->f.'` renvoie '.count($l).' colonnes'); // À FAIRE?: renvoyer un résultat tableau?
						$l = array_shift($l);
					}
					return
						isset($exécMultiRés)
						?
							(
								$exécMultiRés === true
								? $ls
								: implode($exécMultiRés, $ls)
							)
						: $ls[0]
					;
				}
				else
					throw new ErreurExpr("Résultat inattendu à l'exécution de `{$this->f}`");
				return $rés;
				return 1;
			case '==':
			case '=':
			case '<':
			case '<=':
			case '>=':
			case '>':
			case '+':
			case '-':
			case '*': // Mais pas le /, qui sert pour le moment de début de regex. Il faudrait savoir distinguer.
				$fils = $this->_contenus($this->f, $contexte, 2);
				switch($this->t)
				{
					case '==':
					case '=':
				return $fils[0] == $fils[1];
					case '<': return $fils[0] < $fils[1];
					case '<=': return $fils[0] <= $fils[1];
					case '>=': return $fils[0] >= $fils[1];
					case '>': return $fils[0] > $fils[1];
					case '+': return $fils[0] + $fils[1];
					case '-': return $fils[0] - $fils[1];
					case '*': return $fils[0] * $fils[1];
				}
			case '~':
				$fils = $this->_contenus($this->f, $contexte, 2);
				$regex = $fils[1];
				if(is_string($regex))
				{
					$e = new SqleurPreproExpr();
					$regex = $e->_regex($regex);
				}
				else if(is_object($regex) && $regex->t == '/')
					$regex = $regex->f;
				else
					throw new ErreurExpr("Bidule inattendu comme regex:\n".print_r($regex, true), $regex);
				return preg_match($regex, $fils[0]);
			case 'in':
				$gauche = $this->_contenu($this->f[0], $contexte);
				/* À FAIRE: on devrait pouvoir descendre un exécMultiRés ici, pour faire du #if "truc" in `select` */
				$droite = $this->_contenus($this->f[1], $contexte);
				return in_array($gauche, $droite);
			case 'or':
			case 'and':
				$gauche = $this->_contenu($this->f[0], $contexte);
				$droite = $this->_contenu($this->f[1], $contexte);
				switch($this->t)
				{
					case 'or': return $gauche || $droite;
					case 'and': return $gauche && $droite;
				}
			case 'defined':
			case 'f':
				return $this->_exécuterF($contexte);
			case '/':
				return $this;
			default:
				throw new Exception('Je ne sais pas gérer les '.$this->t);
		}
	}
	
	protected function _exécuterF($contexte)
	{
		switch($this->t)
		{
			case 'f':
				$nomFonction = $this->f[0];
				$params = $this->f[1];
				break;
			default: // Toutes les fonctions-opérateurs internes (les "devant") apparaissent de cette façon.
				$nomFonction = $this->t;
				$params = $this->f;
				break;
		}
		if(!isset($contexte->_fonctions[$nomFonction]) || !is_callable($contexte->_fonctions[$nomFonction]))
			throw new ErreurExpr(print_r($nomFonction, true).": fonction inconnue", $this);
		// Cas particulier des fonctions devant *ne pas* remplacer les variables préproc par leur contenu.
		if($nomFonction == 'defined')
		{
			foreach($params as $param)
				if(is_object($param) && $param instanceof NœudPrepro && $param->t == 'mot')
					$param->t = '"';
		}
		$params = $this->_contenus($params, $contexte, false, isset($contexte->_fonctionsInternes[$nomFonction]));
		return call_user_func_array($contexte->_fonctions[$nomFonction], $params);
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
	
	public function infosPosition($bouts, $positions, $numDébut, $numFin, $exprComplète)
	{
		// Position.
		if(!isset($positions[$numDébut]))
			return;
		$this->pos = $positions[$numDébut];
		
		// Représentation textuelle.
		$boutFin = $bouts[$numFin];
		if(is_object($boutFin) && $boutFin instanceof NœudPrepro && isset($boutFin->expr))
			$boutFin = $boutFin->expr;
		else if(!is_string($boutFin))
			return;
		$this->expr = substr($exprComplète, $this->pos, $positions[$numFin] + strlen($boutFin) - $this->pos);
	}
}

?>
