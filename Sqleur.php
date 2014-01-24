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
		$this->_init();
		$f = fopen($fichier, 'r');
		while(strlen($bloc = fread($f, 0x20000)))
			$this->_decoupeBloc($bloc, false);
		fclose($f);
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
		preg_match_all("#\#|;|--|\n|/\*|\*/|'|\\\\'|\\$[a-zA-Z0-9_]\\$#", $chaine, $decoupes, PREG_OFFSET_CAPTURE);
		
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
							$dernierArret = $decoupes[$i][1] + 1;
							$requete = $this->_preprocesse(substr($chaine, $decoupes[$j][1], $decoupes[$i][1] - $decoupes[$j][1]), $requete);
						}
					}
					break;
				case '-':
					$requete .= substr($chaine, $dernierArret, $decoupes[$i][1] - $dernierArret);
					while(++$i < $n && $decoupes[$i][0] != "\n") {}
					if($i < $n)
						$dernierArret = $decoupes[$i][1] + 1;
					break;
				case '/':
					$requete .= substr($chaine, $dernierArret, $decoupes[$i][1] - $dernierArret);
					while(++$i < $n && $decoupes[$i][0] != '*/') {}
					if($i < $n)
						$dernierArret = $decoupes[$i][1] + 2;
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
					else
					{
						$requete .= substr($chaine, $dernierArret, $decoupes[$j][0] - $dernierArret);
						$dernierArret = $decoupes[$j][0];
					}
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
			call_user_func($this->_sortie, strtr($requete, $this->_defs));
	}
	
	public function sortirContenuIfFalse($contenu)
	{
	}
	
	protected function _preprocesse($directive, $requeteEnCours)
	{
		$bouts = explode(' ', $directive);
		$motCle = $bouts[0];
		switch($motCle)
		{
			case '#else':
			case '#elif':
			case '#if':
				if($motCle == '#else')
					$vrai = true;
				else
				{
					$vrai = false;
					if($bouts[2] == '==')
					{
						$vals = array();
						$vals[] = $bouts[1];
						$vals[] = implode(' ', array_slice($bouts, 3));
						foreach($vals as & $val)
							if(preg_match('/^".*"$/', $val))
								$val = substr($val, 1, -1);
							else if(preg_match('/^[0-9]*$/', $val))
								true;
							else
								$val = isset($this->_defs[$val]) ? $this->_defs[$val] : '';
						unset($val);
						$vrai = $vals[0] == $vals[1];
					}
				}
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
				$requeteEnCours = $condition[2]; // On restaure.
				$this->_sortie = $condition[1];
				break;
		}
		
		return $requeteEnCours;
	}
}

?>
