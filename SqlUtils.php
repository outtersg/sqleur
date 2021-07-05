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
	/**
	 * Renvoie un extrait d'une requête SQL autour de la position $pos, suivi d'une ligne avec un marqueur ^ sous $pos.
	 */
	public function contexteSql($sql, $pos)
	{
		$tCon = 0x100; // Taille contexte.
		$tExt = 2 * $tCon; // Taille extrait.
		
		/* À FAIRE: du mb lorsque disponible. Tester avec une requête contenant des accents UTF-8 pour savoir si la pos est en octets ou caractères. */
		/* À FAIRE: la version avec codes ANSI souligné, quand dans un terminal. */
		/* À FAIRE: la version avec ... à la place de … */
		
		if($pos > $tCon)
		{
			$sql = '…'.substr($sql, $pos - $tCon, $tExt);
			$pos = $tCon + strlen('…');
		}
		else
			$sql = substr($sql, 0, $tExt);
		
		// On ne conserve la requête que jusqu'au premier retour à la ligne après $pos.
		
		if(($couic = strpos($sql, "\n", $pos)) !== false)
			$sql = substr($sql, 0, $couic);
		
		// Hum, curieux comportement de strrpos: -1 signifie "en partant du dernier caractère, *mais ce dernier compris*", -2 "sauf le dernier caractère", etc.
		if(($débutLigne = strrpos($sql, "\n", $pos - strlen($sql))) === false)
			$débutLigne = 0;
		else
			++$débutLigne;
		
		return $sql."\n".str_repeat(' ', $pos - $débutLigne).'^';
	}
	
	public function jolieEx($ex, $sql)
	{
		if(preg_match('/ at character ([0-9]+)$/', $ex->getMessage(), $re))
			$m = $ex->getMessage()."\n".$this->contexteSql($sql, 0 + $re[1]);
		
		if(isset($m))
			return new Exception($m, $ex->getCode(), $ex);
		
		return $ex;
	}
}

?>
