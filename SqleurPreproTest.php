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
class SqleurPreproTest extends SqleurPrepro
{
	protected $_préfixes = array('#test');
	
	protected $_mode;
	
	const APPELANT = 0x01; // Masque des bits d'appelant.
	const BRUT =     0x00;
	const PHPUNIT =  0x01;
	
	const FATAL =    0x04;
	
	public function __construct($mode = SqleurPreproTest::BRUT)
	{
		$this->_mode = $mode;
		$this->_accuErr = $this;
	}
	
	public function préprocesse($motClé, $directiveComplète)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$this->_entre($motClé, $directiveComplète);
	}
	
	protected function _entre($motClé, $directiveComplète)
	{
		if(isset($this->_sortieOriginelle))
			throw new Exception($motClé.': impossible de commencer un test alors que je n\'ai pas terminé le précédent');
		
		$this->_préempterSql(2);
		$this->_boulot = array();
		$this->_prochainFatal = $this->_mode & SqleurPreproTest::FATAL;
		if(preg_match("/^$motClé\\sfatal/", $directiveComplète))
			$this->_prochainFatal = true;
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
				$t = $rés->fetchAll(PDO::FETCH_NUM);
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
		$résAttendu = preg_replace('/^([$][^$]*[$])\n*(.+(?:\n+.+)*)\n*\1\n*$/', '\2', $résAttendu);
		
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
		switch($this->_mode & SqleurPreproTest::APPELANT)
		{
			case SqleurPreproTest::PHPUNIT:
				try
				{
					PHPUnit\Framework\Assert::assertEquals("\n".$résAttendu."\n", "\n".$rés."\n", $req);
				}
				catch(Exception $ex)
				{
					// Le moyen le plus simple d'avoir, en cas d'exception, un message enrichi de la pile d'appels, est de rejouer l'assertion avec le nouveau message.
					$ex = $this->_sqleur->exception($req);
					try
					{
					PHPUnit\Framework\Assert::assertEquals("\n".$résAttendu."\n", "\n".$rés."\n", $ex->getMessage());
					}
					catch(PHPUnit\Framework\AssertionFailedError $e)
					{
						$this->_err($e);
					}
				}
				break;
			default:
				if($résAttendu != $rés)
					$this->_err($this->_sqleur->exception('résultat obtenu différent de celui attendu:'."\n".$req."\n<<<<<<<\n".$résAttendu."\n=======\n".$rés."\n>>>>>>>"));
				break;
		}
	}
	
	protected function _err($e)
	{
		if(!isset($this->_prochainFatal) || !$this->_prochainFatal)
			$this->_accuErr->err($e);
		else
			throw $e;
	}
	
	public function err($e)
	{
		$message = '# '.(is_object($e) ? $e->__toString() : $e);
		$message = strtr($message, array("\n" => "\n  "));
		$message .= "\n";
		$finPremièreLigne = strpos($message, "\n");
		$message = '[31m'.substr($message, 0, $finPremièreLigne).'[0m'.substr($message, $finPremièreLigne);
		fprintf(STDERR, $message);
	}
}

?>
