<?php

class SqleurPrepro
{
	public $_sqleur;
	
	public function grefferÀ($sqleur)
	{
		$this->_sqleur = $sqleur;
	}
	
	/*- Préemption -----------------------------------------------------------*/
	
	/**
	 * Dérive le Sqleur pour récupérer les $n prochaines requêtes SQL.
	 */
	protected function _préempterSql($n = 1)
	{
		$this->_nReqsÀChoper = $n;
		$this->_sortieOriginelle = $this->_sqleur->_sortie;
		
		$foncEtParams = func_get_args();
		array_splice($foncEtParams, 0, 1, [ $this, '_préemptionSql' ]);
		$this->_sqleur->_sortie = $foncEtParams;
	}
	
	public function _préemptionSql($req)
	{
		if(!--$this->_nReqsÀChoper)
		{
			/* BOGUE: un #create récoltant les requêtes entrecoupées d'#if ne se restaure pas bien et continue à choper après. */
			$this->_sqleur->_sortie = $this->_sortieOriginelle;
			unset($this->_sortieOriginelle);
		}
		
		$params = func_get_args();
		return call_user_func_array([ $this, '_chope' ], $params);
	}
	
	protected $_sortieOriginelle;
	protected $_nReqsÀChoper;
}

?>
