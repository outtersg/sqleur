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

require_once dirname(__FILE__).'/SqleurPreproExpr.php';
require_once dirname(__FILE__).'/SqleurPreproTest.php';

class SqleurPreproTestExpr extends SqleurPreproTest
{
	protected $_préfixes = array('#testexpr');
	
	public function préprocesse($motClé, $directiveComplète)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$this->_entreExpr($motClé, preg_replace('/^ *[^ ]* */', '', $directiveComplète));
	}
	
	protected function _entreExpr($motClé, $expr)
	{
		if(isset($this->_sortieOriginelle))
			throw new Exception($motClé.': impossible de commencer un test alors que je n\'ai pas terminé le précédent');
		
		$this->_expr = $expr;
		
		$this->_sortieOriginelle = $this->_sqleur->_sortie;
		$this->_sqleur->_sortie = array($this, '_chopeRésAttenduEtSors');
	}
	
	public function _chopeRésAttenduEtSors($résAttendu)
	{
		// On restaure l'environnement avant de faire le test: en cas de pétage, on doit pouvoir continuer.
		$this->_sqleur->_sortie = $this->_sortieOriginelle;
		unset($this->_sortieOriginelle);
		
		// Le test!
		$this->_teste($this->_expr, $résAttendu);
	}
	
	protected function _teste($req, $résAttendu)
	{
		$résAttenduNormalisé = preg_replace('/, */', ',', preg_replace("/\n*[ \t]*/", '', $résAttendu));
		
		$expr = new SqleurPreproExpr;
		try
		{
			$rés = $expr->aff($expr->compiler($req));
			$résAttendu = $résAttenduNormalisé;
		}
		catch(Exception $ex)
		{
			$rés = '! '.$ex->getMessage();
			if($résAttendu{0} == '!')
			{
				$résAttendu = preg_replace('/^! */', '', $résAttendu);
				if(preg_match("\003$résAttendu\003", $ex->getMessage()))
					$rés = $résAttendu; // Pour éviter au _valide de planter.
			}
		}
		
		$this->_valide($résAttendu, $rés, $req);
	}
}

?>
