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

require_once dirname(__FILE__).'/Sqleur.php';
require_once dirname(__FILE__).'/SqleurPreproIncl.php';
require_once dirname(__FILE__).'/SqleurPreproTest.php';
require_once dirname(__FILE__).'/SqleurPreproPif.php';

/**
 * Classe-socle pour un test PHPUnit.
 */
class SqleurTestSuite extends \PHPUnit\Framework\TestCase
{
	public function __construct()
	{
		parent::__construct();
		
		$prépros = array
		(
			'i' => new SqleurPreproIncl(),
			't' => new SqleurPreproTest(SqleurPreproTest::PHPUNIT),
			'p' => new SqleurPreproPif(),
		);
		$prépros['t']->_accuErr = $this;
		$this->sqleur = new Sqleur(array($this, 'sortir'), $prépros);
	}
	
	public function sql()
	{
		$infosClasse = new ReflectionClass($this);
		return strtr($infosClasse->getFileName(), array('.php' => '.sql'));
	}
	
	public function testSql()
	{
		$this->sqleur->decoupeFichier($this->sql());
	}
	
	public function sortir($req)
	{
		$r = $this->bdd()->prepare($req);
		$r->execute();
		return $r;
	}
	
	public function run(\PHPUnit\Framework\TestResult $result = null)
	{
		$result || $result = $this->createResult();
		$this->_result = $result;
		return parent::run($result);
	}
	
	public function err($e)
	{
		$this->_result->addError($this, $e, 0);
	}
}

?>
