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

/**
 * Préprocesseur de tests.
 * La directive #test prend les deux requêtes qui suivent:
 * - la première est un select à exécuter sur le serveur
 * - la seconde un tableau des valeurs attendues en retour
 * La première doit inclure un order by, afin que le résultat soit déterministe.
 * La seconde peut être mise dans une chaîne à dollars SQL ($<id>$ … $<id>$) pour faire propre. Dans tous les cas elle doit se terminer, comme toute instruction SQL, par un point-virgule.
 # Exemple:
   insert into gusses (id, id_pere) values (1, null);
   insert into gusses (id, id_pere) values (2, null);
   insert into gusses (id, id_pere) values (3, 1);
   insert into gusses (id, id_pere) values (4, 1);
   #test
   select pere.id, fils.id from gusses pere left join gusses fils on fils.id_pere = pere.id where pere.id_pere is null order by pere.id, fils.id;
   $$
   1  3
   1  4
   2  -
   $$;
 */
class SqleurPreproTest
{
	protected $_préfixes = array('#test');
	
	const BRUT = null;
	const PHPUNIT = 'phpunit';
	
	public function __construct($mode = SqleurPreproTest::BRUT)
	{
		$this->_mode = $mode;
	}
	
	public function préprocesse($motClé, $directiveComplète, $requêteEnCours)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$this->_entre($motClé);
		
		return $requêteEnCours;
	}
	
	protected function _entre($motClé)
	{
		if(isset($this->_sortieOriginelle))
			throw new Exception($motClé.': impossible de commencer un test alors que je n\'ai pas terminé le précédent');
		
		$this->_sortieOriginelle = $this->_sqleur->_sortie;
		$this->_sqleur->_sortie = array($this, '_chope');
		$this->_boulot = array();
	}
	
	public function _chope($req)
	{
		$this->_boulot[] = $req;
		if(count($this->_boulot) == 2)
			$this->_exécuteEtSors();
	}
		
	protected function _exécuteEtSors()
	{
		$req = $this->_boulot[0];
		$résAttendu = $this->_boulot[1];
		
		$résAttendu = $this->_normaliseRésAttendu($résAttendu);
		
		// On restaure l'environnement avant de faire le test: en cas de pétage, on doit pouvoir continuer.
		$this->_sqleur->_sortie = $this->_sortieOriginelle;
		unset($this->_sortieOriginelle);
		
		// Le test!
		$this->_teste($req, $résAttendu);
	}
	
	protected function _teste($req, $résAttendu)
	{
		$rés = call_user_func($this->_sqleur->_sortie, $req);
		if($rés) // Si on tourne lors d'une passe de préprocesseur seul, on est branchés sur une sortie factice; on ne fait alors rien.
		{
			if(is_object($rés) && $rés instanceof PDOStatement)
			{
				$t = $rés->fetchAll();
				$l = array();
				foreach($t as $ligne)
					$l[] = $this->_normalise($ligne);
				$rés = implode("\n", $l);
			}
			else if(is_string($rés) || is_numeric($rés))
				true;
			else
				throw new Exception('résultat de test inattendu');
			
			$this->_valide($résAttendu, $rés, $req);
		}
	}
	
	protected function _normaliseRésAttendu($résAttendu)
	{
		$résAttendu = preg_replace('/^([$][^$]*[$])\n*((.+(?:\n+.+))*)\n*\1\n*$/', '\2', $résAttendu);
		
		$résAttendu = preg_replace_callback('/"([^"]*)"/', function($trouvaille) { return strtr($trouvaille[1], ' ', "\003"); }, $résAttendu);
		$résAttendu = preg_replace('/ +/', "\t", $résAttendu);
		$résAttendu = strtr($résAttendu, "\003", ' ');
		
		return $résAttendu;
	}
	
	protected function _normalise($colonnes)
	{
		return implode("\t", array_map(array($this, '_normaliseColonne'), $colonnes));
	}
	
	protected function _normaliseColonne($colonne)
	{
		return isset($colonne) ? (preg_match('/^".*"$/', $colonne) ? substr($colonne, 1, -1) : ''.$colonne) : '-';
	}
	
	protected function _valide($résAttendu, $rés, $req)
	{
		switch($this->_mode)
		{
			case SqleurPreproTest::PHPUNIT:
				PHPUnit\Framework\Assert::assertEquals($résAttendu, $rés, $req);
				break;
			default:
				if($résAttendu != $rés)
					throw new Exception('Résultat obtenu différent de celui attendu:'."\n<<<<<<<\n".$résAttendu."\n=======\n".$rés."\n>>>>>>>");
				break;
		}
	}
}

?>
