<?php
/*
 * Copyright (c) 2013 Guillaume Outters
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

include_once 'SqleurCond.php';
include_once 'SqleurPreproExpr.php';

/* NOTE: problématiques du découpage
 * Le Sqleur joue deux rôles: préprocesseur (#include, #define, #if, etc.) et découpeur.
 * Une partie du travail de préprocession est le remplacement d'expressions (préalablement définies par #define).
 *  1. Il ne doit pas être fait prématurément
 *     Si TOTO vaut tutu, et qu'on lit un bloc:
 *       TOTO
 *       #define TOTO titi
 *       TOTO
 *     seul le premier TOTO doit être remplacé par tutu, le second ne pourra être remplacé (par titi) qu'une fois la nouvelle définition de TOTO passée.
 *     (a fortiori on ne remplacera pas TOTO par tutu dans le #define lui-même, sous peine d'aboutir à un "#define tutu titi" non désiré)
 *  2. Il ne doit pas être fait trop tard non plus
 *     Si dans l'exemple précédent on attend la fin de bloc pour effectuer les remplacements, le premier TOTO sera remplacé par titi aussi, ce qui est faux.
 *  3. Dans certains cas il ne doit pas être fait du tout
 *     Dans:
 *       #define TOTO tata
 *       #for TOTO in titi tutu
 *           drop table TOTO;
 *       #done
 *     Le #for, en arrivant au #done qui va déclencher la boucle, doit recevoir le TOTO brut, et non pas remplacé par tata.
 *  4. Il doit avoir été fait avant l'émission à la base
 *     De toute évidence sur le ; marqueur de fin d'instruction SQL, il faut que tous les remplacements aient été faits.
 *  5. Mais il ne doit pas attendre le ; pour être fait
 *     Sans quoi dans:
 *       #define micmac min(COL) as COL##_min, max(COL) as COL##_max
 *       select
 *       #define COL num
 *       micmac
 *       #define COL nom
 *       micmac
 *       from t;
 *     Renverra deux fois nom_min et nom_max, en omettant num_*.
 *  6. Si 5. traite le problème des remplacements dans une instruction, il existe aussi le problème de l'instruction dans le remplacement:
 *       #define micmac select min(COL) from TABLE; select max(COL) from TABLE;
 *     Après remplacement de micmac, un nouveau découpage doit être fait car il contient un ; et donc on doit émettre deux requêtes.
 *  7. Dans le nouveau découpage, on ne doit évidemment pas effectuer les remplacements (une fois suffit).
 *  8. Le remplacement ne peut être effectué arbitrairement sur un bloc à traiter
 *     Le bloc peut être issu d'une lecture d'un fichier par paquets (mettons de 4 Ko);
 *     avec pas de bol, notre terme à remplacer (mettons TITI) peut tomber pile à cheval entre deux blocs de 4 Ko;
 *     si notre fichier contient "… TITI TI|TI TITI …" (le | figurant la limite de bloc),
 *     il nous faut avoir préservé la première moitié du "TITI" découpé ("TI"), pour l'accoler avant le début du bloc suivant ("TI TITI"),
 *     afin de reconstituer un TITI qui pourra être remplacé.
 *  9. On ne peut cependant atermoyer éternellement
 *     Dans le cas extrême du COPY FROM STDIN, la suite du fichier peut faire plusieurs Mo avant de tomber sur un ; de fin ou un # de préprocesseur;
 *     ces Mo doivent avoir été remplacés au fur et à mesure, on ne va pas garder tout ça en mémoire.
 * 10. Attention aux doubles remplacements
 *     Dans l'exemple du 8., avec pour défs TITI=TOTO et TOTO=tutu, si l'on a pu remplacer le premier TITI par TOTO, donnant une chaîne résiduelle de "TOTO TI",
 *     l'accolage de "TI TITI" donne "TOTO TITI TITI", où l'on peut alors effectuer les remplacements.
 *     Mais il ne faut en aucun cas remplacer le premier TOTO par tutu, car il est issu d'un remplacement.
 *     La chaîne résiduelle doit donc être scindée en deux: "TOTO| TI", avec | figurant la fin du dernier remplacement;
 *     seul ce qui se trouve après est candidat à remplacement.
 * 11. Compteur de ligne
 *     Si le remplacement est multi-lignes, la numérotation des lignes dans le fichier source doit avoir été faite *avant* les remplacements.
 *     Une erreur Sqleur ou SQL doit être signalée avec le bon numéro de ligne d'origine.
 * 12. Prépros spéciaux et connaissance des requêtes
 *     Les prépros de #test travaillent généralement en interceptant "la prochaine requête".
 *     À cet effet il est nécessaire d'avoir, dès la préprocession, connaissance du découpage.
 *     Ou alors, si on veut proprement découper les étages, le prépro pourrait émettre une fausse requête, de manière à ce qu'elle soit interceptée par l'étage requête et traitée à ce moment.
 */

/* À FAIRE: état de découpe
 * Soit le SQL suivant:
 *   #define CONSTANTE 16384
 *   insert into toto values(CONSTANTE);
 *   select * from toto;
 * À l'heure actuelle nous avons 3 variables:
 * - _resteEnCours: chaîne lue mais non encore découpée (ex.: tout le pré-SQL précédent, brut, en un seul bloc)
 * - _requeteEnCours: chaîne lue et découpée mais non encore remplacée (ex.: découpée selon les points-virgules, donc "insert into toto values(CONSTANTE)")
 * - _requêteRemplacée: chaîne lue, découpée, et préprocessée (ex.: "insert into toto values(16384)")
 * N'étaient les remplacements, elles pourraient être vues comme de simples marqueurs de position sur un seul bloc qui serait la chaîne lue, complète, brute:
 * - un premier marqueur (P) "j'ai déjà découpé et préprocessé jusqu'ici"
 * - un second marqueur (D) "j'ai déjà juste découpé jusqu'ici"
 * Les choix d'implémentation font que, pour le bloc …(P)…(D)…:
 * - _requêteRemplacée = …(P)
 * - _requeteEnCours = …(P)…(D)
 * - _resteEnCours = (D)…
 * _requeteEnCours contient donc _requêteRemplacée, afin que les préprocesseurs qui souhaitent avoir une préversion de la chaîne résultante n'aient qu'à accéder à la variable, sans la concaténer à quoi que ce soit.
 * Cependant cela a pour inconvénient notable de devoir synchroniser _requêteRemplacée et _requeteEnCours: on ne peut juste passer une partie traitée du second au premier, il faut l'y dupliquer.
 * La solution presqu'élégante aurait été d'embarquer un caractère très spécial dans la chaîne (ex.: \001), permettant la concaténation sans se poser de question, et la mémorisation / restauration faciles (une seule variable), mais ceci complique la lecture (nécessité de faire sauter le \001; même si lorsque l'on veut jouer une requête normalement il est en toute fin de chaîne), et induit un risque si la requête SQL permet des données binaires (ex.: blob) contenant le caractère séparateur.
 * L'autre solution consiste donc à trimballer une position de marqueur conjointement au bloc mémoire accumulé (ce qui est fait actuellement; avoir une chaîne de caractères plutôt qu'un simple entier permet de vérifier que ce qu'on croit être le "déjà traité" est bien le prélude du "déjà découpé": tant on a peu confiance en notre capacité à balader les deux ensemble.
 * Pour améliorer la situation, il serait donc bon de passer par une seule variable état (facile à trimballer / recopier atomiquement, sans risque d'oubli), à deux membres. Voire trois si on y cale le _resteEnCours (ce qui a du sens car ce qui a été découpé de _resteEnCours est censé se retrouvé dans _requeteEnCours. Les deux sont liés).
 */
/* À FAIRE: suite de blocs typés
 * Le besoin d'embarquer des séparateurs de requête dans des #define, qui donnent lieu à un redécoupage après expansion (pour éviter de balancer une seule requête à points-virgules, mais bien comprendre que finalement il s'agit de plusieurs requêtes, cf. tests/definemulti.test.sql),
 * fait apparaître le souhait de distinguer blocs littéraux ('…' ou $$…$$) et blocs requête.
 * En effet dans les blocs littéraux, l'apparition de ; ne remet pas en cause la découpe. Pour des raisons d'optim, on pourrait donc souhaiter ne déclencher la seconde découpe qu'en cas de #define sur la requête.
 * Sauf que l'appliquerDéfs() se fait généralement sur la requête reconstituée: donc à ce moment on a perdu l'info de si le remplacement touche une partie littérale ou requête, donc on ne peut optimiser.
 * On pourrait donc imaginer qu'au lieu d'un _requeteEnCours et _requêteRemplacée, on ait une liste chaînée de blocs, typés littéraux ou requête.
 * Ce besoin pourrait rejoindre le précédent, "état de découpe".
 * /!\ Certains #define (notamment ceux par regex) peuvent avoir besoin de manger à cheval sur deux blocs, il faut donc aussi ruser pour appliquer les défs ainsi.
 * /!\ Encore pire, on peut avoir des #define n'ayant pas la même signification selon leur application: #define TRUC var_truc / #define TRUC(x) fonction_truc(x) / TRUC('coucou') -- Doit être interprété fonction_truc('coucou') mais risque d'être compris comme var_truc('coucou') si appliquerDéfs() traite séparément les blocs req et litt.
 *     Finalement pour ce risque, le fonctionnement par marquage de zone reste peut-être préférable.
 */

class Sqleur
{
	const MODE_BEGIN_END = 0x01;
	const MODE_COMM_MULTILIGNE = 0x02; // Transmet-on les commentaires /* comm */?
	const MODE_COMM_MONOLIGNE  = 0x04; // Transmet-on les commentaires -- comm?
	const MODE_COMM_TOUS       = 0x06; // MODE_COMM_MULTILIGNE|MODE_COMM_MONOLIGNE
	const MODE_SQLPLUS         = 0x08; // Vraie bouse qui ne sachant pas compter ses imbrications de begin, end, demande un / après les commandes qui lui font peur.
	
	// L'implémentation de détection des begin end est complexifiée par deux considérations Oracle:
	// - la nécessité de pousser _dans le SQL_ le ; suivant un end, _s'il est procédural_ (suivant un create function, et non dans le case end)
	//   Pousser au JDBC Oracle un begin end sans son ; est une erreur de syntaxe (PLS-00103).
	//   Pensant initialement que cela ne s'appliquait qu'aux blocs anonymes (sans create function, par exemple un simple begin exception end), je le voyais comme exigence de BEGIN_END_COMPLEXE;
	//   cependant cela s'avère faux (TOUS les begin end requièrent leur ; sous Oracle), ne justifiant pas la complexité.
	// - mais aussi: la déclaration de variables d'une fonction, au lieu de commencer dans un bloc declare comme dans d'autres dialectes, se fait directement après le as.
	// Pour cette raison on est _obligés_ de traiter le create function / procedure / package as / is comme un begin, et d'y recourir à notre complexité, car ce create function et le begin _partagent leur end_ (1 end pour deux départs). … Sauf que dans PostgreSQL, si le as est suivi d'un $$, le corps de fonction est littéral et non en bloc. … Sauf que le as (et le is, synonyme sous Oracle) ajoutent à la charge processeur, car (outre les as inclus dans une chaîne plus longue, "drop table rase") le as et le is se trouvent dans du "select id as ac_id" et "is not null".
	// La complexité ajoutée est cependant bien identifiée grâce à la constante suivante.
	const BEGIN_END_COMPLEXE = true;
	
	const FIN_SUITE = false;
	const FIN_FICHIER = 0; // /!\ == FIN_SUITE mais !== FIN_SUITE, pour compatibilité avec les usages if(!$laFinEstVraimentLaFin)
	const FIN_FIN = true;
	
	public $tailleBloc = 0x20000;
	
	/**
	 * L'éphéméride permet aux préprocesseurs d'insérer du SQL généré à faire jouer avant la prochaine instruction.
	 */
	/* À FAIRE: savoir les rattacher à l'instruction qui les a générées, à des fins de diagnostic. */
	public $éphéméride = [];
	
	public $_defs = array();
	
	public $_mode;
	public $_sortie;
	public $_requeteEnCours;
	public $_fichier;
	public $_ligne;
	public $_fonctions;
	public $motsChaînes;
	public $_boucles;
	public $_débouclages;
	public $_préprocesseurs;
	public $_chaîneEnCours;
	public $_chaineDerniereDecoupe;
	public $_resteEnCours;
	public $_queDuVent;
	public $_posAvant;
	public $_posAprès;
	public $_requêteRemplacée;
	/**
	 * La requête en cours de constitution comporte des ; introduits par remplacement d'un #define:
	 * elle doit être réanalysée pour vérifier qu'il ne s'agit pas désormais de 2 requêtes.
	 */
	public $_requêteÀRedécouper;
	public $_fonctionsInternes;
	public $terminaison;
	protected $_dernièreLigne;
	protected $_retour;
	protected $_retourDirect;
	protected $_conditions;
	protected $_dansChaîne;
	protected $_états;
	protected $_béguins;
	protected $_béguinsPotentiels;
	protected $_dernierBéguinBouclé;
	protected $_exprFonction;
	protected $IFS;
	protected $_conv;
	protected $_sortieActuelle;
	protected $_sortieAnticipée;
	protected $_aPonduEnAnticipé;
	
	/**
	 * Constructeur.
	 * 
	 * @param fonction $sortie Méthode prenant en paramètre une requête. Sera appelée pour chaque requête, au fur et à mesure qu'elles seront lues.
	 */
	public function __construct($sortie = null, $préprocesseurs = array())
	{
		if(Sqleur::BEGIN_END_COMPLEXE && !isset(Sqleur::$FINS['function'])) Sqleur::$FINS += Sqleur::$FINS_COMPLEXES;
		
		$this->avecDéfs(array());
		$this->_mode = Sqleur::MODE_COMM_TOUS | Sqleur::MODE_BEGIN_END; // SQLite et Oracle ont besoin de MODE_BEGIN_END, PostgreSQL >= 14 aussi: on le met d'office.
		$this->_fichier = null;
		$this->_ligne = null;
		$this->_dernièreLigne = null;
		$this->_boucles = array(); // Indique qu'une boucle est en cours de constitution (et d'exécution de son premier tour de boucle).
		$this->_débouclages = array(); // Indique qu'une boucle est en cours de restitution (second tour et suivants).
		$this->_fonctions = array();
		foreach(static::$FonctionsPréproc as $f)
		{
			$this->_fonctions[$f] = array($this, '_'.$f);
			$this->_fonctionsInternes[$f] = true;
		}
		
		if(($this->_retourDirect = !isset($sortie)))
		{
			$this->_sortie = array($this, '_accumule');
			$this->_retour = array();
		}
		else
			$this->_sortie = $sortie;
		
		$this->_préprocesseurs = array();
		foreach($préprocesseurs as $préprocesseur)
			$this->attacherPréprocesseur($préprocesseur);
	}
	
	public function attacherPréprocesseur($préprocesseur)
	{
		if(method_exists($préprocesseur, 'grefferÀ'))
			$préprocesseur->grefferÀ($this);
		else
		$préprocesseur->_sqleur = $this;
		$this->_préprocesseurs[] = $préprocesseur;
	}
	
	protected function _accumule($requete)
	{
		$this->_retour[] = $requete;
	}
	
	protected function _init($complète = true)
	{
		if($complète)
		$this->_conditions = array(); // Pile des conditions de préprocesseur.
		unset($this->_chaineDerniereDecoupe);
		unset($this->_requeteEnCours);
		unset($this->_requêteRemplacée);
		$this->_requêteÀRedécouper = false;
		unset($this->_resteEnCours);
		if($complète)
		$this->_dansChaîne = null;
	}
	
	/**
	 * Marque un arrêt dans la ponte après en avoir mémorisé l'état dans une SqleurCond.
	 * 
	 * @return SqleurCond Mémorisation de l'état de ponte, à réinjecter plus tard dans reprise().
	 */
	public function pause()
	{
		$mém = new SqleurMém($this);
		$this->jalon($mém);
		$mém->conditions = $this->_conditions;
		$mém->chaîneDernièreDécoupe = $this->_chaineDerniereDecoupe;
		$mém->resteEnCours = isset($this->_resteEnCours) ? $this->_resteEnCours : null;
		$mém->dansChaîne = $this->_dansChaîne;
		
		$this->_init(true);
		
		return $mém;
	}
	
	/**
	 * Enregistre l'état de la ponte dans une SqleurCond.
	 * (similaire pour les sorties, à mémoriserÉtat() qui elle travaille sur les entrées)
	 * 
	 * @param SqleurCond $condition Si fournie, l'état est inscrit dans ce condenant.
	 * 
	 * @return SqleurCond Mémorisation de l'état de ponte.
	 */
	public function jalon($condition = null)
	{
		if(!$condition) $condition = new SqleurCond($this, 1);
		
		$condition->requêteEnCours = $this->_requeteEnCours;
		$condition->requêteRemplacée = $this->_requêteRemplacée;
		$condition->requêteÀRedécouper = $this->_requêteÀRedécouper;
		$condition->défs = $this->_defs;
		
		return $condition;
	}
	
	public function reprise($mém)
	{
		$this->_requeteEnCours = $mém->requêteEnCours;
		$this->_requêteRemplacée = $mém->requêteRemplacée;
		$this->_requêteÀRedécouper = $mém->requêteÀRedécouper;
		$this->_defs = $mém->défs;
		
		if($mém instanceof SqleurMém)
		{
			$this->_conditions = $mém->conditions;
			$this->_chaineDerniereDecoupe = $mém->chaîneDernièreDécoupe;
			$this->_resteEnCours = $mém->resteEnCours;
			$this->_dansChaîne = $mém->dansChaîne;
		}
	}
	
	public function decoupeFichier($fichier)
	{
		$this->_init();
		return $this->_découpeFichier($fichier, true);
	}
	
	public function _découpeFichier($fichier, $laFinEstVraimentLaFin = Sqleur::FIN_FICHIER)
	{
		if(!file_exists($fichier))
			throw $this->exception($fichier.' inexistant');
		
		$this->mémoriserÉtat();
		try
		{
		$this->_fichier = $fichier;
		$f = fopen($fichier, 'r');
			$r = $this->_découpeFlux($f, $laFinEstVraimentLaFin);
		fclose($f);
			$this->restaurerÉtat();
		return $r;
		}
		catch(Exception $e)
		{
			$this->restaurerÉtat();
			throw $e;
		}
	}
	
	public function découpeIncise($incise, $brut = false)
	{
		$this->mémoriserÉtat(true); /* À FAIRE: $technique ou pas $technique? */
		try
		{
			if($brut)
				$this->_defs = [ 'stat' => [], 'dyn' => [] ]; // Seront de toute manière restaurées par restaurerÉtat().
			$r = $this->_decoupeBloc($incise, true);
			$défsÉcrites = $this->_defs;
			$this->restaurerÉtat($brut);
			// $brut vise à empêcher les définitions d'être _utilisées_, mais pas _définies_:
			// on exploite donc les définitions faites par l'incise.
			// Le principal (et seul?) exploitant de cette possibilité étant SqleurPreproDef.
			if($brut)
				foreach($défsÉcrites as $groupeDéfs => $défsGroupe)
					$this->_defs[$groupeDéfs] = $défsGroupe + $this->_defs[$groupeDéfs];
			return $r;
		}
		catch(Exception $e)
		{
			$this->restaurerÉtat($brut);
			throw $e;
		}
	}
	
	public function decoupeFlux($f)
	{
		$this->_init();
		return $this->_découpeFlux($f, true);
	}
	
	public function _découpeFlux($f, $laFinEstVraimentLaFin = false)
	{
		$nConditionsImbriquées = count($this->_conditions);
		$this->_ligne = 1;
		while(strlen($bloc = fread($f, $this->tailleBloc)))
			$this->_decoupeBloc($bloc, false);
		$r = $laFinEstVraimentLaFin !== Sqleur::FIN_SUITE
		   ? $this->_decoupeBloc('', $laFinEstVraimentLaFin)
		   : null
		;
		if(($nConditionsImbriquées -= count($this->_conditions)))
			throw $this->exception($nConditionsImbriquées > 0 ? $nConditionsImbriquées.' #endif sans #if' : (-$nConditionsImbriquées).' #if sans #endif');
		return $r;
	}
	
	public function decoupe($chaineRequetes)
	{
		$this->_init();
		return $this->_decoupeBloc($chaineRequetes);
	}
	
	const DANS_CHAÎNE_DÉBUT = 0;
	const DANS_CHAÎNE_FIN = 1;
	const DANS_CHAÎNE_CAUSE = 2;
	
	const CHAÎNE_COUPÉE = -1;
	const CHAÎNE_PASSE_LA_MAIN = 1; // Indique que la chaîne donne au prochain élément une chance de se jouer. La chaîne ayant pour critère de délivrance du jeton les mêmes que _decoupeBloc pour entrer dans l'élément, il y a de fortes chances pour qu'il soit consommé immédiatement; le seul cas de non-consommation étant si la découpe qui a sa chance, manque de bol, tombe sur un fragment incomplet (le bloc lu se termine avant que lui ait sa fin de découpe): dans ce cas, le jeton est préservé, et la découpe "hôte" pourra être retentée une fois le tampon regarni.
	const CHAÎNE_JETON_CONSOMMÉ = 2;
	
	static $FINS = array
	(
		// Ceux ouvrant un bloc, avec leur mot-clé de fin:
		'begin' => 'end',
		'case' => 'end',
		// Les autres:
		'end' => true,
		'end case' => true, // Pour Oracle, un case SQL finit en end mais un case PL/SQL en end case.
		// Les faux-amis (similaires à un "vrai" mot-clé, remontés en tant que tel afin que, mis sur pied d'égalité, on puisse décider duquel il s'agit):
		'begin transaction' => false,
		'end if' => false,
		'end loop' => false,
	);
	
	static $FINS_COMPLEXES = array
	(
		'function as' => 'end',
		'function' => true,
		'declare' => true,
		'as' => true,
	);
	
	protected function _ajouterBoutRequête($bout, $appliquerDéfs = true, $duVent = false, $numDernierArrêt = null)
	{
		if($appliquerDéfs)
		{
			isset($this->_requêteRemplacée) || $this->_requêteRemplacée = '';
			if($this->_requêteRemplacée == substr($this->_requeteEnCours, 0, $tDéjàRempl = strlen($this->_requêteRemplacée))) // Notre fiabilité laissant à douter, on s'assure que $this->_requêteRemplacée est bien le début de l'accumulateur.
			{
				$bout = substr($this->_requeteEnCours, $tDéjàRempl).$bout;
				$this->_requeteEnCours = $this->_requêteRemplacée;
			}
			$bout = $this->_appliquerDéfs($bout);
		}
		$this->_requeteEnCours .= $bout;
		if($this->_queDuVent && !$duVent && trim($bout) && !$this->dansUnSiÀLaTrappe())
			$this->_queDuVent = false;
		if($appliquerDéfs)
			$this->_requêteRemplacée = $this->_requeteEnCours;
		$this->_entérinerBéguins($numDernierArrêt);
		
		// Notre sortie accepte-t-elle de travailler en anticipé?
		
		/* À FAIRE: uniquement dans certaines conditions (dans une chaîne de caractères, hors boucle #for, bref seulement si on peut continuer sans devoir revenir à ce qu'on est en train de travailler). */
		if($this->_sortieActuelle != $this->_sortie)
		{
			// Si on a changé de sortie depuis la dernière fois, on recalcule.
			$this->_sortieAnticipée =
				isset($this->_sortie[0])
				&& is_object($this->_sortie[0])
				&& is_string($this->_sortie[1])
				&& method_exists($this->_sortie[0], $this->_sortie[1].'Partiel')
				? [ $this->_sortie[0], $this->_sortie[1].'Partiel' ]
				: null
			;
			$this->_sortieActuelle = $this->_sortie;
		}
		if($this->_sortieAnticipée)
		{
			if(($nÉcrits = call_user_func($this->_sortieAnticipée, $this->_requeteEnCours)))
			{
				$this->_requeteEnCours = substr($this->_requeteEnCours, $nÉcrits);
				if($appliquerDéfs)
					$this->_requêteRemplacée = substr($this->_requêteRemplacée, $nÉcrits);
				$this->_aPonduEnAnticipé = true;
			}
		}
	}
	
	protected function _decoupeBloc($chaîne, $laFinEstVraimentLaFin = true) { return $this->découperBloc($chaîne, $laFinEstVraimentLaFin); }
	public function découperBloc($chaine, $laFinEstVraimentLaFin = true)
	{
		if(isset($this->_resteEnCours))
		{
			$chaine = $this->_resteEnCours.$chaine;
			unset($this->_resteEnCours);
		}
		$this->_chaîneEnCours = $chaine;
		
		// Tous le code gérant cet enquiquinante suite ";\n+/\n*" sera marqué de l'étiquette DML (Découpe Multi-Lignes):
		// À FAIRE: DML dissocier $onEnFaitPlusPourSqlMoins du ; et ne vérifier leur séquence que dans le traitement DML? Là ça complique beaucoup de choses… Par contre en effet on gagne en perfs car on ne lit pas chaque / isolé, et on évite aussi de manger ceux de // ou /**/; sinon laisser l'expr comme ça, mais après preg_match_all traduire la suite en deux découpes successives. /!\ Bien traiter le cas où le ; était dans un bloc, et le \n/ dans le suivant. /!\ Attention aussi, là j'ai l'impression qu'on mange le / si on a un commentaire juste après le ;, de type ";//".
		$onEnFaitPlusPourSqlMoins = $this->_mode & Sqleur::MODE_SQLPLUS ? '(?:\s*\n\s*/(?:\n|$))?' : '';
		$expr = '[#\\\\\'"]|\\\\[\'"]|;'.$onEnFaitPlusPourSqlMoins.'|--|'."\n".'|/\*|\*/|\$[a-zA-Z0-9_]*\$';
		$opEx = ''; // OPtions sur l'EXpression.
		if($this->_mode & Sqleur::MODE_BEGIN_END)
		{
			// On repère non seulement les expressions entrant et sortant d'un bloc procédural,
			// mais aussi les faux-amis ("end" de "end loop" à ne pas confondre avec celui fermant un "begin").
			// N.B.: un contrôle sur le point-virgule sera fait par ailleurs (pour distinguer un "begin" de bloc procédural, de celui synonyme de "begin transaction" en PostgreSQL par exemple).
			$opEx .= 'i';
			$expr .= '|begin(?: transaction)?|case|end(?: if| loop| case)?';
			if(Sqleur::BEGIN_END_COMPLEXE)
			{
				$this->_exprFonction = '(?:create(?: or replace)? )?(?:package|procedure|function|trigger)'; // Dans un package, seul ce dernier, qui est premier, est précédé d'un create; les autres sont en "procedure machin is" sans create.
				$expr .= '|'.$this->_exprFonction.'|as|is|declare';
			}
		}
		preg_match_all("@$expr@$opEx", $chaine, $decoupes, PREG_OFFSET_CAPTURE);
		
		$taille = strlen($chaine);
		$decoupes = $decoupes[0];
		$n = count($decoupes);
		
		$dernierArret = 0;
		if(!isset($this->_chaineDerniereDecoupe))
		{
			$chaineDerniereDecoupe = $this->_chaineDerniereDecoupe = "\n"; // Le début de fichier, c'est équivalent à une fin de ligne avant le début de fichier.
			$dernierRetour = 0;
			$this->_béguins = array();
			$this->_béguinsPotentiels = array();
			// À FAIRE: fusionner les deux listes, avec un marqueur de "entériné ou non": là on jongle trop entre entérinés et temporaires.
		}
		else
		{
			$chaineDerniereDecoupe = $this->_chaineDerniereDecoupe;
			$dernierRetour = $chaineDerniereDecoupe == "\n" ? 0 : -1;
			// DML: Particularité: certaines $chaineDerniereDecoupe peuvent porter des retours à la ligne cachés; on restitue au mieux.
			switch(substr($chaineDerniereDecoupe, 0, 1))
			{
				case ';':
					$decoupes[-1] = array($chaineDerniereDecoupe, -strlen($chaineDerniereDecoupe));
					$chaineDerniereDecoupe = substr($chaineDerniereDecoupe, 0, 1);
					break;
			}
		}
		if(!isset($this->_requeteEnCours))
		{
			$this->_requeteEnCours = '';
			$this->_queDuVent = true;
			unset($this->_requêteRemplacée);
		}
		
		for($i = 0; $i < $n; ++$i)
		{
			// Normalisation "au premier caractère": pour la plupart de nos chaînes spéciales, le premier caractère est discriminant.
			// Les bouts qui sortent de cette simplification (ex.: mots-clés) pourront travailler sur la version longue dans $decoupes[$i][0].
			$chaineNouvelleDecoupe = substr($decoupes[$i][0], 0, 1);
			// Si on est dans une chaîne, même interrompue, on y retourne. Elle est seule à pouvoir décider de s'interrompre (soit pour fin de tampon, soit pour passage de relais temporaire au préprocesseur).
			if($this->_dansChaîne && $this->_dansChaîne[static::DANS_CHAÎNE_CAUSE] != static::CHAÎNE_PASSE_LA_MAIN && !$this->dansUnSiÀLaTrappe())
				$chaineNouvelleDecoupe = $this->_dansChaîne[static::DANS_CHAÎNE_DÉBUT];
			
			switch($chaineNouvelleDecoupe)
			{
				case ';':
					if($this->dansUnSiÀLaTrappe()) break;
					$this->_mangerBout($chaine, /*&*/ $dernierArret, $decoupes[$i][1], false, $i);
					$arrêtJusteAvant = $dernierArret;
					$dernierArret += strlen($decoupes[$i][0]);
					// DML: étant susceptibles de porter du \n, et $chaineDerniereDecoupe n'étant jamais comparée à simplement ';', on y entrepose la restitution exacte de ce qui nous a invoqués (plutôt que seulement le premier caractère).
					$nLignes = substr_count($chaineNouvelleDecoupe = $decoupes[$i][0], "\n");
					if(($this->_mode & Sqleur::MODE_BEGIN_END))
					{
						if(Sqleur::BEGIN_END_COMPLEXE)
						$this->_écarterFauxBéguins();
						if(count($this->_béguins) > 0) // Point-virgule à l'intérieur d'un begin, à la trigger SQLite: ce n'est pas une fin d'instruction.
						{
							$this->_ajouterBoutRequête($chaineNouvelleDecoupe, true, false, $i);
							$this->_ligne += $nLignes;
							break;
						}
						// Le ; après end (de langage procédural, et non pas dans un case end) a deux fonctions:
						// une littérale (complète textuellement l'end), l'autre de séparateur.
						// On ajoute donc sa fonction littérale (pour éviter l'erreur Oracle PLS-00103: end sans point-virgule).
						else if($this->_vientDeTerminerUnBlocProcédural($decoupes, $i))
							$this->_requeteEnCours .= ';';
					}
					$this->terminaison = $decoupes[$i][0];
					// On prend aussi dans la terminaison tous les retours à la ligne qui suivent, pour restituer le plus fidèlement possible.
					/* À FAIRE: prendre aussi les commentaires sur la même ligne ("requête; -- Ce commentaire est attaché à cette requête."). Mais là pour le moment ils font partie de la requête suivante. */
					if(preg_match("/^[ \n\r\t;]+/", substr($chaine, $decoupes[$i][1] + strlen($decoupes[$i][0])), $rEspace))
						$this->terminaison .= $rEspace[0];
					// Si on soupçonne en fin de bloc que la suite pourrait apporter un retour à la ligne qui nous est dû, on réclame cette suite histoire de pouvoir exercer notre droit de regard.
					if($decoupes[$i][1] + strlen($this->terminaison) == strlen($chaine) && $laFinEstVraimentLaFin === Sqleur::FIN_SUITE && !count($this->_débouclages))
					{
						$n = $i; // Hop, comme si on n'avait jamais vu ce point-virgule.
						$dernierArret = $arrêtJusteAvant;
						$chaineNouvelleDecoupe = $chaineDerniereDecoupe;
						break;
					}
					$this->_sors($this->_requeteEnCours);
					$this->terminaison = null;
					$this->_requeteEnCours = '';
					$this->_queDuVent = true; /* À FAIRE: le gérer aussi dans les conditions (empiler et dépiler). */
					unset($this->_requêteRemplacée);
					$this->_requêteÀRedécouper = false;
					unset($this->_dernierBéguinBouclé);
					$this->_ligne += $nLignes;
					break;
				case "\n":
					$dernierRetour = $decoupes[$i][1] + 1;
					++$this->_ligne;
					/* On pousse dès ici, pour bénéficier des remplacements de #define:
					 * - Pas de risque de "couper" une définition (le nom #definé ne peut contenir que du [a-zA-Z0-9_])
					 * - Mais un besoin de le faire, au cas où l'instruction suivante est un prépro qui re#define: le SQL qui nous précède doit avoir l'ancienne valeur.
					 */
					/* À FAIRE: optim: faire le remplacement sur toute suite contiguë de lignes banales (non interrompue par une instruction prépro), et non ligne par ligne. */
					$this->_mangerBout($chaine, /*&*/ $dernierArret, $dernierRetour, false, $i);
					break;
				case '#':
					if
					(
						($chaineDerniereDecoupe == "\n" && $dernierRetour == $decoupes[$i][1]) // Seulement en début de ligne.
						|| (isset($decoupes[$i - 1]) && preg_match("#/\n+$#", $decoupes[$i - 1][0]) && $decoupes[$i - 1][1] + strlen($decoupes[$i - 1][0]) == $decoupes[$i][1]) // … Avec le cas particulier du / SQL*Plus qui mange les \n qui le suivent. DML
					)
					{
						$j = $i;
						$ligne = $this->_ligne;
						while(++$i < $n && $decoupes[$i][0] != "\n")
							if($decoupes[$i][0] == '\\' && isset($decoupes[$i + 1]) && $decoupes[$i + 1][0] == "\n" && $decoupes[$i + 1][1] == $decoupes[$i][1] + 1)
							{
								++$i;
								++$this->_ligne;
							}
						// On ne traite que si on aperçoit l'horizon de notre fin de ligne. Dans le cas contraire, on prétend n'avoir jamais trouvé notre #, pour que le Sqleur nous fournisse un peu de rab jusqu'à avoir un bloc complet.
						if($i >= $n && !$laFinEstVraimentLaFin)
						{
							$i = $j;
							$this->_ligne = $ligne;
							$n = $i;
							$chaineNouvelleDecoupe = $chaineDerniereDecoupe;
							break;
						}
						$this->_ajouterBoutRequête(substr($chaine, $dernierArret, $decoupes[$j][1] - $dernierArret), true, false, $i);
							if($this->_dansChaîne)
								$this->_dansChaîne[static::DANS_CHAÎNE_CAUSE] = static::CHAÎNE_JETON_CONSOMMÉ;
							$dernierArret = $decoupes[$i][1];
							$blocPréprocesse = substr($chaine, $decoupes[$j][1], $decoupes[$i][1] - $decoupes[$j][1]);
							$this->_dernièreLigne = $this->_ligne - substr_count(ltrim($blocPréprocesse), "\n");
							$this->_posAvant = $decoupes[$j][1];
							$this->_posAprès = $decoupes[$i][1] + 1;
							$blocPréprocesse = preg_replace('#\\\\$#m', '', rtrim($blocPréprocesse));
							$this->_chaineDerniereDecoupe = $chaineDerniereDecoupe;
							/* Assurons-nous que les prépro qui voudront inspecter $this->_chaîneEnCours y trouveront bien le contenu de $chaine:
							 * si un de nos prépro a appelé un #include ou autre qui a appeler récursivement un découperBloc(), celui-ci aura modifié $this->_chaîneEnCours,
							 * mais en rendant la main le dépilage de la pile PHP fait que notre fonction retrouve automatiquement son $chaine,
							 * tandis que $this->_chaîneEnCours doit être restauré explicitement. */
							$this->_chaîneEnCours = $chaine;
							$this->_préprocesse($blocPréprocesse);
							$chaineDerniereDecoupe = $this->_chaineDerniereDecoupe;
							--$i; // Le \n devra être traité de façon standard au prochain tour de boucle (calcul du $dernierRetour; ne serait-ce que pour que si notre #if est suivi d'un #endif, celui-ci voie le \n qui le précède).
					}
					break;
				case '-':
				case '/':
					$this->_mangerCommentaire($chaine, $decoupes, $n, /*&*/ $i, /*&*/ $dernierArret, $laFinEstVraimentLaFin, $chaineNouvelleDecoupe == '-' ? Sqleur::MODE_COMM_MONOLIGNE : Sqleur::MODE_COMM_MULTILIGNE);
					break;
				case '"':
				case "'":
				case '$':
					if(!$this->dansUnSiÀLaTrappe())
					$this->_mangerChaîne($chaine, $decoupes, $n, /*&*/ $i, /*&*/ $dernierRetour, /*&*/ $chaineNouvelleDecoupe, /*&*/ $dernierArret, /*&*/ $nouvelArret);
					break;
				case '\\':
					break;
				default:
					if($this->dansUnSiÀLaTrappe()) break;
					// Les mots-clés.
					// Certains mots-clés changent de sens en fonction de leur complétude (ex.: "begin" (début de bloc, end attendu) / "begin transaction" (instruction isolée))
					// Si un des mots-clés pouvant aussi être début d'un autre mot-clé arrive en fin de bloc, on demande un complément d'information (lecture du paquet d'octets suivant pour nous assurer qu'il n'a pas une queue qui change sa sémantique).
					if(Sqleur::CHAÎNE_COUPÉE == $this->_motClé($chaine, $taille, $laFinEstVraimentLaFin, $decoupes, $dernierRetour, $dernierArret, $i))
					{
						$n = $i;
						$chaineNouvelleDecoupe = $chaineDerniereDecoupe;
					}
					else
						// Bon sinon la normalisation d'un mot-clé ça fait plusieurs caractères.
						$chaineNouvelleDecoupe = $decoupes[$i][0];
					break;
			}
			$chaineDerniereDecoupe = $chaineNouvelleDecoupe;
			
			$this->_effeuillerÉphéméride();
		}
		
			if(count($this->_boucles))
			{
				$ajoutCorpsDeBoucle = $laFinEstVraimentLaFin ? $chaine : substr($chaine, 0, $dernierArret);
				foreach($this->_boucles as $boucle)
					$boucle->corps .= $ajoutCorpsDeBoucle;
			}
			$this->_resteEnCours = substr($chaine, $dernierArret);
			$this->_chaineDerniereDecoupe = $chaineDerniereDecoupe;
			$this->_béguinsPotentiels = array(); // Tous les béguins identifiés mais non consommés se retrouvent dans le _resteEnCours et seront donc réidentifiés au tour de boucle suivant.
		if($laFinEstVraimentLaFin)
		{
			$this->_ajouterBoutRequête($this->_resteEnCours);
			$this->_sors($this->_requeteEnCours);
			$this->_init(false);
			if($this->_retourDirect)
		{
			$retour = $this->_retour;
			$this->_retour = array();
			return $retour;
			}
		}
	}
	
	protected function _mangerBout($chaîne, & $dernierArret, $jusquÀ, $duVent = false, $numDernierArrêt = null)
	{
		$this->_ajouterBoutRequête(substr($chaîne, $dernierArret, $jusquÀ - $dernierArret), true, $duVent, $numDernierArrêt);
		$dernierArret = $jusquÀ;
	}
	
	protected function _mangerChaîne($chaine, $decoupes, $n, & $i, & $dernierRetour, & $chaineNouvelleDecoupe, & $dernierArret, & $nouvelArret)
	{
		$chaîneType = $chaineNouvelleDecoupe;
		if($this->_dansChaîne) // On ne fait que reprendre une chaîne interrompue.
		{
			$fin = $this->_dansChaîne[static::DANS_CHAÎNE_FIN];
			$this->_dansChaîne = null;
			$débutIntérieur = 0; // Le marqueur qui nous fait entrer dans la chaîne étant déjà passé, nous sommes dès le départ à l'intérieur de la chaîne.
			// La boucle while qui suit, appelée en principe lors que le $i est le caractère d'entrée dans la chaîne, voudra passer outre ce caractère.
			// Si l'on est appelés déjà dans la chaîne (donc qu'$i n'est pas le guillemet), on place notre $i sur le guillemet (virtuel) précédant notre départ.
			--$i;
		}
		else // C'est la découpe courante qui nous fait entrer dans la chaîne
		{
			if(Sqleur::BEGIN_END_COMPLEXE)
			$this->_entreEnChaîne($chaine, $decoupes, $i);
			$fin = $decoupes[$i][0];
			$débutIntérieur = strlen($fin);
		}
		while(++$i < $n && $decoupes[$i][0] != $fin)
		{
			if($decoupes[$i][0] == "\n")
			{
				$dernierRetour = $decoupes[$i][1] + 1;
				++$this->_ligne;
			}
			// Les chaînes à dollars sont parsemables d'instructions préproc. Cela permet de définir des fonctions SQL avec des fragments dépendants du préproc.
			/* À FAIRE: détecter aussi si entre \n et # on n'a que des espaces / tabulations (et une option posée: en effet il ne faudrait pas qu'un # dans une chaîne soit interprété comme du prépro). */
			/* À FAIRE: les instructions prépro émettant un pseudo \n en fin d'instruction, devraient manger celui les introduisant plutôt que de le restituer. */
			else if($decoupes[$i][0] == '#'&& $chaineNouvelleDecoupe == '$' && $dernierRetour == $decoupes[$i][1])
			{
				$chaineNouvelleDecoupe = "\n"; // Notre tunnel a masqué tout ce qu'il s'est passé dans notre mangeage; exposons au moins la découpe de juste avant la sortie.
				--$i; // Le # lui-même ne rentre pas dans la chaîne.
				$this->_dansChaîne = array($chaîneType, $fin, static::CHAÎNE_PASSE_LA_MAIN); // Le prochain élément gagne une chance d'être joué pour lui-même. À lui de consommer (unset) le jeton dès qu'il a pris sa chance.
				break;
			}
		}
		if($i >= $n)
			$this->_dansChaîne = array($chaîneType, $fin, static::CHAÎNE_COUPÉE);
		// Ce qui a été parcouru ci-dessus est mis de côté.
		/* NOTE: interruption prématurée
		 * Dans le cas d'un marqueur de fin multi-caractères, si $i >= $n (autrement dit si l'on a atteint la fin du bloc lu avant d'avoir trouvé notre fin de chaîne), il est possible que la fin du bloc, manque de bol, tombât pile au milieu du marqueur de fin. Si c'est le cas, autrement dit si dans les derniers octets du bloc lu on trouve le premier caractère du marqueur de fin, on laisse ces derniers octets pour que le prochain bloc lu vienne s'y agréger et reconstituer le marqueur de fin complet.
		 * On s'assure aussi qu'il ne chevauche pas le marqueur de début: il serait malvenu que dans la chaîne $marqueur$marqueur$marqueur$ (équivalente en SQL à 'marqueur'), la fin de bloc tombant au milieu (donc |$marqueur$mar|queur$marqueur$|), prenant le $ fermant du premier $marqueur$ initial pour l'ouvrant potentiel du $marqueur$ final, on le garde de côté, ce qui serait équivalent à avoir lu |$marqueur$| puis |($mar)queur$marqueur$|, autrement dit $marqueur$$marqueur$marqueur$.
		 */
		$j = $i < $n ? $i : $i - 1;
		$nouvelArret = $j >= 0 ? $decoupes[$j][1] + strlen($decoupes[$j][0]) : 0;
		$fragment = substr($chaine, $dernierArret, $nouvelArret - $dernierArret);
		if
		(
			$i >= $n && strlen($fin) > 1
			&& ($fragmentSaufMarqueurEntrée = substr($fragment, $débutIntérieur))
			&& ($posDébutMarqueurFin = strpos($fragmentSaufMarqueurEntrée, substr($fin, 0, 1), max(0, strlen($fragmentSaufMarqueurEntrée) - (strlen($fin) - 1)))) !== false // On cherche les (strlen($fin) - 1) caractères, car si on cherchait dans les strlen($fin) derniers (et qu'on le trouvait), cela voudrait dire qu'on aurait le marqueur de fin en entier, qui aurait été détecté à la découpe.
		)
		{
			$nCarsÀRéserver = strlen($fragmentSaufMarqueurEntrée) - $posDébutMarqueurFin;
			$nouvelArret -= $nCarsÀRéserver;
			$fragment = substr($fragment, 0, -$nCarsÀRéserver);
		}
		/* NOTE: ajout avec ou sans remplacement
		 * Deux cas de figure à gérer totalement différemment:
		 * 1. On ne coupe *surtout pas* après chaque bloc chaîne, pour éviter de couper un #define à paramètres.
		 * Ex.:
		 *   #define MACRO(x, y) …
		 *   MACRO('a', 'b');
		 * Si on effectue les remplacements à chaque fin de chaîne, ils seront appliqués à "MACRO('a'" puis ", 'b'", et enfin à ");" (remplacement de fin de requête).
		 * La macro n'aura alors pas moyen de s'appliquer (il lui faut repérer ses parenthèses ouvrante et fermante dans le même bloc).
		 # 2. On peut couper aux retours à la ligne, et on *doit* couper quand ils incluent des #define, des #for:
		 #    - les macros sont censées être monoligne. En fin de ligne on ne risque donc pas de couper quoi que ce soit.
		 #    - le #define, et surtout le #for, change la valeur d'une variable avant / après (ou à chaque tour de boucle).
		 #      Si on attend la fin pour effectuer le remplacement, on utilise la *dernière* valeur de la variable et non celle au moment du parcours.
		 #      Pour le #define à la rigueur on sait que le prépro _ajouterBoutRequête(true).
		 #      Pour le #for on ajoute un blindage en début d'ajouterDéfs().
		 #    - le #copy sera optimisable s'il peut pousser ligne à ligne (donc si à chaque fin de ligne elle est prête, remplacements inclus).
		 */
		$this->_ajouterBoutRequête($fragment, false, false, $i);
		$dernierArret = $nouvelArret;
	}
	
	protected function _mangerCommentaire($chaîne, $découpes, $n, & $i, & $dernierArrêt, $laFinEstVraimentLaFin, $mode)
	{
		/* À FAIRE?: en mode /, pour décharger la mémoire, voir si on ne peut pas passer par un traitement type "chaînes" capable de calculer un _resteEnCours minimal. */
		
		switch($mode)
		{
			case Sqleur::MODE_COMM_MONOLIGNE:  $borne = "\n"; $etDélim = false; break;
			case Sqleur::MODE_COMM_MULTILIGNE: $borne = "*/"; $etDélim = true; break;
		}
		
		$this->_mangerBout($chaîne, /*&*/ $dernierArrêt, $découpes[$i][1], false, $i);
		
		while(++$i < $n && $découpes[$i][0] != $borne)
			if($découpes[$i][0] == "\n") // Implicitement: && $mode != '-', car en ce cas, la condition d'arrêt nous a déjà fait sortir.
				++$this->_ligne;
		if($i < $n || $laFinEstVraimentLaFin) // Seconde condition: si on arrive en bout de truc, l'EOF clot notre commentaire.
		{
			$arrêt = $i >= $n ? strlen($chaîne) : $découpes[$i][1] + ($tÉpilogue = $etDélim ? strlen($découpes[$i][0]) : 0);
			if($this->_mode & $mode) // Si le mode du Sqleur demande de sortir aussi ce type de commentaire, on s'exécute.
				$this->_mangerBout($chaîne, /*&*/ $dernierArrêt, $arrêt, true, $i);
			else // Sinon on ne fait qu'avancer le curseur sans signaler le commentaire lui-même.
				$dernierArrêt = $arrêt;
			if($mode == Sqleur::MODE_COMM_MONOLIGNE && $i < $n)
				--$i; // Le \n devra être traité de façon standard au prochain tour de boucle (calcul du $dernierRetour).
		}
	}
	
	protected function _sors($requete, $brut = false, $appliquerDéfs = false, $interne = false)
	{
		$this->_vérifierBéguins();
		
		/* À FAIRE: le calcul qui suit est faux si $requete a subi un remplacement de _defs où le remplacement faisait plus d'une ligne. */
		$this->_dernièreLigne = $this->_ligne - substr_count(ltrim($requete), "\n");
		if($appliquerDéfs)
			$requete = $this->_appliquerDéfs($requete);
		$this->_requeteEnCours = '';
		unset($this->_requêteRemplacée);
		if(($t1 = strlen($r1 = rtrim($requete))) < ($t0 = strlen($requete)) && isset($this->terminaison))
			$this->terminaison = substr($requete, $t1 - $t0).$this->terminaison;
		if((strlen($requete = ltrim($r1)) && !$this->_queDuVent) || $this->_aPonduEnAnticipé)
		{
			$this->_aPonduEnAnticipé = null;
			if($this->_requêteÀRedécouper)
			{
				$this->_requêteÀRedécouper = false;
				// La requête est à relire brute (découpeIncise(…, true)), sans reremplacement:
				// - pour perfs
				// - pour exactitude si un #define inclut l'expression littérale qu'il surcharge
				//   ex.: #define ma_table schéma.ma_table résulterait en des schéma.schéma.ma_table en cas de double remplacement
				// - pour éviter les boucles infinies
				//   dans le cas extrême cumulant l'auto-surcharge comme ci-dessus + un ; dans le remplacement, qui redéclenche le _requêteÀRedécouper
				$r = $this->découpeIncise($requete.(isset($this->terminaison) ? $this->terminaison : ''), true);
				return $r;
			}
			
			if(isset($this->_conv))
				$requete = call_user_func($this->_conv, $requete);
			$sortie = $this->_sortie;
			$paramsSortir = [ $requete, false, $interne ];
			// Extraction des paramètres supplémentaires dans une sortie de type [ <objet>, <méthode>, <params supplémentaires> ].
			if(is_array($sortie))
				$paramsSortir = array_merge($paramsSortir, array_splice($sortie, 2));
			
			return call_user_func_array($sortie, $paramsSortir);
		}
	}
	
	// À FAIRE: possibilité de demander la "vraie" sortie. Mais pas facile, car un certain nombre de préprocesseurs peuvent la court-circuiter.
	public function exécuter($req, $appliquerDéfs = false, $interne = false)
	{
		return $this->_sors($req, true, $appliquerDéfs, $interne);
	}
	
	public function dansUnSiÀLaTrappe()
	{
		return is_array($this->_sortie) && is_string($this->_sortie[1]) && $this->_sortie[1] == 'sortirContenuIfFalse';
	}
	
	public function sortirContenuIfFalse($contenu)
	{
	}
	
	protected function _cond($motClé, $cond)
	{
		$boucle = false;
		switch($motClé)
		{
			case '#while':
				$boucle = true;
				break;
			case '#for':
				if($this->dansUnSiÀLaTrappe())
				{
					$cond = '0';
					break;
				}
				$boucle = true;
				$_ = '\s\r\n';
				$cond = trim($cond);
				if(!preg_match("/^((?:[^$_,]+|[$_]*,[$_]*)+)[$_]+in[$_]+/", $cond, /*&*/ $rCond))
					throw $this->exception('#for <var> in <val> <val>');
				$var = explode(',', preg_replace("/[$_]*,[$_]*/", ',', $rCond[1]));
				$cond = substr($cond, strlen($rCond[0]));
				$cond = $this->calculerExpr($cond, true, true, count($var));
				array_unshift($cond, $var);
				break;
		}
		return new SqleurCond($this, $cond, $boucle);
	}
	
	protected function _préprocesse($directive)
	{
		$posEspace = strpos($directive, ' ');
		$motCle = $posEspace === false ? $directive : substr($directive, 0, $posEspace);
		switch($motCle)
		{
			case '#ifdef':
			case '#ifndef':
			case '#elifdef':
			case '#elifndef':
				// Les composites sont transcrits en leur équivalent.
				$texteCondition = $posEspace === false ? '' : substr($directive, $posEspace + 1);
				$texteCondition = preg_replace('#/\*.*\*/#', '', $texteCondition); /* À FAIRE: en fait ça on devrait le proposer en standard à toutes les instructions prépro, non? */
				$texteCondition = 'defined('.$texteCondition.')';
				if(substr($motCle, ($posEspace = strpos($motCle, 'def')) - 1, 1) == 'n')
				{
					--$posEspace;
					$texteCondition = '!'.$texteCondition;
				}
				$motCle = substr($motCle, 0, $posEspace);
				$directive = $motCle.' '.$texteCondition;
				/* Et pas de break, on continue avec notre motCle recomposé. */
			case '#else':
			case '#elif':
			case '#while':
			case '#for':
			case '#if':
				$texteCondition = $posEspace === false ? '' : substr($directive, $posEspace);
				$pointDEntrée = in_array($motCle, array('#if', '#while', '#for'));
				$condition = $pointDEntrée ? $this->_cond($motCle, $texteCondition) : array_pop($this->_conditions);
				if(!$condition)
					throw $this->exception('#else sans #if');
				// Inutile de recalculer tous les #if imbriqués sous un #if 0.
				if($pointDEntrée && $this->dansUnSiÀLaTrappe())
					$condition->déjàFaite = true;
				// Si pas déjà fait, et que la condition est avérée.
				if
				(
					!$condition->déjàFaite
					&&
					(
						$motCle == '#else' // Si l'on atteint un #else dont la condition n'est pas déjà traitée, c'est qu'on rentre dans le #else.
						|| (in_array($motCle, array('#elif')) && ($condition->cond = $texteCondition) && false) // Pour un #elif, nouvelle condition. Un petit false pour être sûrs de tester la ligne suivante.
						|| $condition->avérée()
					)
				)
				{
					$this->_sortie = $condition->sortie;
					$this->reprise($condition);
					$condition->enCours(true);
					$condition->déjàFaite = true;
				}
				else
				{
					$this->_sortie = array($this, 'sortirContenuIfFalse');
					if($condition->enCours) // Si on clôt l'en-cours.
					{
						$this->jalon($condition); // On mémorise dans la condition.
						$condition->enCours(false);
					}
				}
				$this->_conditions[] = $condition;
				return;
			case '#done':
			case '#endif':
				$condition = array_pop($this->_conditions);
				if(!$condition)
					throw $this->exception('#endif sans #if');
				if(!$condition->enCours) // Si le dernier bloc traité (#if ou #else) était à ignorer,
					$this->reprise($condition);
				$condition->enCours(false);
				$this->_sortie = $condition->sortie;
				return;
		}
		if(!$this->dansUnSiÀLaTrappe())
		{
			foreach($this->_préprocesseurs as $préproc)
				// Les préprocesseurs désirant modifier la requête en cours de constitution doivent désormais exploiter $this->_requeteEnCours.
				if($préproc->préprocesse($motCle, $directive) !== false)
					return;
			
			switch($motCle)
			{
			case '#encoding':
				$encodage = trim(substr($directive, $posEspace));
				if(in_array(preg_replace('/[^a-z0-9]/', '', strtolower($encodage)), array('', 'utf8')))
					unset($this->_conv);
				else
					$this->_conv = function($ligne) use($encodage) { return iconv($encodage, 'utf-8', $ligne); };
				break;
				default:
					fprintf(STDERR, "[33m# Expression préprocesseur non traitée: $directive[0m\n");
					break;
			}
		}
	}
	
	protected function _effeuillerÉphéméride()
	{
		while(($feuille = array_shift($this->éphéméride)))
		{
			if(is_array($feuille) && count($feuille) > 2)
				$params = array_splice($feuille, 2);
			else
				$params = [];
			return call_user_func_array($feuille, $params);
		}
	}
	
	/*- États ----------------------------------------------------------------*/
	
	const ÉTAT_TECHNIQUE = 5;
	
	public function mémoriserÉtat($technique = false)
	{
		$this->_états[] = array
		(
			$this->_defs,
			isset($this->_conv) ? $this->_conv : null,
			$this->_fichier,
			$this->_ligne,
			$this->_dernièreLigne,
			$technique,
			$this->_boucles,
			isset($this->_resteEnCours) ? $this->_resteEnCours : null,
		);
		// Les boucles sont locales à un niveau d'inclusion.
		$this->_boucles = array();
	}
	
	public function restaurerÉtat($avecDéfs = false)
	{
		/* À FAIRE: gérer les instructions multi-fichiers.
		 * À l'heure actuelle sur changement de fichier tout est restauré (_resteEnCours, etc.),
		 * donc une instruction *ne peut pas* commencer en fin de fichier inclus et continuer en suite de fichier incluant par exemple
		 * (sauf dans une chaîne de caractères, où le contenu inclus s'assucumule avec ce qu'on avait déjà, avant d'être sorti; tandis que sur des instructions, on a parfois besoin de revenir en arrière (ex.: voir où se situait le dernier ;), or la frontière de fichier empêche cela).
		 * Idéalement la constante FIN_FICHIER n'existerait pas (une suite incluants / inclus serait vue comme un seul long fichier).
		 */
		list
		(
			$défs,
			$this->_conv,
			$this->_fichier,
			$this->_ligne,
			$this->_dernièreLigne,
			$technique,
			$this->_boucles,
			$this->_resteEnCours,
		) = array_pop($this->_états);
		if ($avecDéfs)
			$this->_defs = $défs;
	}
	
	public function pileDAppels()
	{
		$r = array();
		
		$this->mémoriserÉtat();
		foreach($this->_états as $état)
			if(isset($état[4]) && !$état[Sqleur::ÉTAT_TECHNIQUE]) // Si on n'a pas de ligne, c'est qu'on est à l'initialisation, avant même l'entrée dans du SQL. Inutile d'en parler.
			array_unshift($r, array('file' => $état[2], 'line' => $état[4]));
		$this->restaurerÉtat();
		
		return $r;
	}
	
	public function exception($truc)
	{
		if(is_object($truc))
		{
			$classe = get_class($truc);
			$message = $truc->getMessage();
			$code = $truc->getCode();
			$ex = $truc;
		}
		else
		{
			$classe = null;
			$message = $truc;
			$ex = null;
		}
		
		// PHP empêchant de définir sa propre trace sur les exceptions, on la glisse dans le message.
		$messagePile = '';
		$pile = $this->pileDAppels();
		foreach($pile as $endroit)
			$messagePile .= "\n\t".$endroit['file'].':'.$endroit['line'];
		$message .= $messagePile;
		
		switch($classe)
		{
			case 'PHPUnit_Framework_ExpectationFailedException':
				return new $classe($message, $ex->getComparisonFailure(), $ex->getPrevious());
		}
		return new Exception($message, isset($ex) ? $ex->getCode() : 0, $ex);
	}
	
	/*- Remplacements --------------------------------------------------------*/
	
	public function avecDefinitions($défs) { return $this->avecDéfs($défs); }
	public function avecDéfs($défs)
	{
		$this->_defs = array('stat' => array(), 'dyn' => array());
		return $this->ajouterDéfs($défs);
	}
	
	public function ajouterDéfs($défs)
	{
		// Si on a encore un bout de truc accumulé non remplacé, on lui applique les anciennes définitions.
		if(isset($this->_requeteEnCours))
			$this->_ajouterBoutRequête('', true, true);
		
		foreach($this->_defs as & $ptrEnsembleDéfs)
			$ptrEnsembleDéfs = array_diff_key($ptrEnsembleDéfs, $défs);
		foreach($défs as $id => $contenu)
		{
			if(!isset($contenu)) continue;
			$type = is_string($contenu) || is_numeric($contenu) || !is_callable($contenu) ? 'stat' : 'dyn';
			$this->_defs[$type][$id] = $contenu;
		}
		unset($this->_defs['statr']); // Cache pour remplacements textuels, à recalculer puisque stat a bougé.
	}
	
	protected function _appliquerDéfs($chaîne) { return $this->appliquerDéfs($chaîne); }
	public function appliquerDéfs($chaîne)
	{
		/* À FAIRE: adapter le calcul des lignes aux lignes originales (un remplacement peut contenir un multi-lignes). */
		if(is_array($chaîne)) $chaîne = $chaîne[0];
		// La séparation statiques / dynamiques nous oblige à les passer dans un ordre différent de l'initial (qui mêlait statiques et dynamiques).
		// On choisit les dynamiques d'abord, car, plus complexes, certaines de leurs parties peuvent être surchargées par des statiques.
		foreach($this->_defs['dyn'] as $expr => $rempl)
			if(($chaîne2 = preg_replace_callback($expr, $rempl, $chaîne)) !== $chaîne)
			{
				if(!$this->_dansChaîne && substr_count($chaîne2, ';') != substr_count($chaîne, ';'))
					$this->_requêteÀRedécouper = true;
				$chaîne = $chaîne2;
			}
		if(!isset($this->_defs['statr']) || $this->_defs['IFS'][''] != $this->IFS)
		{
			if(!isset($this->IFS))
				$this->IFS = ' ';
			/* NOTE: $this->_defs['IFS']['']
			 * Pour que l'IFS soit entreposé conjointement au statr qu'il a produit (histoire de sauter en même temps, qu'on ne garde pas un IFS décorrélé de son statr),
			 * on le met dans _defs (qui saute en tout ou rien).
			 * Cependant celui-ci doit être un tableau de tableaux, donc notre IFS s'adapte.
			 */
			$this->_defs['IFS'][''] = $this->IFS;
			$this->_defs['statr'] = array();
			foreach($this->_defs['stat'] as $clé => $val)
				$this->_defs['statr'][$clé] = is_array($val) ? implode($this->IFS, $val) : $val;
		}
		if(($chaîne2 = strtr($chaîne, $this->_defs['statr'])) !== $chaîne)
		{
			if(!$this->_dansChaîne && substr_count($chaîne2, ';') != substr_count($chaîne, ';'))
				$this->_requêteÀRedécouper = true;
			$chaîne = $chaîne2;
		}
		return $chaîne;
	}
	
	public function _defined($nomVar)
	{
		return array_key_exists($nomVar, $this->_defs['stat']);
	}
	
	public function _concat($params)
	{
		$args = func_get_args();
		return implode('', $args);
	}
	
	public function _split($quoi, $parQuoi)
	{
		return explode($parQuoi, $quoi);
	}
	
	public function _replace($params)
	{
		$args = func_get_args();
		if($args[1] instanceof NœudPrepro && $args[1]->t == '/')
			return preg_replace($args[1]->f, $args[2], $args[0]);
		return strtr($args[0], [ $args[1] => $args[2] ]);
	}
	
	public function _getenv($param)
	{
		return getenv($param);
	}
	
	/*- Expressions du préprocesseur -----------------------------------------*/
	
	protected function _calculerPrepro($expr) { return $this->calculerExpr($expr); }
	/**
	 * Calcule une expression préprocesseur.
	 * 
	 * @param string $expr Expression textuelle.
	 * @param boolean $multi Autorisée à renvoyer un tableau de résultats. Si false, une exception est levée lorsque l'expression résulte en une suite d'éléments plutôt qu'un résultat unique.
	 * @param boolean $motsChaînes Si false, les mots sans guillemets doivent correpondre à une définition. Si true, une suite de caractères non entourée de guillemets sera cherchée comme définition, à défaut sera renvoyée telle quelle.
	 *                Si null, est utilisée l'éventuelle $this->motsChaîne.
	 * @param char $exécMultiRés Susceptible de renvoyer plusieurs résultats.
	 *                           Si non défini, un `select` renvoyant deux résultats provoque une erreur.
	 *                           Si chaîne de caractères, les deux résultats sont concaténés par $exécMultiRés pour être passés à la suite du traitement.
	 *                           Si entier ou true, le résultat est renvoyé sous forme de tableau (et $exécMultiRés donne le nombre de colonnes attendues).
	 * 
	 * @return string
	 */
	public function calculerExpr($expr, $multi = false, $motsChaînes = null, $exécMultiRés = null)
	{
		$e = new SqleurPreproExpr();
		$anciensMotsChaînes = isset($this->motsChaînes) ? $this->motsChaînes : null;
		if(isset($motsChaînes))
		$this->motsChaînes = $motsChaînes;
		$r = $e->calculer($expr, $this, $multi, $exécMultiRés);
		$this->motsChaînes = $anciensMotsChaînes;
		return $r;
	}
	
	public static $FonctionsPréproc = array
	(
		'defined',
		'concat',
		'split',
		'replace',
		'getenv',
	);
	
	/*- Intestins ------------------------------------------------------------*/
	
	/**
	 * Interrompt les processus lorsque qu'une chaîne intervient comme un pavé dans la mare.
	 */
	protected function _entreEnChaîne($fragment, $découpes, $i)
	{
		/*- Corps de fonction "mode chaîne" -*/
		/* PostgreSQL permet de définir les corps de fonction sous deux formes:
		 * - create function as $$ begin coucou; end; $$
		 * - "create function as begin coucou; end;
		 * Le premier mode est reposant pour l'analyseur (on passe toute la chaîne sans se poser de question à l'interpréteur SQL),
		 * le second demande une analyse d'imbrication des begin … end pour savoir quel end signale la fin de fonction.
		 */
		
		if($this->_mode & Sqleur::MODE_BEGIN_END)
			if
			(
				($ptrBéguin = /*&*/ $this->_ptrDernierBéguin())
				&& $ptrBéguin[0] == 'function as'
			)
			{
				// Recherche de la dernière découpe significative avant notre entrée en chaîne.
				for($j = $i; isset($découpes[--$j]) && !trim($découpes[$j][0]);) {}
				// Correspond-elle à notre introducteur de begin ("as" dans "create function … as begin")?
				if
				(
					isset($découpes[$j])
					&& substr($ptrBéguin[1], -strlen($découpes[$j][0])) == $découpes[$j][0]
					// Et précède-t-elle immédiatement notre chaîne?
					&&
					(
						($posDD = $découpes[$j][1] + strlen($découpes[$j][0])) == $découpes[$i][1]
						|| ($posDD >= 0 && !trim(substr($fragment, $posDD, $découpes[$i][1] - $posDD)))
					)
				)
					// Alors nous sommes une chaîne juste derrière le "as",
					// donc le begin (et son end) sera à l'_intérieur_ de la chaîne,
					// et donc le "as" n'a plus à se préoccuper de trouver l'end correspondant;
					// on le fait sauter des "en attente":
					// array_splice plutôt qu'unset, qui ne libère pas l'indice et laissera donc un tableau à trous lors du prochain [] =.
					if(count($this->_béguinsPotentiels))
						array_splice($this->_béguinsPotentiels, -1);
					else
						array_splice($this->_béguins, -1);
			}
	}
	
	/**
	 * Analyse les mots-clés SQL qui, dans certaines situations, peuvent indiquer un bloc dans lequel le point-virgule n'est pas fermant.
	 * Le cas échéant, ajoute le mot-clé à la pile de décompte des niveaux.
	 */
	protected function _motClé($chaîne, $taille, $laFinEstVraimentLaFin, $découpes, $dernierRetour, $dernierArrêt, $i)
	{
		$motClé = strtolower($découpes[$i][0]);
		// Un synonyme PostgreSQL prêtant à confusion.
		if($motClé == 'begin' && isset($découpes[$i + 1]) && $découpes[$i + 1][1] == $découpes[$i][1] + strlen($motClé) && $découpes[$i + 1][0] == ';')
			$motClé = 'begin transaction';
		if(Sqleur::BEGIN_END_COMPLEXE)
		{
		// Pour Oracle, les "create quelque chose as" sont des pré-begin (mais on ne doit pas attendre le begin pour prendre littéralement les ; car on peut avoir du "create … as ma_var integer; begin …; end;" (le ; avant le begin fait partie du bloc, et non pas sépare une instruction "create" d'une "begin")).
		if(preg_match('#^'.$this->_exprFonction.'$#', $motClé))
			$motClé = 'function';
		if($motClé == 'is')
			$motClé = 'as';
		// À FAIRE: uniquement si pas de ; entre le create et le as! (faire le tri lors d'un ;)
		}
		
		if(!isset(Sqleur::$FINS[$motClé]))
			throw new Exception("Bloc de découpe inattendu $motClé");
		// Les faux-amis sont les end quelque chose, qu'on ne gère pas ainsi que leur balise de démarrage.
		if(!Sqleur::$FINS[$motClé])
			return;
		
		// Attention aux mots-clés en limite de bloc de lecture, qui peuvent en cacher un autre;
		// mieux vaut alors sortir, et ne revenir qu'une fois assurés que rien ne le suit qui en ferait changer le sens (ex.: begin / begin transaction),
		// et inversement que nous ne sommes pas utiles au mot-clé qui nous suivra (ex.: as est content de savoir qu'il suit un creation function plutôt qu'un select colonne).
		// À FAIRE: en fait non pas le dernier, mais "le dernier après avoir écarté les lignes vides". En effet parfois un ; serait bien aise de trouver un end devant lui; s'ils ne sont séparés que par une limite de bloc ça va, mais si en plus s'ajoutent des \n, alors la clause suivante se satisfait du \n comme successeur au end et exploite ce dernier avant de le poubelliser: le ; ne le retrouvera plus.
		// /!\ La ligne suivante est un raccourci, dans le cas le plus courant, pour sortir plus rapidement;
		//     cependant dans d'autres cas (ex.: "case … histo … | … end" dont le histo contient un is vu ĉ une découpe, m̂ si + tard il sera écarté),
		//     nous ne sommes pas dernière découpe, et donc seul le gros if derrière assurera le contrôle correct.
		if($i == count($découpes) - 1 && !$laFinEstVraimentLaFin)
					// N.B.: fait double emploi avec le gros if() plus bas. Mais c'est plus prudent.
					return Sqleur::CHAÎNE_COUPÉE;
		
		if
		(
			// Est-on sûr de n'avoir rien avant?
			($découpes[$i][1] == $dernierArrêt || $découpes[$i][1] == $dernierRetour || $this->délimiteur(substr($chaîne, $découpes[$i][1] - 1, 1)))
			&& // Ni rien après?
			(
				($découpes[$i][1] + strlen($découpes[$i][0]) == $taille && $laFinEstVraimentLaFin)
				|| $this->délimiteur(substr($chaîne, $découpes[$i][1] + strlen($découpes[$i][0]), 1))
			)
		)
		{
			if(Sqleur::BEGIN_END_COMPLEXE)
			{
			// Cas particulier du 'as' qui se combine avec un 'function' pour donner un nouveau mot-clé,
			// lorsque rien ne s'interpose entre eux (pas de begin entre le function et le as, pas de point-virgule, etc.).
			if($motClé == 'as')
			{
				if(($ptrBéguin = & $this->_ptrDernierBéguin()) && $ptrBéguin[0] == 'function' && $this->_functionAs($ptrBéguin, $chaîne, $découpes, $i))
				{
					$ptrBéguin[0] .= ' '.$motClé;
					$ptrBéguin[1] .= ' … '.$découpes[$i][0];
				}
				// Et on retourne, soit l'ayant intégré au précédent, soit l'ignorant.
				return;
			}
				// Un begin dans une fonction prend la suite de la fonction.
				if($motClé == 'begin' && ($ptrBéguin = & $this->_ptrDernierBéguin()))
					switch($ptrBéguin[0])
					{
						case 'function as': return;
						case 'declare': $ptrBéguin[0] = 'begin'; return;
					}
			}
			$this->_béguinsPotentiels[] = array($motClé, $découpes[$i][0], $this->_ligne, $i);
		}
	}
	
	/**
	 * Pointeur sur le dernier begin dans lequel on est entrés.
	 *
	 * @param bool $seulementEnCours Si vrai, ne remonte que les begin en cours de constitution.
	 */
	protected function & _ptrDernierBéguin($seulementEnCours = false)
	{
		$r = null;
		
		if(($dern = count($this->_béguinsPotentiels) - 1) >= 0)
			return /*&*/ $this->_béguinsPotentiels[$dern];
		if(!$seulementEnCours && ($dern = count($this->_béguins) - 1) >= 0)
			return /*&*/ $this->_béguins[$dern];
		
		return /*&*/ $r;
	}
	
	public function délimiteur($car)
	{
		// On inclut les caractères de contrôle, dont la tabulation.
		// On s'arrête en 0x80, de peur de voir comme délimiteur des caractères UTF-8.
		return
		!in_array($car, array('_'))
		&&
		(
			($car >= "\0" && $car < '0') || ($car > '9' && $car < 'A') || ($car > 'Z' && $car < 'a') || ($car > 'z' && $car <= chr(0x7F))
		);
	}
	
	/**
	 * Enregistrer les begin / end qui jusque-là n'étaient que potentiels.
	 * À appeler lorsque le bloc SQL les contenant est définitivement agrégé à $this->_requeteEnCours.
	 * 
	 * @param null|int Position du dernier arrêt. Si définie, seuls les béguins situés avant cet arrêt sont consommés.
	 */
	protected function _entérinerBéguins($numDernierArrêt = null)
	{
		foreach($this->_béguinsPotentiels as $numBéguin => $béguin)
		{
			if(isset($numDernierArrêt) && $béguin[3] >= $numDernierArrêt)
			{
				$this->_béguinsPotentiels = array_slice($this->_béguinsPotentiels, $numBéguin);
				return;
			}
			switch($motClé = $béguin[0])
			{
				case 'end if':
				case 'end loop':
				case 'begin transaction':
					break;
				case 'end case':
				case 'end':
					if(!count($this->_béguins))
						throw $this->exception("Problème d'imbrication: $motClé sans début correspondant");
					$début = array_pop($this->_béguins);
					$débutOrig = $début[1];
					$début = $début[0];
					if(!isset(Sqleur::$FINS[$début]))
						throw $this->exception("Problème d'imbrication: $débutOrig (remonté comme mot-clé de début de bloc) non référencé");
					if($motClé == 'end case') $motClé = 'end';
					if($motClé != Sqleur::$FINS[$début])
						throw $this->exception("Problème d'imbrication: {$béguin[1]} n'est pas censé fermer ".$début);
					$this->_dernierBéguinBouclé = $début;
					break;
				default:
					$this->_béguins[] = $béguin;
					break;
			}
		}
		$this->_béguinsPotentiels = array();
	}
	
	/**
	 * S'assure que tous les blocs procéduraux (begin …; end;) ont été fermés.
	 * À appeler avant de passer le bloc à l'exécutant.
	 */
	protected function _vérifierBéguins()
	{
		if(count($this->_béguins))
		{
			$ligne = $this->_dernièreLigne;
			$this->_dernièreLigne = $this->_béguins[0][2];
			$béguins = array();
			foreach($this->_béguins as $béguin)
				$béguins[] = $béguin[1].':'.$béguin[2];
			$ex = $this->exception('blocs non terminés ('.implode(', ', $béguins).')');
			$this->_dernièreLigne = $ligne;
			throw $ex;
		}
	}
	
	/* À appeler sur point-virgule pour faire sauter les fonctions non transformées (non suivies de leur corps démarré par un as).
	 * Ex.: simple déclaration sans définition, drop function, for each row execute procedure, etc.
	 */
	protected function _écarterFauxBéguins()
	{
		if(!($n = count($this->_béguins))) return;
		
		for($i = $n; --$i >= 0 && in_array($this->_béguins[$i][0], array('package', 'procedure', 'function'));) {}
		if(++$i < $n)
			array_splice($this->_béguins, $i);
	}
	
	protected function _vientDeTerminerUnBlocProcédural($découpes, $i)
	{
		return
			isset($this->_dernierBéguinBouclé)
			&& in_array($this->_dernierBéguinBouclé, array('begin', 'function as'))
			&& ($boutPréc = $this->_découpePrécédente($découpes, $i)) !== null
			&& strtolower($boutPréc) == 'end'
			&&
			(
				($posMoi = $découpes[$i][1]) == ($posFinPréc = $découpes[$i - 1][1] + strlen($découpes[$i - 1][0]))
				|| !($espace = trim(substr($this->_chaîneEnCours, $posFinPréc, $posMoi - $posFinPréc)))
				|| (($this->_mode & Sqleur::MODE_SQLPLUS) && $espace == '/')
				// Grumf, certains (Oracle) tolèrent un mot entre le end et le point-virgule (le nom de la fonction définie).
				|| preg_match('#^[a-zA-Z0-9_]+#', $espace)
			)
		;
	}
	
	/**
	 * Renvoie la dernière découpe significative avant celle demandée.
	 */
	protected function _découpePrécédente($découpes, $i)
	{
		while(isset($découpes[--$i]))
			if(!in_array($découpes[$i][0], array("\n", '/', '--')))
				return $découpes[$i][0];
	}
	
	/**
	 * Valide qu'un 'as', arrivant en $découpes[$i], se raccroche bien à une function ou assimilé.
	 */
	protected function _functionAs($béguin, $chaîne, $découpes, $i)
	{
		/* NOTE: motivation
		 * on cherche à distinguer un "create function machin() as" (qui devrait être suivi d'un begin) d'un as/is sans intérêt ("create trigger … when (a is not null)").
		 * Les parenthèses semblent un bon moyen de distinguer un as "complément de fonction" d'un as "SQL".
		 * Mais attention aux perfs! On ne peut pas s'amuser à instituer un décompte des ouvertures / fermetures de parenthèses sur l'ensemble du SQL (le $expr de découperBloc()), juste pour blinder le (très rare) cas du create function.
		 * On fait donc de l'approximatif en réextrayant les caractères entre notre create et notre as (en espérant ne pas avoir perdu de contexte).
		 */
		// À FAIRE: robustesse: là si le create a été détecté sur la précédente passe, on a perdu trace du contenu exacte entre lui et nous (le 'as');
		//          il faudrait alors, en cas de détection d'un create, avoir un mode spécial qui mémorise tout jusqu'à tomber sur le as (ou jusqu'à un ; marquant l'arrêt des recherches).
		// Grrr SQLite autorise le create trigger when à NE PAS avoir de parenthèses! Ceci dit dans leur https://www.sqlite.org/lang_createtrigger.html le is et le as n'apparaissent jamais seuls (is null est traduit en isnull, as n'est explicite que dans cast(x as y) donc avec parenthèses explicites).
		
		if(($iDébut = $béguin[3]) >= $i)
			$iDébut = 0;
		if($iDébut >= $i) return true; // /!\ Approximation.
		// À FAIRE?: si $découpes[$iDébut][0] != $béguin[1], throw?
		
		$entre = substr($chaîne, $posDébut = $découpes[$iDébut][1] + strlen($découpes[$iDébut][0]), $découpes[$i][1] - $posDébut);
		// On est au même niveau que le create function tant qu'on n'est pas dans une parenthèse, donc tant que l'on a autant de parenthèses ouvrantes que de fermantes (… ou moins en cas de bloc mémoire ayant coupé un peu trop entre notre create et nous).
		if(substr_count($entre, ')') < substr_count($entre, '(')) return false;
		
		// Seconde condition: être un vrai mot-clé isolé… Genre pas le is de function windows_iis() ou function is_acceptable().
		// Notons que le CHAÎNE_COUPÉE renvoyé par _motClé() nous GARANTIT que maintenant nous avons dans $chaîne les caractères suivant notre prétendu mot-clé.
		if(strlen(trim(substr($entre, -1))) > 0) return false;
		if(strlen(trim(substr($chaîne, $découpes[$i][1] + strlen($découpes[$i][0]), 1))) > 0) return false;
		
		return true;
	}
}

?>
