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

class SqleurCond
{
	public function __construct($sqleur, $cond, $boucle = false)
	{
		$this->_sqleur = $sqleur;
		$this->sortie = $this->_sqleur->_sortie;
		$this->défs = $this->_sqleur->_defs;
		$this->requêteEnCours = $this->_sqleur->_requeteEnCours;
		$this->requêteRemplacée = isset($this->_sqleur->_requêteRemplacée) ? $this->_sqleur->_requêteRemplacée : null;
		$this->ligne = $this->_sqleur->_ligne;
		
		$this->déjàFaite = false;
		$this->enCours = false;
		$this->cond = $cond;
		if(is_array($this->cond))
			$this->var = array_shift($this->cond);
		$this->boucle = $boucle;
	}
	
	public function avérée()
	{
		if(is_string($this->cond))
			return $this->_sqleur->calculerExpr($this->cond);
		else if(is_array($this->cond))
			return count($this->cond) > 0;
	}
	
	public function enCours($ouiOuNon)
	{
		// Si nous devons boucler, on s'abonne à tout ce qui passera par le Sqleur afin d'accumuler le corps de boucle pour pouvoir ensuite le rejouer.
		if($this->boucle && ($ouiOuNon ^ $this->enCours) && !$this->_sqleur->dansUnSiÀLaTrappe())
		{
			if($ouiOuNon)
				$this->_commencer();
			else
				$this->_terminer();
		}
		$this->enCours = $ouiOuNon;
	}
	
	protected function _commencer()
	{
		$this->corps = '';
		$this->_étêtage = $this->_sqleur->_posAprès; // Tout ce qui précède la directive elle-même devra sauter.
		
		$this->_sqleur->_boucles[] = $this;
		
		$this->_défSiVar();
	}
	
	protected function _défSiVar()
	{
		if(isset($this->var) && is_array($this->cond))
		{
			$val = array_shift($this->cond);
			$défs =
				is_array($this->var) && is_array($val)
				? array_diff_key(array_combine($this->var, $val), array('' => true)) // La clé vide (#for VAL1,,VAL2 in `select 1, 'm''en fiche', 2`) signifie que l'on souhaite ignorer le résultat.
				: array(is_array($this->var) ? $this->var[0] : $this->var => $val);
			$this->_sqleur->ajouterDéfs($défs);
		}
	}
	
	protected function _terminer()
	{
		foreach($this->_sqleur->_boucles as $num => $abonné)
			if($abonné === $this)
			{
				unset($this->_sqleur->_boucles[$num]);
				$trouvé = true;
				break;
			}
		if(!isset($trouvé))
			throw $this->_sqleur->exception('#done: le #while '.(is_string($this->cond) ? $this->cond : serialize($this->cond)).' correspondant n\'a pas été ouvert dans ce fichier');
		
		// On récupère ce sur quoi était en train de bosser le Sqleur avant de recontrer le #done.
		$this->corps .= substr($this->_sqleur->_chaîneEnCours, 0, $this->_sqleur->_posAvant);
		$this->boucler();
	}
	
	public function boucler()
	{
		$dernièreDécoupe = $this->_sqleur->_chaineDerniereDecoupe;
		$this->_sqleur->mémoriserÉtat(true);
		$this->_sqleur->_ligne = $this->ligne;
		$corps = $this->corps();
		while($this->avérée())
		{
			$this->_sqleur->_chaineDerniereDecoupe = "\n"; // Le re-bloc commence par un "\n".
			$this->_sqleur->_resteEnCours = null; /* À FAIRE: ne risque-t-on pas d'écraser quelque chose que le précédent tour de boucle souhaitait nous voir compléter? */
			$this->_défSiVar();
			$this->_sqleur->_ligne = $this->ligne + 1;
			$this->_sqleur->_débouclages[] = $this;
			$this->_sqleur->découperBloc($corps, false);
			array_pop($this->_sqleur->_débouclages);
		}
		$this->_sqleur->restaurerÉtat();
		$this->_sqleur->_chaineDerniereDecoupe = $dernièreDécoupe;
	}
	
	public function corps()
	{
		if($this->_étêtage)
		{
			if($this->_étêtage > strlen($this->corps))
				throw $this->_sqleur->exception('corps de boucle '.(is_string($this->cond) ? $this->cond : serialize($this->cond)).' non défini');
			$this->corps = substr($this->corps, $this->_étêtage);
			$this->_étêtage = 0;
		}
		return $this->corps;
	}
}

?>
