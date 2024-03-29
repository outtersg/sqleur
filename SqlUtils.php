<?php
/*
 * Copyright (c) 2021 Guillaume Outters
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

class SqlUtils
{
	public static $FONCS;
	
	public function __construct()
	{
		if(!isset(SqlUtils::$FONCS))
		{
			$foncs = array
			(
				'strlen',
				'substr',
				'strpos',
				'strrpos',
			);
			foreach($foncs as $fonc)
				SqlUtils::$FONCS[$fonc] = $fonc;
			
			if(function_exists('mb_strrpos'))
				foreach(SqlUtils::$FONCS as & $pFonc)
					$pFonc = 'mb_'.$pFonc;
		}
	}
	
	/**
	 * Renvoie un extrait d'une requête SQL autour de la position $pos, suivi d'une ligne avec un marqueur ^ sous $pos.
	 */
	public function contexteSql($sql, $pos)
	{
		foreach(SqlUtils::$FONCS as $orig => $impl)
			$$orig = $impl;
		
		$tCon = 0x100; // Taille contexte.
		$tExt = 2 * $tCon; // Taille extrait.
		
		/* À FAIRE: la version avec codes ANSI souligné, quand dans un terminal. */
		/* À FAIRE: la version avec ... à la place de … */
		
		if($pos > $tCon)
		{
			$sql = '…'.$substr($sql, $pos - $tCon, $tExt);
			$pos = $tCon + $strlen('…');
		}
		else
			$sql = $substr($sql, 0, $tExt);
		
		// On ne conserve la requête que jusqu'au premier retour à la ligne après $pos.
		
		if(($couic = $strpos($sql, "\n", $pos)) !== false)
			$sql = $substr($sql, 0, $couic);
		
		// Hum, curieux comportement de strrpos: -1 signifie "en partant du dernier caractère, *mais ce dernier compris*", -2 "sauf le dernier caractère", etc.
		if(($débutLigne = $strrpos($sql, "\n", $pos - $strlen($sql))) === false)
			$débutLigne = 0;
		else
			++$débutLigne;
		
		$index = str_repeat(' ', $pos - $débutLigne).'^';
		
		// Les caractères qui prennent un peu plus de place.
		for($i = $débutLigne - 1; ++$i < $pos;)
			if($substr($sql, $i, 1) == "\t")
				$index[$i - $débutLigne] = "\t";
		
		return $sql."\n".$index;
	}
	
	public function jolieEx($ex, $sql)
	{
		if(preg_match('/ at character ([0-9]+)$/', $ex->getMessage(), $re))
			$m = $ex->getMessage().":\n".$this->contexteSql($sql, -1 + $re[1]);
		
		if(isset($m))
			/* À FAIRE: rebalancer une get_class($ex) serait plus propre, malheureusement certaines classes internes n'ont pas le constructeur adéquat. */
			return new Exception($m, $ex->getCode(), $ex);
		
		return $ex;
	}
}

?>
