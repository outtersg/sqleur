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

include_once 'SqleurPreproExpr.php';

class Sqleur
{
	/**
	 * Constructeur.
	 * 
	 * @param fonction $sortie Méthode prenant en paramètre une requête. Sera appelée pour chaque requête, au fur et à mesure qu'elles seront lues.
	 */
	public function __construct($sortie = null, $préprocesseurs = array())
	{
		$this->_defs = array();
		if(($this->_retourDirect = !isset($sortie)))
		{
			$this->_sortie = array($this, '_accumule');
			$this->_retour = array();
		}
		else
			$this->_sortie = $sortie;
		
		foreach($préprocesseurs as $préprocesseur)
			$préprocesseur->_sqleur = $this;
		$this->_préprocesseurs = $préprocesseurs;
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
		$this->_ligne = 1;
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
		preg_match_all("#\#|\\\\|;|--|\n|/\*|\*/|'|\\\\'|\\$[a-zA-Z0-9_]*\\$#", $chaine, $decoupes, PREG_OFFSET_CAPTURE);
		
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
					++$this->_ligne;
					break;
				case '#':
					if($chaineDerniereDecoupe == "\n" && $dernierRetour == $decoupes[$i][1]) // Seulement en début de ligne.
					{
						$requete .= substr($chaine, $dernierArret, $decoupes[$i][1] - $dernierArret);
						$j = $i;
						while(++$i < $n && $decoupes[$i][0] != "\n")
							if($decoupes[$i][0] == '\\' && isset($decoupes[$i + 1]) && $decoupes[$i + 1][0] == "\n" && $decoupes[$i + 1][1] == $decoupes[$i][1] + 1)
							{
								++$i;
								++$this->_ligne;
							}
						if($i < $n)
						{
							$dernierArret = $decoupes[$i][1];
							$blocPréprocesse = rtrim(substr($chaine, $decoupes[$j][1], $decoupes[$i][1] - $decoupes[$j][1]));
							$blocPréprocesse = preg_replace('#\\\\$#m', '', $blocPréprocesse);
							$requete = $this->_preprocesse($blocPréprocesse, $requete);
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
					while(++$i < $n && $decoupes[$i][0] != '*/')
						if($decoupes[$i][0] == "\n")
							++$this->_ligne;
					if($i < $n)
						$dernierArret = $decoupes[$i][1] + 2;
					else if($laFinEstVraimentLaFin) // Si on arrive en bout de truc, l'EOF clot notre commentaire.
						$dernierArret = $taille;
					break;
				case "'":
				case '$':
					$j = $i;
					$fin = $decoupes[$j][0];
					while(++$i < $n && $decoupes[$i][0] != $fin)
						if($decoupes[$i][0] == "\n")
							++$this->_ligne;
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
	
	public function dansUnSiÀLaTrappe()
	{
		return is_array($this->_sortie) && is_string($this->_sortie[1]) && $this->_sortie[1] == 'sortirContenuIfFalse';
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
				$condition = $motCle == '#if' ? array(false, $this->_sortie, $requeteEnCours, false, $this->_defs) : array_pop($this->_conditions); // 0: déjà fait; 1: sauvegarde de la vraie sortie; 2: requête en cours; 3: en cours; 4: définitions.
				if(!$condition[0] && $vrai) // Si pas déjà fait, et que le if est avéré.
				{
					$this->_sortie = $condition[1];
					$requeteEnCours = $condition[2];
					$this->_defs = $condition[4];
					$condition[3] = true; // En cours.
					$condition[0] = true; // Déjà fait.
				}
				else
				{
					$this->_sortie = array($this, 'sortirContenuIfFalse');
					if($condition[3]) // Si on clôt l'en-cours.
					{
						$condition[2] = $requeteEnCours; // On mémorise 
						$condition[4] = $this->_defs;
					}
					$condition[3] = false;
				}
				$this->_conditions[] = $condition;
				break;
			case '#endif':
				$condition = array_pop($this->_conditions);
				if(!$condition[3]) // Si le dernier bloc traité (#if ou #else) était à ignorer,
				{
				$requeteEnCours = $condition[2]; // On restaure.
					$this->_defs = $condition[4];
				}
				$this->_sortie = $condition[1];
				break;
		}
		if(!$this->dansUnSiÀLaTrappe())
		{
			foreach($this->_préprocesseurs as $préproc)
				if(($r = $préproc->préprocesse($motCle, $directive, $requeteEnCours)) !== false)
					return $r;
			switch($motCle)
			{
			case '#define':
				$déf = preg_split('/[ 	]+/', $directive, 3);
				$contenuDéf = isset($déf[2]) ? $déf[2] : '';
				$contenuDéf = strtr($contenuDéf, $this->_defs);
				$this->_defs[$déf[1]] = $contenuDéf;
				break;
			case '#encoding':
				$encodage = trim(substr($directive, $posEspace));
				if(in_array(preg_replace('/[^a-z0-9]/', '', strtolower($encodage)), array('', 'utf8')))
					unset($this->_conv);
				else
					$this->_conv = function($ligne) use($encodage) { return iconv($encodage, 'utf-8', $ligne); };
				break;
			}
		}
		
		return $requeteEnCours;
	}
	
	/*- États ----------------------------------------------------------------*/
	
	public function mémoriserÉtat()
	{
		$this->_états[] = array
		(
			$this->_defs,
			isset($this->_conv) ? $this->_conv : null,
			$this->_ligne,
		);
	}
	
	public function restaurerÉtat($avecDéfs = false)
	{
		list
		(
			$défs,
			$this->_conv,
			$this->_ligne,
		) = array_pop($this->_états);
		if ($avecDéfs)
			$this->_defs = $défs;
	}
	
	/*- Expressions du préprocesseur -----------------------------------------*/
	
	protected function _calculerPrepro($expr)
	{
		$e = new SqleurPreproExpr();
		return $e->calculer($expr, $this);
	}
}

?>
