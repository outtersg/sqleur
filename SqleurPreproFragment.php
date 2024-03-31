<?php
/*
 * Copyright (c) 2022 Guillaume Outters
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

class SqleurPreproFragment extends SqleurPreproIncl
{
	protected $_préfixes = array('#fragment');
	
	public function grefferÀ($sqleur)
	{
		parent::grefferÀ($sqleur);
		$sqleur->ajouterDéfs([ '/(?:'.implode('|', $this->_préfixes).')\("([^"]*)",\s*"([^"]*)"\)/' => [ $this, 'fragment' ] ]);
	}
	
	public function fragment($chemin, $format = null)
	{
		if(is_array($chemin))
			list($invocation, $chemin, $format) = $chemin;
		/* COPIE: parent::inclure() */
		if(substr($chemin, 0, 1) != '/' && isset($this->_sqleur->_fichier))
			$chemin = dirname($this->_sqleur->_fichier).'/'.$chemin;
		
		$this->_accu = '';
		$this->_sortieOriginelle = $this->_sqleur->_sortie;
		$this->_sqleur->_sortie = array($this, '_chope');
		$mém = $this->_sqleur->pause();
		
		$this->_sqleur->_découpeFichier($chemin, true);
		
		$this->_sqleur->reprise($mém);
		$this->_sqleur->_sortie = $this->_sortieOriginelle;
		unset($this->_sortieOriginelle);
		$rés = $this->_accu;
		unset($this->_accu);
		
		// On restaure l'environnement à la manière d'une ligne préprocesseur (car nous pouvons être invoqués au fil de l'eau et ne bénéficions alors pas de toutes les restaurations de contexte qu'ont les prépro).
		if(!isset($this->_sqleur->_requeteEnCours))
			$this->_sqleur->_requeteEnCours = '';
		
		// On retravaille le fragment obtenu, et on le pousse.
		
		switch($format)
		{
			case 'json': $rés = json_encode($rés); break;
		}
		
		return $rés; // Renvoi plutôt que découperBloc car nous sommes appelés comme fonction génératrice de contenu.
	}
	
	public function _chope($req, $false = false, $interne = null)
	{
		if($interne)
		{
			$params = func_get_args();
			return call_user_func($this->_sortieOriginelle, $params);
		}
		
		// COPIE: sql2csv:SPP.exécuter()
		// À FAIRE: ne pas être si lié au SQL (point-virgule terminaison par défaut).
		$terminaison = isset($this->_sqleur->terminaison) ? $this->_sqleur->terminaison : ";\n";
		
		$this->_accu .= $req.$terminaison;
	}
	
	protected $_accu;
	protected $_sortieOriginelle;
}

?>
