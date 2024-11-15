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

require_once __DIR__.'/SqleurPrepro.php';

class SqleurPreproCopy extends SqleurPrepro
{
	/* NOTE: #copy volumineux
	 * Le SqleurPreproCopy se garde d'exploser en Memory exhausted, en jouant chaque paquet de 16 Mo individuellement plutôt que d'attendre la toute dernière ligne du CSV pour pousser.
	 * Cela peut donc introduire un risque de transcription non fidèle (variables de préprocesseur tranchées en deux), ainsi qu'un risque d'explosion des contraintes si la première entrée requiert la présence de la dernière mais qu'elles sont envoyées dans deux lots différents.
	 */
	protected $_préfixes = array('#copy');
	
	public function préprocesse($motClé, $directiveComplète)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$this->_entre($motClé, $directiveComplète);
	}
	
	protected function _entre($motClé, $directiveComplète)
	{
		$this->_pousseur();
		
		$this->_pousseur->init(substr($directiveComplète, 1));
		
		if(!isset($this->_pousseur->source))
		{
		$this->_sortieOriginelle = $this->_sqleur->_sortie;
		/* À FAIRE: faire basculer le Sqleur dans un mode ligne à ligne plutôt qu'expression par expression, pour ne pas devoir charger tout le fichier en mémoire avant de l'injecter. */
			$this->_sqleur->_brut = true;
		$this->_sqleur->_sortie = array($this, '_chope');
		}
		else
		{
			$sépl = "\n";
			$f = fopen($this->_pousseur->source, 'r');
			$a = ''; // $accu
			$b = true; // $bloc
			while($b !== false)
			{
				$b = stream_get_line($f, 0x10000);
				// Nom d'une pipe, n'y a-t-il pas moyen de préciser le dernier paramètre à stream_get_line
				// mais qu'il nous permette de distinguer un retour sur séparateur d'un retour sur taille max de bloc?
				// S'il nous renvoie une taille inférieure, très bien, on sait qu'il a trouvé notre fin de ligne{{","|transs->_pousseur->ligne(substr($a, $d, $e - $d));}}
				// mais s'il renvoie pile la taille,
				// ce peut être soit une fin de bloc (auquel cas on doit agréger le bloc suivant),
				// soit un retour (auquel cas il ne faut surtout pas agréger avec la ligne suivante!).
				// En attendant on se farcit le truc à la main :-\
				// Sinon on pourrait optimiser en fgets lorsque $sépl est \n
				if($b !== false)
					if($a !== '')
						$a .= $b;
					else
						$a = $b;
				/* NOTE: Expérimentations sur les différentes techniques, par bloc de 0x10000 octets, en PHP 7.2
				 * - 0,006 preg_split() + lignes()
				 * - 0,2   preg_match_all("/\n/") + for substr() + lignes()
				 * - 0,5   preg_match_all("/\n/") + for substr() + lignes()
				 * - 0,6   preg_match_all("/\n/") + for ligne(substr())
				 * - 0,8   for strpos() ligne(substr())
				 * En conclusion le coûteux est l'invocation de petites fonctions dans une boucle for.
				 * La combinaison preg_split() (qui prépare déjà le tableau de lignes) + lignes(), à deux appels, est imbattable.
				 * preg_split() est à peine plus lent que preg_match_all("/\n/"),
				 * mais renvoie directement les lignes tandis que le second demanderait derrière un coûteux substra l'avantage de préparer de façon + lignes() est 80 fois 80 fois plus rapide qu'une boucle de strpos.
				 */
				$rs = preg_split('/'.$sépl.'+/', $a);
				if($b !== false)
					$a = array_pop($rs);
				else
				{
					if(count($rs) == 1 && strlen(trim($a, $sépl)) == 0)
						$rs = [];
					$a = '';
				}
				$this->_pousseur->lignes($rs);
				/* À FAIRE: des fin() intermédiaires comme avec _chopePartiel(). */
			}
			fclose($f);
			
			$this->_pousseur->fin();
		}
	}
	
	protected function _pousseur()
	{
		if(isset($this->_pousseur))
			return $this->_pousseur;
		
		if(isset($this->_sqleur->bdd))
			$bdd = $this->_sqleur->bdd;
		else if
		(
			isset($this->_sqleur->_sortie)
			&& is_array($this->_sqleur->_sortie)
			&& isset($this->_sqleur->_sortie[0])
			&& is_object($this->_sqleur->_sortie[0])
			&& isset($this->_sqleur->_sortie[0]->bdd)
		)
			$bdd = $this->_sqleur->_sortie[0]->bdd;
		else
			throw new Exception('copy appelé, mais le Sqleur n\'a pas de bdd attachée');
		/* À FAIRE: pgsqlCopyFromArray/File c'est bien gentil mais ça oblige à tout charger en mémoire avant d'appeler.
		 * N'y a-t-il pas une bonne âme pour répliquer ce que font les pg_put_line et pg_end_copy? */
		if(method_exists($bdd, 'pgsqlCopyFromFile'))
			$this->_pousseur = new SqleurPreproCopyPousseurPg($bdd);
		/* À FAIRE: autres SGBD que PostgreSQL. */
		
		if(!isset($this->_pousseur))
			throw new Exception('le pilote ne dispose d\'aucun moyen de pousser un fichier complet');
		
		return $this->_pousseur;
	}
	
	public function _chope($req, $partiel = false)
	{
		$sépl = "\n";
		
		if(($déjà = isset($this->_requêteEnCours)))
			$req = $this->_requêteEnCours.$req;
		if($partiel)
		{
			// On ne cherche pas le \n tout en bout de chaîne, mais on préserve le bout susceptible de contenir notre terminateur: il serait malencontreux qu'un \n s'y trouvant soit pris pour une fin d'enregistrement.
			if(($tailleReste = strlen($req) - ($this->_terminator ? strlen($this->_terminator) : 8) - 3) < 0) $tailleReste = 0;
			if(($fin = strrpos(substr($req, 0, $tailleReste), $sépl)) === false)
			{
				$this->_requêteEnCours = $req;
				$req = '';
			}
			else
			{
				$this->_requêteEnCours = substr($req, $fin + 1);
				$req = substr($req, 0, $fin);
			}
		}
		else
			unset($this->_requêteEnCours);
		
		/* Pour le moment on ne gère qu'une grosse chaîne de caractères, délimiteur dollar. */
		
		$rd = [ '', '' ];
		if(!$déjà)
		{
			if(!preg_match('/'.self::DÉMARRAGE.'/', $req, $rd))
			throw new Exception('copy prend en entrée une chaîne délimitée par dollars');
			$this->_terminator = $rd[1];
		}
		if(!$partiel)
			if(!preg_match('/\n[$]'.$this->_terminator.'[$]\n*$/', $req, $rf))
			throw new Exception('copy prend en entrée une chaîne délimitée par dollars et terminée de la même manière');
		
		$bazar = isset($rf) ? substr($req, strlen($rd[0]), -strlen($rf[0])) : substr($req, strlen($rd[0]));
		$ls = [];
		foreach(explode("\n", $bazar) as $l)
			if($l) // Les lignes vides ne nous intéressent pas.
				$ls[] = $l;
		$this->_pousseur->lignes($ls);
		
		$this->_pousseur->fin();
		
		if($partiel || isset($this->_lignesEnCours))
			/* À FAIRE: demander en plus au lanceur s'il est verbeux. */
			if(function_exists('posix_isatty') && posix_isatty(STDERR))
			{
				if(!isset($this->_lignesEnCours)) $this->_lignesEnCours = 0;
				$this->_lignesEnCours += count($ls);
				fprintf(STDERR, "\rcopy %d%s", $this->_lignesEnCours, $partiel ? '' : "\n");
				if(!$partiel)
					unset($this->_lignesEnCours);
			}
		
		if($partiel)
			return;
		
		$this->_sqleur->_sortie = $this->_sortieOriginelle;
		$this->_sqleur->_brut = false;
		unset($this->_sortieOriginelle);
	}
	
	public function _chopePartiel($req)
	{
		// On ne démarre qu'une fois entrés dans le vif du sujet.
		if(!isset($this->_requêteEnCours) && !preg_match('/'.self::DÉMARRAGE.'/', $req)) return;
		
		// Ne travaillons pas pour des clopinettes.
		// PHP n'a pas à rougir face à psql, c'est surtout la latence du réseau qui fait préférer les gros paquets aux petits.
		if(strlen($req) < (getenv('BLOC_COPIE') ? getenv('BLOC_COPIE') : 0x1000000)) return;
		
		$this->_chope($req, true);
		return strlen($req);
	}
	
	protected $_pousseur;
	protected $_sortieOriginelle;
	protected $_requêteEnCours;
	protected $_terminator;
	protected $_lignesEnCours;
	
	const DÉMARRAGE = '^\n*[$]([^ $]*)[$]\n*';
}

class SqleurPreproCopyPousseur
{
	public $source;
	
	public function __construct($bdd)
	{
		$this->_bdd = $bdd;
	}
	
	public function init($req)
	{
		$expr = 'from (?<from>stdin|\'[^ ]+\')|delimiter \'(?<delim>[^\']+)\'|null (?:as )?(?<null>\'[^\']*)\'|(?<csv>csv)|(?<sauf>header)';
		
		if(!preg_match("/^copy\\s+(?<t>[a-z0-9_.]+)(?:\\s*\((?<c>[^)]+)\))?(?<p>(?:\\s+(?:$expr))*)\$/i", $req, $r))
			throw new Exception('copy ininterprétable: '.$req);
		preg_match_all("/\\s+(?:$expr)/i", $r['p'], $rpss, PREG_SET_ORDER);
		$rp = array();
		foreach($rpss as $rps)
			foreach($rps as $clé => $val)
				if(!empty($val))
					$rp[$clé] = $val;
		
		$this->_table = $r['t'];
		$this->_champs = preg_split('/\s*,\s*/', $r['c']);
		$this->_sép = empty($rp['delim']) ? "\t" : $rp['delim'];
		$this->_null = isset($rp['null']) ? substr($rp['null'], 1) : null; // La chaîne vide n'étant pas capturée par PCRE, on capture avec le guillemet initial écarté ici.
		$this->_csv = empty($rp['csv']) ? null : $rp['csv'];
		$this->source = empty($rp['from']) || strcasecmp($rp['from'], 'stdin') == 0 ? null : substr($rp['from'], 1, -1);
		$this->_csv = empty($rp['csv']) ? null : $rp['csv'];
		$this->_sauf = empty($rp['sauf']) ? 0 : 1;
		
		$this->_données = array();
	}
	
	public function lignes($ls)
	{
		$this->_données = array_merge($this->_données, $ls);
	}
	
	public function ligne($l)
	{
		$this->_données[] = $l;
	}
	
	protected function données()
	{
		$données = $this->_données;
		
		if($this->_sauf)
		{
			$données = array_slice($données, $this->_sauf);
			$this->_sauf = 0;
		}
		
		if(!isset($this->_csv)) return $données;
		
		$d = [];
		$résidu = $this->_résidu;
		$this->_sépi = "\037"; // Séparateur Interne
		/*
		$sépExpr = preg_replace('#([|\#*?\[\]()])#', '\\\\\\1', $this->_sép);
		$sépExpr = '#""|"|'.$sépExpr.'#';
		*/
		foreach($données as $l)
		{
			/* À FAIRE: si on rencontre notre séparateur interne dans la chaîne, on reparcourt l'ensemble des données pour l'éjecter. Il faudra alors que _sépi soit persisté (rajouter un if(!isset avant le 037). */
			if(strpos($l, '"') !== false)
			{
				// preg_replace_callback() est trop lente. En bidouillant avec str_getcsv(), on divise par 5 le temps d'exécution.
				/*
				$l = preg_replace_callback($sépExpr, [ $this, '_remplCsv' ], $l);
				*/
				if($this->_nGuili) $l = '"'.$l;
				$champs = str_getcsv($l, $this->_sép);
				if(PHP_VERSION_ID < 830000 && in_array(substr($l, -2), [ $this->_sép.'"', '"' ]))
				{
					/* Cas particulier: en PHP < 8.3, si un champ n'est constitué que d'un guillemet (non fermé), str_getcsv renvoyait un caractère nul plutôt qu'une chaîne vide (#11982).
					 *                  or on va se retrouver dans ce cas si un de nos champs commence par un retour à la ligne ("champ1";"\nchamp2")
					 *                  notre fonction étant appelée ligne à ligne, la chaîne passée à str_getcsv sera "champ1";" avec ce guillemet isolé comme dernier champ.
					 */
					if(end($champs) === "\0")
					{
						array_pop($champs);
						$champs[] = '';
					}
				}
				// On détecte les guillemets ouvert sans refermeture au rendu d'un séparateur ajouté: est-il pris littéralement (intégré au dernier champ) ou comme séparateur (nouveau champ vide)?
				$this->_nGuili = count(str_getcsv($l.$this->_sép, $this->_sép)) == count($champs);
				$l = implode($this->_sépi, $champs);
			}
			else
				$l = strtr($l, [ $this->_sép => $this->_sépi ]);
			
			if(isset($résidu))
			{
				$l = $résidu."\n".$l;
				$résidu = null;
			}
			if($this->_nGuili)
			{
				$résidu = $l;
				continue;
			}
			
			if(substr($l, -1) == "\r") $l = substr($l, 0, -1);
			$d[] = $l;
		}
		
		$this->_données = [];
		$this->_résidu = $résidu;
		return $d;
	}
	
	public function _remplCsv($r)
	{
		switch($r[0])
		{
			case '""': return $this->_nGuili ? '"' : '';
			case '"': $this->_nGuili = !$this->_nGuili; return '';
			case $this->_sép: return $this->_nGuili ? $r[0] : $this->_sépi;
		}
	}
	
	protected $_bdd;
	protected $_table;
	protected $_champs;
	protected $_sép;
	protected $_null;
	protected $_csv;
	protected $_sauf = 0;
	protected $_données;
	protected $_résidu;
	protected $_sépi;
	protected $_nGuili = 0; // Nombre de guillemets internes.
}

class SqleurPreproCopyPousseurPg extends SqleurPreproCopyPousseur
{
	public function fin()
	{
		if(!count($données = $this->données())) return;
		
		$sép = isset($this->_sépi) ? $this->_sépi : $this->_sép;
		// Argh un CSV contenant des \ m'a fait rudement découvrir les cas aux limites de pgsqlCopyFromArray:
		// mais à vrai dire ce sens (non publié) de l'\ est bien pratique pour passer toute sorte de caractères spéciaux comme les retours à la ligne.
		$remplControblique = [ "\n" => '\n', "\r" => '\r', '\\' => '\\\\' ];
		// Pour copyFromArray(), null ne comprend pas la chaîne vide. On la remplace.
		if(($null = $this->_null) === '')
		{
			$null = chr(0x18); /* À FAIRE: vérifier que le caractère n'est présent dans aucune ligne. */
			$remplNull = '/(^|'.$sép.')('.$sép.'|$)/'; $parNull = '\1'.$null.'\2';
			foreach($données as $pos => $l)
				// Pour repérer les champs vides (entre deux séparateurs, ou entre le début de ligne et un sép, ou entre sép et fin de ligne, ou entre début et fin),
				// on est kif-kif en temps entre un preg_replace et un repérage en substr(strtr($sép.$l.$sép, [ $sép.$sép => $sép.$null.$sép ]), 1, -1).
				// En tout cas on fait deux passes pour remplacer les successifs (dans a;;;;;b, la première passe attrapera les couples de ;; pour donner a;N;;N;;b et la seconde prendra les restants pour donner a;N;N;N;N;b).
				$données[$pos] = preg_replace($remplNull, $parNull, preg_replace($remplNull, $parNull, strtr($l, $remplControblique)));
		}
		else
			// foreach plutôt qu'array_filter: pour 50000 lignes, 1,8 s en foreach, 3,4 s en array_filter.
		foreach($données as $pos => $l)
				$données[$pos] = strtr($l, $remplControblique);

		if
		(
			!$this->_bdd->pgsqlCopyFromArray
			(
				$this->_table,
				$données,
				$sép,
				isset($null) ? $null : 'NULL',
				isset($this->_champs) ? implode(',', $this->_champs) : null
			)
		)
		{
			$e = $this->_bdd->errorInfo();
			throw new Exception('copy: '.$e[2]);
		}
	}
}

?>
