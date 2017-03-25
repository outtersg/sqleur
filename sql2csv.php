#!/usr/bin/env php
<?php

require_once dirname(__FILE__).'/Sqleur.php';

class Flux
{
	const STDIN = 0;
	const STDOUT = 1;
	const STDERR = 2;
	
	const À_OUVRIR = -1;
	const PERMANENT = 0;
	const OUVERT = 1;
	
	public $descr;
	public $f;
	protected $état;
	protected $sortieSinonEntrée;
	
	public function __construct($descr, $sortieSinonEntrée)
	{
		if(is_int($descr))
		{
			switch($descr)
			{
				case Flux::STDIN:
					$this->f = STDIN;
					break;
				case Flux::STDOUT:
					$this->f = STDOUT;
					break;
				case Flux::STDERR:
					$this->f = STDERR;
					break;
				default:
					throw new Exception("Descripteur de fichier $descr inconnu");
			}
			$this->état = Flux::PERMANENT;
		}
		else
			$this->état = Flux::À_OUVRIR;
		$this->descr = $descr;
		$this->sortieSinonEntrée = $sortieSinonEntrée;
	}
	
	public function ouvrir($re = false)
	{
		switch($this->état)
		{
			case Flux::À_OUVRIR:
				$this->f = fopen($this->descr, $this->sortieSinonEntrée ? ($re ? 'a' : 'w') : 'r');
				if($this->f === false)
					throw new Exception('Impossible d\'ouvrir "'.$this->descr.'"');
				$this->état = Flux::OUVERT;
				break;
		}
		return $this->f;
	}
	
	public function fermer()
	{
		switch($this->état)
		{
			case Flux::OUVERT:
				fclose($this->f);
				$this->état = Flux::À_OUVRIR;
				break;
		}
	}
}

class JoueurSql extends Sqleur
{
	protected $bdd;
	protected $sortiesDéjàUtilisées = array();
	public $conversions;
	
	public function __construct()
	{
		// À FAIRE: permettre des directives #output dans le SQL pour changer de fichier de sortie; cela permettrait de caser plusieurs exports dans le même .sql, en séparant chaque export par cette directive.
		parent::__construct(array($this, 'exécuter'));
	}
	
	public function sortie($sortie)
	{
		$this->sortie = new Flux($sortie, true);
	}
	
	public function jouer($chemin)
	{
		$fluxEntrée = new Flux($chemin, false);
		$requêtes = $this->decoupeFlux($fluxEntrée->ouvrir());
		$fluxEntrée->fermer();
	}
	
	public function exécuter($sql)
	{
		fprintf(STDERR, "    ".strtr($sql, array("\n" => "\n    ")).";\n");
		$rés = $this->bdd->query($sql);
		$rés->setFetchMode(PDO::FETCH_ASSOC);
		if(($nCols = $rés->columnCount()) > 0)
		{
			$colonnes = array();
			for($numCol = -1; ++$numCol < $nCols;)
			{
				$descrCol = $rés->getColumnMeta($numCol);
				$colonnes[] = $descrCol['name'];
			}
			$this->exporter($rés, $colonnes);
		}
	}
	
	protected function exporter($résultat, $colonnes = null)
	{
		// La sortie courante a-t-elle déjà été utilisée? Si oui, ce peut être embêtant, car plusieurs requêtes successives n'ont pas forcément le même format de sortie, donc les agréger dans le même fichier de sortie est risqué pour le moins. Si l'on veut une telle chose, mieux vaudra passer par un union SQL (qui garantira que les deux requêtes sortent des colonnes compatibles).
		
		$re = false;
		foreach($this->sortiesDéjàUtilisées as $sortieAncienne)
			if($sortieAncienne->descr === $this->sortie->descr)
			{
				fprintf(STDERR, '# La sortie "'.$sortieAncienne->descr.'" est réutilisée en ayant déjà servi pour l\'export d\'une autre requête. Nous ne garantissons pas que le fichier résultant sera cohérent entre les deux exports qui y sont combinés.'."\n");
				$re = true;
				break;
			}
		
		$this->sortiesDéjàUtilisées[] = $this->sortie;
		
		$this->sortie->ouvrir($re);
		
		if(isset($colonnes))
			$this->exporterLigne($colonnes, ';', '');
		
		while(($l = $résultat->fetch()) !== false)
			$this->exporterLigne($l);
		
		$this->sortie->fermer();
	}
	
	protected function exporterLigne($l)
	{
		if(isset($this->conversions))
			foreach($l as & $ptrChamp)
				$ptrChamp = strtr($ptrChamp, $this->conversions);
		fputcsv($this->sortie->f, $l, ';');
	}
}

class Sql2Csv
{
	public function __construct($argv, $j)
	{
		// Analyse des paramètres.
		
		$entrées = array();
		$sortie = Flux::STDOUT;
		$conversions = array();
		
		for($i = 0; ++$i < count($argv);)
			switch($argv[$i])
			{
				case '-o':
					++$i;
					$sortie = $argv[$i];
					break;
				default:
					$entrées[] = $argv[$i] === '-' ? Flux::STDIN : $argv[$i];
					break;
			}
		
		if(!count($entrées))
			$entrées[] = Flux::STDIN;
		
		// On y va!
		
		$j->conversions = isset($conversions) && count($conversions) ? $conversions : null;
		$j->sortie($sortie);
		foreach($entrées as $entrée)
			$j->jouer($entrée);
	}
}
