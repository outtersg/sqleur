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

require_once __DIR__.'/SqleurPrepro.php';

class SqleurPreproIncl extends SqleurPrepro
{
	protected $_préfixes = array('#incl', '#inclure', '#include');
	
	public function préprocesse($motClé, $directiveComplète)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$directiveComplète = $this->_sqleur->appliquerDéfs($directiveComplète);
		$bouts = preg_split("/[ \t,;]+/", $directiveComplète);
		array_shift($bouts);
		$auMoinsUn = false;
		foreach($bouts as $bout)
		{
			$chemin = trim($bout, '"');
			if(empty($chemin))
				continue;
			$auMoinsUn = true;
			$this->inclure($chemin);
		}
		if(!$auMoinsUn)
			throw new Exception($motClé.', oui, mais '.$motClé.' quoi?');
	}
	
	public function inclure($chemin)
	{
		if(substr($chemin, 0, 1) != '/' && isset($this->_sqleur->_fichier))
			$chemin = dirname($this->_sqleur->_fichier).'/'.$chemin;
		$cheminAbsolu = $chemin;
		if(substr($cheminAbsolu, 0, 1) != '/') $cheminAbsolu = getcwd().'/'.$cheminAbsolu;
		
		// Si le script est le premier inclus, on colle son nom comme point d'entrée.
		$créf = ':SCRIPT_FILENAME';
		$défs = [ $créf => $cheminAbsolu, ':SCRIPT_NAME' => basename($chemin) ];
		if(!$this->_sqleur->_defined($créf) || !$this->_sqleur->_defs['stat'][$créf]) // La valeur 0 (stdin) n'est pas pérenne.
			$this->_sqleur->ajouterDéfs($défs);
		/* À FAIRE: un __FILE__ lui défini inconditionnellement? */
		
		$this->_sqleur->_découpeFichier($chemin);
	}
}

?>
