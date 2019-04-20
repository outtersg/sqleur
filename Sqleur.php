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
		$this->_fichier = null;
		$this->_ligne = null;
		$this->_dernièreLigne = null;
		$this->_fonctions = array();
		foreach(static::$FonctionsPréproc as $f)
		{
			$this->_fonctions[$f] = array($this, '_'.$f);
			$this->_fonctionsInternes[$f] = true;
		}
		
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
		$this->_dansChaîne = null;
	}
	
	public function decoupeFichier($fichier)
	{
		$this->_init();
		return $this->_découpeFichier($fichier, true);
	}
	
	public function _découpeFichier($fichier, $laFinEstVraimentLaFin = false)
	{
		if(!file_exists($fichier))
			throw $this->exception($fichier.' inexistant');
		
		$this->mémoriserÉtat();
		try
		{
		$this->_fichier = $fichier;
		$f = fopen($fichier, 'r');
			$r = $this->_découpeFlux($f, $laFinEstVraimentLaFin);
		fclose($f);
			$this->restaurerÉtat();
		return $r;
		}
		catch(Exception $e)
		{
			$this->restaurerÉtat();
			throw $e;
		}
	}
	
	public function decoupeFlux($f)
	{
		$this->_init();
		return $this->_découpeFlux($f, true);
	}
	
	public function _découpeFlux($f, $laFinEstVraimentLaFin = false)
	{
		$nConditionsImbriquées = count($this->_conditions);
		$this->_ligne = 1;
		while(strlen($bloc = fread($f, 0x20000)))
			$this->_decoupeBloc($bloc, false);
		$r = $laFinEstVraimentLaFin ? $this->_decoupeBloc('', true) : null;
		if(($nConditionsImbriquées -= count($this->_conditions)))
			throw $this->exception($nConditionsImbriquées > 0 ? $nConditionsImbriquées.' #endif sans #if' : (-$nConditionsImbriquées).' #if sans #endif');
		return $r;
	}
	
	public function decoupe($chaineRequetes)
	{
		$this->_init();
		return $this->_decoupeBloc($chaineRequetes);
	}
	
	const DANS_CHAÎNE_DÉBUT = 0;
	const DANS_CHAÎNE_FIN = 1;
	const DANS_CHAÎNE_CAUSE = 2;
	
	const CHAÎNE_COUPÉE = -1;
	const CHAÎNE_PASSE_LA_MAIN = 1; // Indique que la chaîne donne au prochain élément une chance de se jouer. La chaîne ayant pour critère de délivrance du jeton les mêmes que _decoupeBloc pour entrer dans l'élément, il y a de fortes chances pour qu'il soit consommé immédiatement; le seul cas de non-consommation étant si la découpe qui a sa chance, manque de bol, tombe sur un fragment incomplet (le bloc lu se termine avant que lui ait sa fin de découpe): dans ce cas, le jeton est préservé, et la découpe "hôte" pourra être retentée une fois le tampon regarni.
	const CHAÎNE_JETON_CONSOMMÉ = 2;
	
	protected function _decoupeBloc($chaine, $laFinEstVraimentLaFin = true)
	{
		if(isset($this->_resteEnCours))
			$chaine = $this->_resteEnCours.$chaine;
		preg_match_all("#\#|\\\\|;|--|\n|/\*|\*/|'|\\\\'|\\$[a-zA-Z0-9_]*\\$#", $chaine, $decoupes, PREG_OFFSET_CAPTURE);
		
		$taille = strlen($chaine);
		$decoupes = $decoupes[0];
		$n = count($decoupes);
		
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
			$chaineNouvelleDecoupe = $decoupes[$i][0]{0};
			// Si on est dans une chaîne, même interrompue, on y retourne. Elle est seule à pouvoir décider de s'interrompre (soit pour fin de tampon, soit pour passage de relais temporaire au préprocesseur).
			if($this->_dansChaîne && $this->_dansChaîne[static::DANS_CHAÎNE_CAUSE] != static::CHAÎNE_PASSE_LA_MAIN && !$this->dansUnSiÀLaTrappe())
				$chaineNouvelleDecoupe = $this->_dansChaîne[static::DANS_CHAÎNE_DÉBUT];
			
			switch($chaineNouvelleDecoupe)
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
							if($this->_dansChaîne)
								$this->_dansChaîne[static::DANS_CHAÎNE_CAUSE] = static::CHAÎNE_JETON_CONSOMMÉ;
							$dernierArret = $decoupes[$i][1];
							$blocPréprocesse = substr($chaine, $decoupes[$j][1], $decoupes[$i][1] - $decoupes[$j][1]);
							$this->_dernièreLigne = $this->_ligne - substr_count(ltrim($blocPréprocesse), "\n");
							$blocPréprocesse = preg_replace('#\\\\$#m', '', rtrim($blocPréprocesse));
							$this->_chaineDerniereDecoupe = $chaineDerniereDecoupe;
							$requete = $this->_preprocesse($blocPréprocesse, $requete);
							$chaineDerniereDecoupe = $this->_chaineDerniereDecoupe;
							--$i; // Le \n devra être traité de façon standard au prochain tour de boucle (calcul du $dernierRetour; ne serait-ce que pour que si notre #if est suivi d'un #endif, celui-ci voie le \n qui le précède).
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
					/* À FAIRE: pour décharger la mémoire, voir si on ne peut pas passer par le traitement des chaînes capable de calculer un _resteEnCours minimal. */
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
					if(!$this->dansUnSiÀLaTrappe())
					$this->_mangerChaîne($chaine, $decoupes, $n, /*&*/ $i, /*&*/ $dernierRetour, /*&*/ $chaineNouvelleDecoupe, /*&*/ $dernierArret, /*&*/ $nouvelArret, /*&*/ $requete);
					break;
			}
			$chaineDerniereDecoupe = $chaineNouvelleDecoupe;
		}
		
			$this->_requeteEnCours = $requete;
			$this->_resteEnCours = substr($chaine, $dernierArret);
			$this->_chaineDerniereDecoupe = $chaineDerniereDecoupe;
		if($laFinEstVraimentLaFin)
		{
			$this->_sors($this->_requeteEnCours.$this->_resteEnCours);
			unset($this->_chaineDerniereDecoupe);
			unset($this->_requeteEnCours);
			unset($this->_resteEnCours);
			if($this->_retourDirect)
		{
			$retour = $this->_retour;
			$this->_retour = array();
			return $retour;
			}
		}
	}
	
	protected function _mangerChaîne($chaine, $decoupes, $n, & $i, & $dernierRetour, & $chaineNouvelleDecoupe, & $dernierArret, & $nouvelArret, & $requete)
	{
		$chaîneType = $chaineNouvelleDecoupe;
		if($this->_dansChaîne) // On ne fait que reprendre une chaîne interrompue.
		{
			$fin = $this->_dansChaîne[static::DANS_CHAÎNE_FIN];
			$this->_dansChaîne = null;
			$débutIntérieur = 0; // Le marqueur qui nous fait entrer dans la chaîne étant déjà passé, nous sommes dès le départ à l'intérieur de la chaîne.
			// La boucle while qui suit, appelée en principe lors que le $i est le caractère d'entrée dans la chaîne, voudra passer outre ce caractère.
			// Si l'on est appelés déjà dans la chaîne (donc qu'$i n'est pas le guillemet), on place notre $i sur le guillemet (virtuel) précédant notre départ.
			--$i;
		}
		else // C'est la découpe courante qui nous fait entrer dans la chaîne
		{
			$fin = $decoupes[$i][0];
			$débutIntérieur = strlen($fin);
		}
		while(++$i < $n && $decoupes[$i][0] != $fin)
		{
			if($decoupes[$i][0] == "\n")
			{
				$dernierRetour = $decoupes[$i][1] + 1;
				++$this->_ligne;
			}
			// Les chaînes à dollars sont parsemables d'instructions préproc. Cela permet de définir des fonctions SQL avec des fragments dépendants du préproc.
			else if($decoupes[$i][0] == '#'&& $chaineNouvelleDecoupe == '$' && $dernierRetour == $decoupes[$i][1])
			{
				$chaineNouvelleDecoupe = "\n"; // Notre tunnel a masqué tout ce qu'il s'est passé dans notre mangeage; exposons au moins la découpe de juste avant la sortie.
				--$i; // Le # lui-même ne rentre pas dans la chaîne.
				$this->_dansChaîne = array($chaîneType, $fin, static::CHAÎNE_PASSE_LA_MAIN); // Le prochain élément gagne une chance d'être joué pour lui-même. À lui de consommer (unset) le jeton dès qu'il a pris sa chance.
				break;
			}
		}
		if($i >= $n)
			$this->_dansChaîne = array($chaîneType, $fin, static::CHAÎNE_COUPÉE);
		// Ce qui a été parcouru ci-dessus est mis de côté.
		/* NOTE: interruption prématurée
		 * Dans le cas d'un marqueur de fin multi-caractères, si $i >= $n (autrement dit si l'on a atteint la fin du bloc lu avant d'avoir trouvé notre fin de chaîne), il est possible que la fin du bloc, manque de bol, tombât pile au milieu du marqueur de fin. Si c'est le cas, autrement dit si dans les derniers octets du bloc lu on trouve le premier caractère du marqueur de fin, on laisse ces derniers octets pour que le prochain bloc lu vienne s'y agréger et reconstituer le marqueur de fin complet.
		 * On s'assure aussi qu'il ne chevauche pas le marqueur de début: il serait malvenu que dans la chaîne $marqueur$marqueur$marqueur$ (équivalente en SQL à 'marqueur'), la fin de bloc tombant au milieu (donc |$marqueur$mar|queur$marqueur$|), prenant le $ fermant du premier $marqueur$ initial pour l'ouvrant potentiel du $marqueur$ final, on le garde de côté, ce qui serait équivalent à avoir lu |$marqueur$| puis |($mar)queur$marqueur$|, autrement dit $marqueur$$marqueur$marqueur$.
		 */
		$j = $i < $n ? $i : $i - 1;
		$nouvelArret = $decoupes[$j][1] + strlen($decoupes[$j][0]);
		$fragment = substr($chaine, $dernierArret, $nouvelArret - $dernierArret);
		if
		(
			$i >= $n && strlen($fin) > 1
			&& ($fragmentSaufMarqueurEntrée = substr($fragment, $débutIntérieur))
			&& ($posDébutMarqueurFin = strpos($fragmentSaufMarqueurEntrée, $fin{0}, max(0, strlen($fragmentSaufMarqueurEntrée) - (strlen($fin) - 1)))) !== false // On cherche les (strlen($fin) - 1) caractères, car si on cherchait dans les strlen($fin) derniers (et qu'on le trouvait), cela voudrait dire qu'on aurait le marqueur de fin en entier, qui aurait été détecté à la découpe.
		)
		{
			$nCarsÀRéserver = strlen($fragmentSaufMarqueurEntrée) - $posDébutMarqueurFin;
			$nouvelArret -= $nCarsÀRéserver;
			$fragment = substr($fragment, 0, -$nCarsÀRéserver);
		}
		$requete .= $fragment;
		$dernierArret = $nouvelArret;
	}
	
	protected function _sors($requete)
	{
		$this->_dernièreLigne = $this->_ligne - substr_count(ltrim($requete), "\n");
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
				if(!$condition)
					throw $this->exception('#else sans #if');
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
				if(!$condition)
					throw $this->exception('#endif sans #if');
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
			$this->_requeteEnCours = $requeteEnCours;
			foreach($this->_préprocesseurs as $préproc)
				// N.B.: $requeteEnCours NE DOIT PLUS être passée à préprocesse().
				// Les préprocesseurs désirant modifier la requête en cours de constitution doivent désormais exploiter $this->_requeteEnCours.
				// Ce dernier paramètre désormais inutile pourra être supprimé une fois tous les préprocesseurs existants purgés.
				if($préproc->préprocesse($motCle, $directive, $requeteEnCours) !== false)
				{
					$requeteEnCours = $this->_requeteEnCours;
					return $requeteEnCours;
				}
			$requeteEnCours = $this->_requeteEnCours;
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
			$this->_fichier,
			$this->_ligne,
			$this->_dernièreLigne,
		);
	}
	
	public function restaurerÉtat($avecDéfs = false)
	{
		list
		(
			$défs,
			$this->_conv,
			$this->_fichier,
			$this->_ligne,
			$this->_dernièreLigne,
		) = array_pop($this->_états);
		if ($avecDéfs)
			$this->_defs = $défs;
	}
	
	public function pileDAppels()
	{
		$r = array();
		
		$this->mémoriserÉtat();
		foreach($this->_états as $état)
			if(isset($état[4])) // Si on n'a pas de ligne, c'est qu'on est à l'initialisation, avant même l'entrée dans du SQL. Inutile d'en parler.
			array_unshift($r, array('file' => $état[2], 'line' => $état[4]));
		$this->restaurerÉtat();
		
		return $r;
	}
	
	public function exception($truc)
	{
		if(is_object($truc))
		{
			$classe = get_class($truc);
			$message = $truc->getMessage();
			$code = $truc->getCode();
			$ex = $truc;
		}
		else
		{
			$classe = null;
			$message = $truc;
			$ex = null;
		}
		
		// PHP empêchant de définir sa propre trace sur les exceptions, on la glisse dans le message.
		$messagePile = '';
		$pile = $this->pileDAppels();
		foreach($pile as $endroit)
			$messagePile .= "\n\t".$endroit['file'].':'.$endroit['line'];
		$message .= $messagePile;
		
		switch($classe)
		{
			case 'PHPUnit_Framework_ExpectationFailedException':
				return new $classe($message, $ex->getComparisonFailure(), $ex->getPrevious());
		}
		return new Exception($message, isset($ex) ? $ex->getCode() : 0, $ex);
	}
	
	/*- Expressions du préprocesseur -----------------------------------------*/
	
	protected function _calculerPrepro($expr)
	{
		$e = new SqleurPreproExpr();
		return $e->calculer($expr, $this);
	}
	
	public static $FonctionsPréproc = array
	(
		'defined',
	);
	
	public function _defined($nomVar)
	{
		return array_key_exists($nomVar, $this->_defs);
	}
}

?>
