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
		$this->ligne = $this->_sqleur->_ligne;
		
		$this->déjàFaite = false;
		$this->enCours = false;
		$this->cond = $cond;
		$this->boucle = $boucle;
	}
	
	public function avérée()
	{
			return $this->_sqleur->calculerExpr($this->cond);
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
			throw $this->_sqleur->exception('#done: le #while '.$this->cond.' correspondant n\'a pas été ouvert dans ce fichier');
		
		// On récupère ce sur quoi était en train de bosser le Sqleur avant de recontrer le #done.
		$this->corps .= substr($this->_sqleur->_chaîneEnCours, 0, $this->_sqleur->_posAvant);
		$this->boucler();
	}
	
	public function boucler()
	{
		$this->_sqleur->mémoriserÉtat(true);
		$this->_sqleur->_ligne = $this->ligne;
		$corps = $this->corps();
		while($this->avérée())
		{
			$this->_sqleur->_ligne = $this->ligne;
			$this->_sqleur->découperBloc($corps, false);
		}
		$this->_sqleur->restaurerÉtat();
	}
	
	public function corps()
	{
		if($this->_étêtage)
		{
			if($this->_étêtage > strlen($this->corps))
				throw $this->_sqleur->exception('corps de boucle '.$this->cond.' non défini');
			$this->corps = substr($this->corps, $this->_étêtage);
			$this->_étêtage = 0;
		}
		return $this->corps;
	}
}

?>
