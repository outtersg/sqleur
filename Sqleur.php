<?php
/*
 * Copyright (c) 2013 Guillaume Outters
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

class Sqleur
{
	/**
	 * Constructeur.
	 * 
	 * @param fonction $sortie Méthode prenant en paramètre une requête. Sera appelée pour chaque requête, au fur et à mesure qu'elles seront lues.
	 */
	public function __construct($sortie = null)
	{
		$this->_defs = array();
		if(($this->_retourDirect = !isset($sortie)))
		{
			$this->_sortie = array($this, '_accumule');
			$this->_retour = array();
		}
		else
			$this->_sortie = $sortie;
	}
	
	public function avecDefinitions($definitions)
	{
		$this->_defs = $definitions;
	}
	
	protected function _accumule($requete)
	{
		$this->_retour[] = $requete;
	}
	
	protected function _init()
	{
		$this->_conditions = array(); // Pile des conditions de préprocesseur.
		unset($this->_chaineDerniereDecoupe);
		unset($this->_requeteEnCours);
		unset($this->_resteEnCours);
	}
	
	public function decoupeFichier($fichier)
	{
		$ancienFichier = isset($this->_fichier) ? $this->_fichier : null;
		$this->_fichier = $fichier;
		$f = fopen($fichier, 'r');
		$r = $this->decoupeFlux($f);
		fclose($f);
		$this->_fichier = $ancienFichier;
		return $r;
	}
	
	public function decoupeFlux($f)
	{
		$this->_init();
		while(strlen($bloc = fread($f, 0x20000)))
			$this->_decoupeBloc($bloc, false);
		return $this->_decoupeBloc('', true);
	}
	
	public function decoupe($chaineRequetes)
	{
		$this->_init();
		return $this->_decoupeBloc($chaineRequetes);
	}
	
	protected function _decoupeBloc($chaine, $laFinEstVraimentLaFin = true)
	{
		if(isset($this->_resteEnCours))
			$chaine = $this->_resteEnCours.$chaine;
		preg_match_all("#\#|;|--|\n|/\*|\*/|'|\\\\'|\\$[a-zA-Z0-9_]*\\$#", $chaine, $decoupes, PREG_OFFSET_CAPTURE);
		
		$taille = strlen($chaine);
		$decoupes = $decoupes[0];
		$n = count($decoupes);
		
		// On peut déjà supprimer tout ce qui ne nous permettra pas de constituer une requête complète: pas la peine d'interpréter AAA si on attend d'avoir AAABB pour tout relire en partant d'AAA.
		if(!$laFinEstVraimentLaFin)
		{
			while(--$n >= 0 && $decoupes[$n][0] != ';') {}
			++$n;
		}
		
		$dernierArret = 0;
		if(!isset($this->_chaineDerniereDecoupe))
		{
			$chaineDerniereDecoupe = $this->_chaineDerniereDecoupe = "\n"; // Le début de fichier, c'est équivalent à une fin de ligne avant le début de fichier.
			$dernierRetour = 0;
		}
		else
		{
			$chaineDerniereDecoupe = $this->_chaineDerniereDecoupe;
			$dernierRetour = $chaineDerniereDecoupe == "\n" ? 0 : -1;
		}
		$requete = isset($this->_requeteEnCours) ? $this->_requeteEnCours : '';
		
		for($i = 0; $i < $n; ++$i)
		{
			switch($chaineNouvelleDecoupe = $decoupes[$i][0]{0})
			{
				case ';':
					$requete .= substr($chaine, $dernierArret, $decoupes[$i][1] - $dernierArret);
					$this->_sors($requete);
						$requete = '';
					$dernierArret = $decoupes[$i][1] + 1;
					break;
				case "\n":
					$dernierRetour = $decoupes[$i][1] + 1;
					break;
				case '#':
					if($chaineDerniereDecoupe == "\n" && $dernierRetour == $decoupes[$i][1]) // Seulement en début de ligne.
					{
						$requete .= substr($chaine, $dernierArret, $decoupes[$i][1] - $dernierArret);
						$j = $i;
						while(++$i < $n && $decoupes[$i][0] != "\n") {}
						if($i < $n)
						{
							$dernierArret = $decoupes[$i][1];
							$requete = $this->_preprocesse(rtrim(substr($chaine, $decoupes[$j][1], $decoupes[$i][1] - $decoupes[$j][1])), $requete);
							--$i; // Le \n devra être traité de façon standard au prochain tour de boucle (calcul du $dernierRetour).
						}
					}
					break;
				case '-':
					$requete .= substr($chaine, $dernierArret, $decoupes[$i][1] - $dernierArret);
					while(++$i < $n && $decoupes[$i][0] != "\n") {}
					if($i < $n)
					{
						$dernierArret = $decoupes[$i][1];
						--$i; // Le \n devra être traité de façon standard au prochain tour de boucle (calcul du $dernierRetour).
					}
					else if($laFinEstVraimentLaFin) // Si on arrive en bout de truc, l'EOF clot notre commentaire.
						$dernierArret = $taille;
					break;
				case '/':
					$requete .= substr($chaine, $dernierArret, $decoupes[$i][1] - $dernierArret);
					while(++$i < $n && $decoupes[$i][0] != '*/') {}
					if($i < $n)
						$dernierArret = $decoupes[$i][1] + 2;
					else if($laFinEstVraimentLaFin) // Si on arrive en bout de truc, l'EOF clot notre commentaire.
						$dernierArret = $taille;
					break;
				case "'":
				case '$':
					$j = $i;
					$fin = $decoupes[$j][0];
					while(++$i < $n && $decoupes[$i][0] != $fin) {}
					if($i < $n)
					{
						$nouvelArret = $decoupes[$i][1] + strlen($decoupes[$i][0]);
						$requete .= substr($chaine, $dernierArret, $nouvelArret - $dernierArret);
						$dernierArret = $nouvelArret;
					}
					/* À FAIRE: pour décharger la mémoire, il nous faudrait pouvoir ici stocker le bout lu dans la requête (et avancer $dernierArret). Le problème est qu'on n'a rien qui passerait le relai à la prochaine itération pour lui dire "on était au beau milieu d'une chaîne" (il faudrait insérer quelque chose en tête de $decoupes). Bien entendu, on ne pourrait pas tout mettre en bloc en cas de délimiteur multioctet. Ex.: chaîne "$DELIM$ coucou $DELIM$"; si la lecture par bloc nous fournit "$DELIM$ couc", très bien, on peut ajouter à $requete la totalité; par contre si elle nous a fourni dans $chaine "$DELIM$ coucou $DEL", on ne doit stocker dans $requete que "$DELIM$ coucou " car le "$DEL" doit rester dans $chaine pour si, au prochain tour de boucle, on recevait "IM$". Cet À FAIRE s'applique aussi aux commentaires. */
					break;
			}
			$chaineDerniereDecoupe = $chaineNouvelleDecoupe;
		}
		if($laFinEstVraimentLaFin)
		{
			$requete .= substr($chaine, $dernierArret, $taille - $dernierArret);
			$this->_sors($requete);
		}
		else
		{
			$this->_requeteEnCours = $requete;
			$this->_resteEnCours = substr($chaine, $dernierArret);
			$this->_chaineDerniereDecoupe = $chaineDerniereDecoupe;
		}
		
		if($laFinEstVraimentLaFin && $this->_retourDirect)
		{
			$retour = $this->_retour;
			$this->_retour = array();
			return $retour;
		}
	}
	
	protected function _sors($requete)
	{
		if(strlen($requete = trim($requete)))
		{
			if(isset($this->_conv))
				$requete = call_user_func($this->_conv, $requete);
			call_user_func($this->_sortie, strtr($requete, $this->_defs));
		}
	}
	
	public function sortirContenuIfFalse($contenu)
	{
	}
	
	protected function _preprocesse($directive, $requeteEnCours)
	{
		$posEspace = strpos($directive, ' ');
		$motCle = $posEspace === false ? $directive : substr($directive, 0, $posEspace);
		switch($motCle)
		{
			case '#else':
			case '#elif':
			case '#if':
				if($motCle == '#else')
					$vrai = true;
				else
					$vrai = $this->_calculerPrepro($posEspace === false ? '' : substr($directive, $posEspace));
				$condition = $motCle == '#if' ? array(false, $this->_sortie, $requeteEnCours, false) : array_pop($this->_conditions); // 0: déjà fait; 1: sauvegarde de la vraie sortie; 2: requête en cours; 3: en cours.
				if(!$condition[0] && $vrai) // Si pas déjà fait, et que le if est avéré.
				{
					$this->_sortie = $condition[1];
					$requeteEnCours = $condition[2];
					$condition[3] = true; // En cours.
					$condition[0] = true; // Déjà fait.
				}
				else
				{
					$this->_sortie = array($this, 'sortirContenuIfFalse');
					if($condition[3]) // Si on clôt l'en-cours.
						$condition[2] = $requeteEnCours; // On mémorise 
					$condition[3] = false;
				}
				$this->_conditions[] = $condition;
				break;
			case '#endif':
				$condition = array_pop($this->_conditions);
				if(!$condition[3]) // Si le dernier bloc traité (#if ou #else) était à ignorer,
				$requeteEnCours = $condition[2]; // On restaure.
				$this->_sortie = $condition[1];
				break;
			case '#define':
				// À FAIRE: gérer le multi-ligne avec des \.
				$déf = preg_split('/[ 	]+/', $directive, 3);
				$this->_defs[$déf[1]] = $déf[2];
				break;
			case '#encoding':
				$encodage = trim(substr($directive, $posEspace));
				if(in_array(preg_replace('/[^a-z0-9]/', '', strtolower($encodage)), array('', 'utf8')))
					unset($this->_conv);
				else
					$this->_conv = function($ligne) use($encodage) { return iconv($encodage, 'utf-8', $ligne); };
				break;
		}
		
		return $requeteEnCours;
	}
	
	/*- Expressions du préprocesseur -----------------------------------------*/
	
	protected function _decouperPrepro($expr)
	{
		$bouts = array();
		
		preg_match_all('# +|,|==|"#', $expr, $découpe, PREG_OFFSET_CAPTURE);
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
	
	protected function _compilerPrepro($expr)
	{
		$bouts = $this->_decouperPrepro($expr);
		$racine = $this->_arborerPrepro($bouts);
		while(is_array($racine) && count($racine) == 1)
			$racine = array_shift($racine);
		
		return $racine;
	}
	
	protected function _arborerPrepro($bouts)
	{
		$recherchés = array
		(
			array
			(
				',' => 'bi',
				'not' => 'devant',
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
								$fil = $this->_arborerPrepro($fil);
								if(is_array($fil))
								{
									if(count($fil) != 1)
										throw new Exception('L\'opérateur binaire '.$bout.' attend deux membres de part et d\'autre');
									$fil = array_shift($fil);
								}
								$racine->f[] = $fil;
							}
							if($recherchésPlan[$bout] == 'bimulti')
								$racine->f[1] = $this->_listerVirgulesPrepro($racine->f[1]);
							return $racine;
						case 'devant':
							array_splice($bouts, $num, 1);
							$racine = new NœudPrepro($bout, $this->_arborerPrepro($bouts));
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
							return $this->_arborerPrepro($bouts);
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
	
	protected function _listerVirgulesPrepro($expr)
	{
		if(!($expr instanceof NœudPrepro))
			throw new Exception('Truc improbable après une virgule');
		
		if($expr->t != ',')
			return array($expr);
		
		$r = array_merge(array($expr->f[0]), $this->_listerVirgulesPrepro($expr->f[1]));
		
		return $r;
	}
	
	protected function _calculerPrepro($expr)
	{
		$racine = $this->_compilerPrepro($expr);
	
		if(!($racine instanceof NœudPrepro))
			throw new Exception('Expression ininterprétable');
		
		return $racine->exécuter($this);
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
			case 'not':
				return !$this->_contenu($this->f, $contexte);
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
