<?php

class Sqleur
{
	/**
	 * Constructeur.
	 * 
	 * @param fonction $sortie Méthode prenant en paramètre une requête. Sera appelée pour chaque requête, au fur et à mesure qu'elles seront lues.
	 */
	public function __construct($sortie = null)
	{
		if(($this->_retourDirect = !isset($sortie)))
		{
			$this->_sortie = array($this, '_accumule');
			$this->_retour = array();
		}
		else
			$this->_sortie = $sortie;
	}
	
	protected function _accumule($requete)
	{
		$this->_retour[] = $requete;
	}
	
	public function decoupeFichier($fichier)
	{
		$f = fopen($fichier, 'r');
		while(strlen($bloc = fread($f, 8)))
			$this->_decoupeBloc($bloc, false);
		fclose($f);
		return $this->_decoupeBloc('', true);
	}
	
	public function decoupe($chaineRequetes)
	{
		return $this->_decoupeBloc($chaineRequetes);
	}
	
	protected function _decoupeBloc($chaine, $laFinEstVraimentLaFin = true)
	{
		if(isset($this->_resteEnCours))
			$chaine = $this->_resteEnCours.$chaine;
		preg_match_all("#;|--|\n|/\*|\*/|'|\\\\'|\\$[a-zA-Z0-9_]\\$#", $chaine, $decoupes, PREG_OFFSET_CAPTURE);
		
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
		$requete = isset($this->_requeteEnCours) ? $this->_requeteEnCours : '';
		
		for($i = 0; $i < $n; ++$i)
		{
			switch($decoupes[$i][0]{0})
			{
				case ';':
					$requete .= substr($chaine, $dernierArret, $decoupes[$i][1] - $dernierArret);
					if(strlen($requete = trim($requete)))
					{
						call_user_func($this->_sortie, $requete);
						$requete = '';
					}
					$dernierArret = $decoupes[$i][1] + 1;
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
		}
		if($laFinEstVraimentLaFin)
		{
			$requete .= substr($chaine, $dernierArret, $taille - $dernierArret);
			if(strlen($requete = trim($requete)))
				call_user_func($this->_sortie, $requete);
		}
		else
		{
			$this->_requeteEnCours = $requete;
			$this->_resteEnCours = substr($chaine, $dernierArret);
		}
		
		if($laFinEstVraimentLaFin && $this->_retourDirect)
		{
			$retour = $this->_retour;
			$this->_retour = array();
			return $retour;
		}
	}
}

?>
