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

class Sqleur
{
	const MODE_BEGIN_END = 0x01; /* À FAIRE: le mettre par défaut? SQLite fonctionne comme ça, Oracle aussi, seul PostgreSQL définit ses fonctions en chaîne à $ mais ça change en PostgreSQL 14. */
	const MODE_COMM_MULTILIGNE = 0x02; // Transmet-on les commentaires /* comm */?
	const MODE_COMM_MONOLIGNE  = 0x04; // Transmet-on les commentaires -- comm?
	const MODE_COMM_TOUS       = 0x06; // MODE_COMM_MULTILIGNE|MODE_COMM_MONOLIGNE
	const MODE_SQLPLUS         = 0x08; // Vraie bouse qui ne sachant pas compter ses imbrications de begin, end, demande un / après les commandes qui lui font peur.
	
	public $tailleBloc = 0x20000;
	
	/**
	 * Constructeur.
	 * 
	 * @param fonction $sortie Méthode prenant en paramètre une requête. Sera appelée pour chaque requête, au fur et à mesure qu'elles seront lues.
	 */
	public function __construct($sortie = null, $préprocesseurs = array())
	{
		$this->avecDéfs(array());
		$this->_mode = Sqleur::MODE_COMM_TOUS;
		$this->_fichier = null;
		$this->_ligne = null;
		$this->_dernièreLigne = null;
		$this->_boucles = array();
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
		
		foreach($préprocesseurs as $préprocesseur)
			$préprocesseur->_sqleur = $this;
		$this->_préprocesseurs = $préprocesseurs;
	}
	
	protected function _accumule($requete)
	{
		$this->_retour[] = $requete;
	}
	
	protected function _init()
	{
		$this->_conditions = array(); // Pile des conditions de préprocesseur.
		unset($this->_chaineDerniereDecoupe);
		unset($this->_requeteEnCours);
		unset($this->_requêteRemplacée);
		unset($this->_resteEnCours);
		$this->_dansChaîne = null;
	}
	
	public function decoupeFichier($fichier)
	{
		$this->_init();
		return $this->_découpeFichier($fichier, true);
	}
	
	public function _découpeFichier($fichier, $laFinEstVraimentLaFin = false)
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
		$r = $laFinEstVraimentLaFin ? $this->_decoupeBloc('', true) : null;
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
		'create package' => 'end',
		'create or replace package' => 'end',
		// Les autres:
		'end' => true,
		// Les faux-amis (similaires à un "vrai" mot-clé, remontés en tant que tel afin que, mis sur pied d'égalité, on puisse décider duquel il s'agit):
		'begin transaction' => false,
		'end if' => false,
		'end loop' => false,
	);
	
	protected function _ajouterBoutRequête($bout, $appliquerDéfs = true, $duVent = false)
	{
		/* À FAIRE: Ouille, on applique les définitions ici, après découpe, ce qui veut dire que si notre définition contient plusieurs instructions on finira avec une seule instruction contenant un point-virgule! */
		/* À FAIRE: si on fait le point précédent (repasser par un découperBloc), adapter le calcul des lignes aux lignes originales (un remplacement peut contenir un multi-lignes). */
		/* À FAIRE: appeler sur chaque fin de ligne (on ne peut avoir de symbole à remplacer à cheval sur une fin de ligne) pour permettre au COPY par exemple de consommer en flux tendu. */
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
		if($this->_queDuVent && !$duVent && trim($bout))
			$this->_queDuVent = false;
		if($appliquerDéfs)
			$this->_requêteRemplacée = $this->_requeteEnCours;
		$this->_entérinerBéguins();
	}
	
	protected function _decoupeBloc($chaîne, $laFinEstVraimentLaFin = true) { return $this->découperBloc($chaîne, $laFinEstVraimentLaFin); }
	public function découperBloc($chaine, $laFinEstVraimentLaFin = true)
	{
		if(isset($this->_resteEnCours))
			$chaine = $this->_resteEnCours.$chaine;
		$this->_chaîneEnCours = $chaine;
		
		// Tous le code gérant cet enquiquinante suite ";\n+/\n*" sera marqué de l'étiquette DML (Découpe Multi-Lignes):
		// À FAIRE: DML dissocier $onEnFaitPlusPourSqlMoins du ; et ne vérifier leur séquence que dans le traitement DML? Là ça complique beaucoup de choses… Par contre en effet on gagne en perfs car on ne lit pas chaque / isolé, et on évite aussi de manger ceux de // ou /**/; sinon laisser l'expr comme ça, mais après preg_match_all traduire la suite en deux découpes successives. /!\ Bien traiter le cas où le ; était dans un bloc, et le \n/ dans le suivant. /!\ Attention aussi, là j'ai l'impression qu'on mange le / si on a un commentaire juste après le ;, de type ";//".
		$onEnFaitPlusPourSqlMoins = $this->_mode & Sqleur::MODE_SQLPLUS ? '(?:\s*\n/(?:\n|$))?' : '';
		$expr = '#|\\\\|;'.$onEnFaitPlusPourSqlMoins.'|--|'."\n".'|/\*|\*/|\'|\\\\\'|\$[a-zA-Z0-9_]*\$';
		if($this->_mode & Sqleur::MODE_BEGIN_END)
			// On repère non seulement les expressions entrant et sortant d'un bloc procédural,
			// mais aussi les faux-amis ("end" de "end loop" à ne pas confondre avec celui fermant un "begin").
			// N.B.: un contrôle sur le point-virgule sera fait par ailleurs (pour distinguer un "begin" de bloc procédural, de celui synonyme de "begin transaction" en PostgreSQL par exemple).
			$expr .= preg_replace_callback('/[a-z]/', function($x) { return '['.$x[0].strtoupper($x[0]).']'; }, '|begin(?: transaction)?|case|end(?: if| loop)?|create(?: or replace)? package');
// À FAIRE: distinguer le begin (end) du begin transaction et du begin; (= begin transaction PostgreSQL). Le second en repérant un ; immédiatement après notre mot-clé.
		preg_match_all("@$expr@", $chaine, $decoupes, PREG_OFFSET_CAPTURE);
		
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
		}
		else
		{
			$chaineDerniereDecoupe = $this->_chaineDerniereDecoupe;
			$dernierRetour = $chaineDerniereDecoupe == "\n" ? 0 : -1;
			// DML: Particularité: certaines $chaineDerniereDecoupe peuvent porter des retours à la ligne cachés; on restitue au mieux.
			switch(substr($chaineDerniereDecoupe, 0, 1))
			{
				case ';':
					$decoupes[-1] = [ $chaineDerniereDecoupe, -strlen($chaineDerniereDecoupe) ];
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
					$this->_mangerBout($chaine, /*&*/ $dernierArret, $decoupes[$i][1]);
					$dernierArret += strlen($decoupes[$i][0]);
					// DML: étant susceptibles de porter du \n, et $chaineDerniereDecoupe n'étant jamais comparée à simplement ';', on y entrepose la restitution exacte de ce qui nous a invoqués (plutôt que seulement le premier caractère).
					$nLignes = substr_count($chaineDerniereDecoupe = $decoupes[$i][0], "\n");
					if(($this->_mode & Sqleur::MODE_BEGIN_END))
						if(count($this->_béguins) > 0) // Point-virgule à l'intérieur d'un begin, à la trigger SQLite: ce n'est pas une fin d'instruction.
						{
							$this->_ajouterBoutRequête($chaineDerniereDecoupe);
							$this->_ligne += $nLignes;
							break;
						}
						// Le ; après end (de langage procédural, et non pas dans un case end) a deux fonctions:
						// une littérale (complète textuellement l'end), l'autre de séparateur.
						// On ajoute donc sa fonction littérale (pour éviter l'erreur Oracle PLS-00103: end sans point-virgule).
						else if($this->_vientDeTerminerUnBlocProcédural($decoupes, $i))
							$this->_requeteEnCours .= ';';
					$this->terminaison = $decoupes[$i][0];
					$this->_sors($this->_requeteEnCours);
					$this->terminaison = null;
					$this->_requeteEnCours = '';
					$this->_queDuVent = true; /* À FAIRE: le gérer aussi dans les conditions (empiler et dépiler). */
					unset($this->_requêteRemplacée);
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
					$this->_mangerBout($chaine, /*&*/ $dernierArret, $dernierRetour);
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
						$this->_ajouterBoutRequête(substr($chaine, $dernierArret, $decoupes[$j][1] - $dernierArret));
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
				case "'":
				case '$':
					if(!$this->dansUnSiÀLaTrappe())
					$this->_mangerChaîne($chaine, $decoupes, $n, /*&*/ $i, /*&*/ $dernierRetour, /*&*/ $chaineNouvelleDecoupe, /*&*/ $dernierArret, /*&*/ $nouvelArret);
					break;
				case '\\':
					break;
				default:
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
		}
		
			if(count($this->_boucles))
			{
				$ajoutCorpsDeBoucle = $laFinEstVraimentLaFin ? $chaine : substr($chaine, 0, $dernierArret);
				foreach($this->_boucles as $boucle)
					$boucle->corps .= $ajoutCorpsDeBoucle;
			}
			$this->_resteEnCours = substr($chaine, $dernierArret);
			$this->_chaineDerniereDecoupe = $chaineDerniereDecoupe;
		if($laFinEstVraimentLaFin)
		{
			$this->_ajouterBoutRequête($this->_resteEnCours);
			$this->_sors($this->_requeteEnCours);
			unset($this->_chaineDerniereDecoupe);
			unset($this->_requeteEnCours);
			unset($this->_requêteRemplacée);
			unset($this->_resteEnCours);
			if($this->_retourDirect)
		{
			$retour = $this->_retour;
			$this->_retour = array();
			return $retour;
			}
		}
	}
	
	protected function _mangerBout($chaîne, & $dernierArret, $jusquÀ, $duVent = false)
	{
		$this->_ajouterBoutRequête(substr($chaîne, $dernierArret, $jusquÀ - $dernierArret), true, $duVent);
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
		/* NOTE: ajout sans remplacement
		 * On ajoute le bout lu sans effectuer les remplacements, pour éviter de couper un #define.
		 * Ex.:
		 *   #define MACRO(x, y) …
		 *   MACRO('a', 'b');
		 * Si on effectue les remplacements à chaque fin de chaîne, ils seront appliqués à "MACRO('a'" puis ", 'b'", et enfin à ");" (remplacement de fin de requête).
		 * La macro n'aura alors pas moyen de s'appliquer (il lui faut repérer ses parenthèses ouvrante et fermante dans le même bloc).
		 * Le seul cas qui justifie le remplacement avant émission de l'instruction complète (hors cas du COPY où un remplacement ligne à ligne est bienvenu) est lorsque notre chaîne est coupée d'un #define ("problématique 2.").
		 * Mais dans ce cas, nous passons la main à l'instruction de préproc dont la première action sera d'_ajouterBoutRequête(true).
		 * Inutile donc que nous le fassions.
		 */
		$this->_ajouterBoutRequête($fragment, false);
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
		
		$this->_mangerBout($chaîne, /*&*/ $dernierArrêt, $découpes[$i][1]);
		
		while(++$i < $n && $découpes[$i][0] != $borne)
			if($découpes[$i][0] == "\n") // Implicitement: && $mode != '-', car en ce cas, la condition d'arrêt nous a déjà fait sortir.
				++$this->_ligne;
		if($i < $n || $laFinEstVraimentLaFin) // Seconde condition: si on arrive en bout de truc, l'EOF clot notre commentaire.
		{
			$arrêt = $i >= $n ? strlen($chaîne) : $découpes[$i][1] + ($tÉpilogue = $etDélim ? strlen($découpes[$i][0]) : 0);
			if($this->_mode & $mode) // Si le mode du Sqleur demande de sortir aussi ce type de commentaire, on s'exécute.
				$this->_mangerBout($chaîne, /*&*/ $dernierArrêt, $arrêt, true);
			else // Sinon on ne fait qu'avancer le curseur sans signaler le commentaire lui-même.
				$dernierArrêt = $arrêt;
			if($mode == Sqleur::MODE_COMM_MONOLIGNE && $i < $n)
				--$i; // Le \n devra être traité de façon standard au prochain tour de boucle (calcul du $dernierRetour).
		}
	}
	
	protected function _sors($requete, $brut = false, $appliquerDéfs = false, $interne = false)
	{
		/* À FAIRE: le calcul qui suit est faux si $requete a subi un remplacement de _defs où le remplacement faisait plus d'une ligne. */
		$this->_dernièreLigne = $this->_ligne - substr_count(ltrim($requete), "\n");
		if($appliquerDéfs)
			$requete = $this->_appliquerDéfs($requete);
		if(strlen($requete = trim($requete)) && !$this->_queDuVent)
		{
			if(isset($this->_conv))
				$requete = call_user_func($this->_conv, $requete);
			return call_user_func($this->_sortie, $requete, false, $interne);
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
		$requeteEnCours = $this->_requeteEnCours;
		
		$posEspace = strpos($directive, ' ');
		$motCle = $posEspace === false ? $directive : substr($directive, 0, $posEspace);
		switch($motCle)
		{
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
					$this->_requeteEnCours = $condition->requêteEnCours;
					$this->_requêteRemplacée = $condition->requêteRemplacée;
					$this->_defs = $condition->défs;
					$condition->enCours(true);
					$condition->déjàFaite = true;
				}
				else
				{
					$this->_sortie = array($this, 'sortirContenuIfFalse');
					if($condition->enCours) // Si on clôt l'en-cours.
					{
						$condition->requêteEnCours = $requeteEnCours; // On mémorise.
						$condition->requêteRemplacée = $this->_requêteRemplacée;
						$condition->défs = $this->_defs;
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
				{
					$this->_requeteEnCours = $condition->requêteEnCours; // On restaure.
					$this->_requêteRemplacée = $condition->requêteRemplacée;
					$this->_defs = $condition->défs;
				}
				$condition->enCours(false);
				$this->_sortie = $condition->sortie;
				return;
		}
		if(!$this->dansUnSiÀLaTrappe())
		{
			$this->_requeteEnCours = $requeteEnCours;
			foreach($this->_préprocesseurs as $préproc)
				// N.B.: $requeteEnCours NE DOIT PLUS être passée à préprocesse().
				// Les préprocesseurs désirant modifier la requête en cours de constitution doivent désormais exploiter $this->_requeteEnCours.
				// Ce dernier paramètre désormais inutile pourra être supprimé une fois tous les préprocesseurs existants purgés.
				if($préproc->préprocesse($motCle, $directive, $requeteEnCours) !== false)
				{
					$requeteEnCours = $this->_requeteEnCours;
					return $requeteEnCours;
				}
			$requeteEnCours = $this->_requeteEnCours;
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
		
		$this->_requeteEnCours = $requeteEnCours;
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
		if(is_array($chaîne)) $chaîne = $chaîne[0];
		// La séparation statiques / dynamiques nous oblige à les passer dans un ordre différent de l'initial (qui mêlait statiques et dynamiques).
		// On choisit les dynamiques d'abord, car, plus complexes, certaines de leurs parties peuvent être surchargées par des statiques.
		foreach($this->_defs['dyn'] as $expr => $rempl)
			$chaîne = preg_replace_callback($expr, $rempl, $chaîne);
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
		$chaîne = strtr($chaîne, $this->_defs['statr']);
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
	);
	
	/*- Intestins ------------------------------------------------------------*/
	
	/**
	 * Analyse les mots-clés SQL qui, dans certaines situations, peuvent indiquer un bloc dans lequel le point-virgule n'est pas fermant.
	 * Le cas échéant, ajoute le mot-clé à la pile de décompte des niveaux.
	 */
	protected function _motClé($chaîne, $taille, $laFinEstVraimentLaFin, $découpes, $dernierRetour, $dernierArrêt, $i)
	{
		$motClé = strtolower($découpes[$i][0]);
		// Attention aux mots-clés en limite de bloc de lecture, qui peuvent en cacher un autre;
		// mieux vaut alors sortir, et ne revenir qu'une fois assurés que rien ne le suit qui en ferait changer le sens (ex.: begin / begin transaction).
		if($i == count($découpes) - 1 && !$laFinEstVraimentLaFin)
			switch($motClé)
			{
				case 'begin':
				case 'end':
					// N.B.: fait double emploi avec le gros if() plus bas. Mais c'est plus prudent.
					return Sqleur::CHAÎNE_COUPÉE;
			}
		// Un synonyme PostgreSQL prêtant à confusion.
		if($motClé == 'begin' && isset($découpes[$i + 1]) && $découpes[$i + 1][1] == $découpes[$i][1] + strlen($motClé) && $découpes[$i + 1][0] == ';')
			$motClé = 'begin transaction';
		if(!isset(Sqleur::$FINS[$motClé]))
			throw new Exception("Bloc de découpe inattendu $motClé");
		// Les faux-amis sont les end quelque chose, qu'on ne gère pas ainsi que leur balise de démarrage.
		if(!Sqleur::$FINS[$motClé])
			return;
		
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
			$this->_béguinsPotentiels[] = $motClé;
	}
	
	public function délimiteur($car)
	{
		// On inclut les caractères de contrôle, dont la tabulation.
		// On s'arrête en 0x80, de peur de voir comme délimiteur des caractères UTF-8.
		return ($car >= "\0" && $car < '0') || ($car > '9' && $car < 'A') || ($car > 'Z' && $car < 'a') || ($car > 'z' && $car <= chr(0x7F));
	}
	
	/**
	 * Enregistrer les begin / end qui jusque-là n'étaient que potentiels.
	 * À appeler lorsque le bloc SQL les contenant est définitivement agrégé à $this->_requeteEnCours.
	 */
	protected function _entérinerBéguins()
	{
		foreach($this->_béguinsPotentiels as $motClé)
			switch($motClé)
			{
				case 'end if':
				case 'end loop':
				case 'begin transaction':
					break;
				case 'end':
					if(!count($this->_béguins))
						throw $this->exception("Problème d'imbrication: $motClé sans début correspondant");
					$début = array_pop($this->_béguins);
					if(!isset(Sqleur::$FINS[$début]))
						throw $this->exception("Problème d'imbrication: $début (remonté comme mot-clé de début de bloc) non référencé");
					if($motClé != Sqleur::$FINS[$début])
						throw $this->exception("Problème d'imbrication: $motClé n'est pas censé fermer ".Sqleur::$FINS[$début]);
					$this->_dernierBéguinBouclé = $début;
					break;
				default:
					$this->_béguins[] = $motClé;
					break;
			}
		$this->_béguinsPotentiels = array();
	}
	
	protected function _vientDeTerminerUnBlocProcédural($découpes, $i)
	{
		return
			isset($this->_dernierBéguinBouclé)
			&& $this->_dernierBéguinBouclé == 'begin'
			&& $découpes[$i - 1][0] == 'end'
			&&
			(
				($posMoi = $découpes[$i][1]) == ($posFinPréc = $découpes[$i - 1][1] + strlen($découpes[$i - 1][0]))
				|| !($espace = trim(substr($this->_chaîneEnCours, $posFinPréc, $posMoi - $posFinPréc)))
				|| (($this->_mode & Sqleur::MODE_SQLPLUS) && $espace == '/')
			)
		;
	}
}

?>
