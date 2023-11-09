<?php
/*
 * Copyright (c) 2023 Guillaume Outters
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

class SqleurPreproCreate extends SqleurPrepro
{
	public function grefferÀ($sqleur)
	{
		parent::grefferÀ($sqleur);
		$sqleur->_fonctions['oracle_in'] = [ $this, '_oracle_in' ];
		$sqleur->_fonctionsInternes['oracle_in'] = true;
	}
	
	public function _oracle_in($col, $vals, $n = null)
	{
		if(!count($vals)) return '1 = 0';
		
		if(!isset($n)) $n = 1000;
		
		$vals = array_map(function($x) { return "'".strtr($x, [ "'" => "''" ])."'"; }, $vals);
		
		if(preg_match('/^[1-9][0-9]*[bc]$/', $n))
		{
			$n = 0 + substr($n, 0, -1);
			$n -= 3; // Un peu de place pour les or en début de ligne.
			$rs = [];
			$r = '';
			foreach($vals as $val)
			{
				if($r && strlen($r) + strlen($val) > $n) // L'if($r) force au moins une valeur par ligne si la limite a été vue vraiment trop basse.
				{
					$rs[] = $r.')';
					$r = '';
				}
				$r .= ($r ? ',' : $col.' in (').$val;
			}
			if(strlen($r)) $rs[] = $r. ')';
		}
		else
			$rs = array_map(function($x) use($col) { return $col.' in ('.implode(',', $x).')'; }, array_chunk($vals, $n));
		$r = count($rs) <= 1 ? $rs[0] : "(\n".implode("\nor ", $rs)."\n)";
		return $r;
	}
}

?>
