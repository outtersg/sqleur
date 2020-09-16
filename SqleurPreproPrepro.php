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

class SqleurPreproPrepro
{
	protected $_préfixes = array('#prepro');
	
	public function préprocesse($motClé, $directiveComplète)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$bouts = preg_split("/[ \t,;]+/", $directiveComplète);
		array_shift($bouts);
		foreach($bouts as $bout)
		{
			foreach(array($bout, 'SqleurPrepro'.ucfirst($bout)) as $classe)
				if(!class_exists($classe))
					if(file_exists($f = dirname(__FILE__).'/'.$classe.'.php'))
					{
						require_once $f;
						break;
					}
			if(!class_exists($classe))
				throw new Exception('préprocesseur '.$bout.' introuvable');
			
			foreach($this->_sqleur->_préprocesseurs as $p)
				if($p instanceof $classe)
					continue;
			
			$p = new $classe;
			$p->_sqleur = $this->_sqleur;
			$this->_sqleur->_préprocesseurs[] = $p;
		}
	}
}

?>
