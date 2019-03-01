<?php
/*
 * Copyright (c) 2019 Guillaume Outters
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

/**
 * Préprocesseur introduisant du pif.
 * Exemple d'utilisation: test de robustesse à la saisie en désordre de données (commutativité).
 * Utilisation:
   -- Par défaut tout ce qui est hors des sections au pif sortira séquentiellement.
   -- Introduit une section qui sortira dans un ordre aléatoire.
   #pif [
   insert etc.; -- Donc cette instruction…
   insert etc.; -- … pourra être jouée après *ou avant* la précédente.
   -- Nommage de l'instruction qui suivra:
   #pif A
   insert etc.; -- Cette instruction s'appelle "A" aux yeux de #pif.
   #pif après A
   insert etc.; -- Cette instruction ne sera *jamais* jouée avant A (par contre rien ne dit qu'elle interviendra juste après A).
   #pif après A
   insert etc.; -- Celle-ci non plus (par contre entre ces deux dernières, l'ordre reste aléatoire).
   -- Nommage et ordre simultanés:
   #pif B après A
   insert etc.; -- Celle-ci sera jouée forcément après A; et s'appelle B, ce qui pourra permettre de chaîner un C plus tard.
   -- Pseudo-nommage sur regex (valable pour toutes les requêtes qui *suivront*)
   -- et ordre sur regex (piochant parmi les requêtes qui *précèdent*):
   #pif /insert into vals \(id, ref\) values \([^,]*, ([0-9]*)\)/ après /insert into ref values \($1,/
   #pif ]
 */
// À FAIRE: introduire les accolades pour déclarer des blocs séquentiels (toujours joués d'affilée).
class SqleurPreproPif
{
	protected $_préfixes = array('#pif', '#rand');
	protected $_motsAprès = array('après', 'suit', 'after');
	
	const TYPE = 0;
	const VAL = 1;
	const DÉPS = 2;
	
	public function __construct()
	{
		$this->_pile = array();
		$this->_idUnique = 0;
	}
	
	protected function _err($source, $message)
	{
		throw new Exception(get_class($this).': '.$message.(isset($source) ? " (dans '".$source."')" : ''));
	}
	
	protected function _découpe($directiveComplète)
	{
		// On vire le mot-clé lui-même.
		$directiveComplète = preg_replace("/^[^ ]*[ \t]+/", '', $directiveComplète);
		
		if(class_exists('SqleurPreproExpr'))
		{
			try
			{
				$e = new SqleurPreproExpr();
				$mots = $e->compiler($directiveComplète);
				if(is_object($mots))
					$mots = array($mots);
				foreach($mots as & $ptrMot)
					if(is_object($ptrMot) && $ptrMot->t == 'mot')
						$ptrMot = $ptrMot->f;
			}
			catch(Exception $ex)
			{
				$messageEnrichi = $ex->getMessage().' (sur compilation de: '.$directiveComplète.')';
				if(method_exists($ex, 'setMessage'))
				{
					$ex->setMessage($messageEnrichi);
					throw $ex;
				}
				else
					throw new Exception($ex->getFile().':'.$ex->getLine().': '.$messageEnrichi);
			}
		}
		else
			$mots = preg_split("/[ \t]+/", $directiveComplète);
		
		return $mots;
	}
	
	public function préprocesse($motClé, $directiveComplète, $requêteEnCours)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;

		$mots = $this->_découpe($directiveComplète);
		$boutAttendu = 'nom';
		for($i = -1; ++$i < count($mots);)
		{
			$mot = $mots[$i];
			switch($mots[$i])
			{
				case '[':
					$this->_entre();
					break;
				case ']':
					$this->_sors();
					break;
				default:
					// Les seuls nœuds préprocesseur autorisés sont les expressions régulières.
					if(is_object($mot))
					{
						if(!($mot instanceof NœudPrepro && $mot->t == '/'))
							$this->_err($directiveComplète, 'nœud inconnu: '.print_r($mot, true));
					}
					if(in_array($mots[$i], $this->_motsAprès))
					{
						if(!isset($mots[$i + 1]))
							$this->_err($directiveComplète, $mots[$i].', '.$mots[$i].' quoi?');
						$boutAttendu = 'déps'.$boutAttendu;
					}
					else
					{
						switch($boutAttendu)
						{
							// Nom et regex interviennent au même endroit.
							case 'regex':
							case 'nom':
								$boutAttendu = is_object($mot) ? 'regex' : 'nom';
								if(isset($this->_prochains[$boutAttendu]))
									$this->_err($directiveComplète, "ah non, alors! Un seul ".$boutAttendu." à la fois: je ne peux travailler à la fois sur '".$this->_prochains[$boutAttendu]."' et '".$mot."'");
								$this->_prochains[$boutAttendu] = $mot;
								break;
							case 'dépsregex':
							case 'dépsnom':
								$this->_prochains[$boutAttendu][is_object($mot) ? $mot->f : $mot] = is_object($mot) ? $mot : true;
								break;
						}
					}
			}
		}
		
		// La directive s'appliquera-t-elle à la prochaine requête, ou bien est-elle de type auto-contenue (se terminant maintenant)?
		
		$this->_termineDirective();
		
		return $requêteEnCours;;
	}
	
	protected function _entre()
	{
		if(count($this->_pile) <= 1)
		{
			$this->_sortieOriginelle = $this->_sqleur->_sortie;
			$this->_sqleur->_sortie = array($this, 'chope');
			$this->_pile = array(array());
			$this->_prochainsLibres();
		}
		// On entrepose déjà ce qu'on sait du bloc: ainsi s'il est nommé il aura au moins sauvé de l'écrasement futur son nom.
		$this->_entrepose('p', array());
	}
	
	public function chope($req)
	{
		$this->_entrepose('r', $req);
	}
	
	protected function _sors()
	{
		array_pop($this->_pile);
		if(count($this->_pile) <= 1)
		{
			$this->_sqleur->_sortie = $this->_sortieOriginelle;
			$this->_déroule(array_shift($this->_pile[0]));
		}
	}
	
	protected function _termineDirective()
	{
		if(isset($this->_prochains['regex']))
		{
			if(!count($this->_prochains['dépsregex']))
				$this->_err($directiveComplète, "une règle regex est faite pour se voir déclarer immédiatement des dépendances");
			$this->_dépsRegex[$this->_prochains['regex']->f] = $this->_prochains['dépsregex'];
			$this->_prochains['dépsregex'] = array();
			unset($this->_prochains['regex']);
		}
	}
	
	protected function _entrepose($type, $truc)
	{
		$ptrListeCourante = & $this->_pile[count($this->_pile) - 1];
		$nom = isset($this->_prochains['nom']) ? $this->_prochains['nom'] : '.'.(++$this->_idUnique).'_'; // Avec une parure disymétrique afin qu'aucun utilisateur n'ait l'idée de prendre la même nomenclature.
		// Ce dont nous dépendons:
		// 1. Explicitement.
		$déps = $this->_prochains['dépsnom'];
		// 2. Parce qu'une regex dit que tout ce qui a notre tête a telles dépendances.
		if($type == 'r' && isset($this->_dépsRegex))
		{
			foreach($this->_dépsRegex as $regex => $dépsCetteRegex)
				if(preg_match($regex, $truc))
					$déps += $dépsCetteRegex;
		}
		// Parmi nos dépendances, résolution de celles dynamiques (regex au lieu de nom).
		/* NOTE: déroulement de la regex à l'entreposage
		 * On déroule dès maintenant la regex, qui ne prendra donc comme prérequis que les requêtes déjà rencontrées.
		 * Cela interdit de dépendre d'une requête non encore rencontrée, ce qui est une bonne chose pour éviter les boucles de dépendances.
		 * Exemple:
		 *   #pif /update t .* where id = ([0-9]*)/ après /update t .* where id = $1/
		 * veut dire que tout update sur une entrée doit être joué après les update sur la même entrée *qui ont été déclarés avant*.
		 * Si la regex est interprétée à l'exécution, l'update ne pourra être joué qu'après lui-même!
		 */
		$dépsBrutes = $déps;
		$déps = array();
		foreach($dépsBrutes as $nomDép => $dép)
			if(is_object($dép))
				$déps += $this->_requêtesPasséesCorrespondantÀ($dép->f);
			else
				$déps[$nomDép] = true;
		
		$ptrListeCourante[$nom] = array(self::TYPE => $type, self::VAL => $truc, self::DÉPS => count($déps) ? $déps : null);
		
		$this->_prochainsLibres();
		
		// Un bloc de type 'p' doit se voir ouvrir son propre sous-bloc indépendant.
		if($type == 'p')
			$this->_pile[] = & $ptrListeCourante[$nom][1];
	}
	
	protected function _prochainsLibres()
	{
		$this->_prochains = array
		(
			'dépsnom' => array(),
			'dépsregex' => array(),
		);
	}
	
	protected function _requêtesPasséesCorrespondantÀ($regex)
	{
		$r = array();
		
		$listeCourante = $this->_pile[count($this->_pile) - 1];
		foreach($listeCourante as $nom => $req)
			if($req[self::TYPE] == 'r' && preg_match($regex, $req[self::VAL]))
				$r[$nom] = true;
		
		return $r;
	}
	
	protected function _déroule($quoi)
	{
		switch($quoi[self::TYPE])
		{
			// Une requête, le plus facile.
			case 'r':
				call_user_func($this->_sqleur->_sortie, $quoi[self::VAL]);
				break;
			// Une séquence.
			case 's':
				foreach($quoi as $val)
					$this->_déroule($val);
				break;
			// Un pif.
			case 'p':
				$this->_déroulePif($quoi[self::VAL]);
				break;
		}
	}
	
	protected function _déroulePif($àFaire)
	{
		$joués = array();
		$jouables = array();
		// Toutes nos dépendances existent-elles?
		foreach($àFaire as $nom => $val)
			if(isset($val[self::DÉPS]) && count($inconnues = array_diff_key($val[self::DÉPS], $àFaire)))
				$this->_err(null, 'requête '.$nom.': dépendance envers '.implode(', ', array_keys($inconnues)).' inexistante'.(count($inconnues) > 1 ? 's' : ''));
		while(count($àFaire) || count($jouables))
		{
			// On met de côté ceux dont toutes les dépendances sont résolues.
			foreach($àFaire as $nom => $val)
				if(!isset($val[self::DÉPS]) || !count(array_diff_key($val[self::DÉPS], $joués)))
				{
					$jouables[$nom] = $val;
					unset($àFaire[$nom]);
				}
			// Boucle infinie d'interdépendances?
			if(!count($jouables) && count($àFaire))
				$this->_err(null, 'interdépendance(s) entre '.implode(', ', array_keys($àFaire)));
			// Jouons (littéralement)!
			$num = rand(0, count($jouables) - 1);
			$clés = array_keys($jouables);
			$clé = $clés[$num];
			$this->_déroule($jouables[$clé]);
			$joués[$clé] = true;
			unset($jouables[$clé]);
		}
	}
}

?>
