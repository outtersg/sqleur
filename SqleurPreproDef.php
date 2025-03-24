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

include_once 'SqleurPreproExpr.php';

require_once __DIR__.'/SqleurPrepro.php';

class SqleurPreproDef extends SqleurPrepro
{
	protected $_préfixes = array('#calc', '#set', '#setn', '#define', '#undef');
	
	const MODE_PLAT = 0;   // #define TOTO
	const MODE_FONC = 1;   // #define TOTO(x)
	const MODE_REGEX = 2;  // #define /TOTO +x/

	// Numéro du premier paramètre chopé par notre expression capturante.
	// 2 car dans la regex capturante les paramètres arriveront en 2, 3, etc. (après l'expression complète en 0, et le nom de #define en 1).
	const PREMIER_PARAM = 2;
	
	protected $_défsParams;
	
	public function préprocesse($motClé, $directiveComplète)
	{
		if(!in_array($motClé, $this->_préfixes))
			return false;
		
		$b = "[ \t]*";
		
		$niAntisNiCrochets = "[^/\\\\[]+";
		$antiPoint = "\\\\.";
		$crochet = "\[[^\\]]+\]";
		if(preg_match("@^#[a-z]+[ \t]+/((?:$niAntisNiCrochets|$antiPoint|$crochet)+)/([a-z]*)(?:[ \t]+|$)@", $directiveComplète, $rer))
		{
			$rer[1] = strtr($rer[1], array('\/' => '/', '\\\\' => '\\'));
			foreach(array('/', '#', '@', "\002", "\003", "\004") as $sépExpr)
				if(strpos($rer[1], $sépExpr) === false)
					break;
			$var = $sépExpr.$rer[1].$sépExpr.$rer[2];
			$mode = 2;
		}
		else
		{
		$mot = "[_a-zA-Z][_a-zA-Z0-9]*";
		preg_match("/^#[a-z]+[ \t]+([^ \t(]+)($b\($b(|$mot(?:$b,$b$mot)*)$b\))?(?:[ \t\r\n]+|\$)/", $directiveComplète, $rer);
		$var = $rer[1];
			$mode = isset($rer[2]) ? 1 : 0;
		}
		$val = substr($directiveComplète, strlen($rer[0]));
		
		$paramsDéfinir = [ $motClé, $var, $val, $mode, $mode == 1 ? $rer[3] : null ];
		
		if($val == '<<')
			return $this->_préempterSql(1, $paramsDéfinir);
		
		call_user_func_array([ $this, '_définir' ], $paramsDéfinir);
	}
	
	public function _définir($motClé, $var, $val, $mode, $paramsDéf)
	{
		if(in_array($motClé, array('#undef')))
		{
			$val = null;
			// #undef sur une expression régulière: on #undef tout ce qui y ressemble.
			if($mode == 2)
			{
				$àDéDéf = [];
				foreach($this->_sqleur->_defs as $ensembleDéfs)
					foreach(array_keys($ensembleDéfs) as $clé)
						if(preg_match($var, $clé))
							$àDéDéf[$clé] = null;
				if(count($àDéDéf))
					$this->_sqleur->ajouterDéfs($àDéDéf);
				return;
			}
		}
		else if(!in_array($motClé, array('#define')))
		{
			$multi = in_array($motClé, [ '#setn' ]);
			// Si on est à la fois à paramètres et en langage prépro, la résolution ne pourra se faire qu'à l'exécution.
			if($mode != self::MODE_FONC)
			{
			$e = new SqleurPreproExpr();
				$val = $e->calculer($val, $this->_sqleur, false, $multi);
			}
		}
		else
			$val = $this->_sqleur->appliquerDéfs($val);
		
		switch($mode)
		{
			case 1:
		// Si on a des parenthèses, on transforme notre var en regex, car elle sera dynamique.
		// À la différence des statiques (qui remplacent un peu n'importe quoi), nous nous rapprochons d'un préprocesseur C qui ne recherche que les mots.
		// Ex.: "#define pied(x) (x+1)" remplacera pied(3) et casse-pied(3) mais pas cassepied(3) (le - étant un séparateur de mots, ça donnera casse-(3+1)).
			$var = $this->_préparerDéfParams($var, $paramsDéf, $val);
				if(isset($multi)) // $multi est définie en mode #set.
					$val = [ $this, '_remplacerSetParams'.($multi ? 'Multi' : '') ];
				else
			$val = array($this, '_remplacerDéfParams');
				break;
			case 2:
				$moi = $this;
				$val = function($rés) use($val, $moi) { return $moi->_remplacerRegEx($rés, $val); };
				break;
		}
		
		$this->_sqleur->ajouterDéfs(array($var => $val));
	}
	
	protected function _préparerDéfParams($déf, $params, $val)
	{
		$b = "[ \t]*";
		
		// Découpe des paramètres de la définition.
		
		$params = preg_split("/$b,$b/", $params);
		if($params[0] == '') $params = array();
		
		// Construction de la regex correspondante.
		
		$prp = SqleurPreproDef::PREMIER_PARAM; // Position Récursion Parenthèses: numéro de la parenthèse capturante qui chope les parenthèses imbriquées.
		$eParam = "((?:[^\\,()]|\\\\.|\((?:(?$prp)|,)+\)|'[^']*')+|)$b";
		$eParams = "";
		foreach($params as $num => $param)
			$eParams .= $num > 0 ? ",$b((?$prp))$b" : $eParam;
		$eDéf = "/(?:^|\\b)($déf)$b\($b$eParams\)/";
		
		// Recherche des paramètres dans le corps, hachage de celui-ci.
		// (sous forme d'une alternance texte brut / variable / text brut / etc.)
		
		// À la différence du statique, on s'assure que les remplacements sont bien des mots (pas de a_zA_Z0-9_ avant ou après), plus proche des variables d'une fonction C.
		// Cependant dans la regex qui suit, on ne vérifie que l'avant, et non l'après, car celui-ci doit pouvoir servir d'avant à une potentielle variable suivante
		// (ex.: "#define TOTO(x, y) x+y" recherche <sép>x et <sép>y dans x+y: si on cherchait <sép>x<sép>, on boufferait le <sép> d'y et on ne trouverait donc pas ce dernier).
		// La vérification de l'après sera faite hors regex.
		if(count($params))
		preg_match_all('/(^|[^_a-zA-Z0-9])('.implode('|', $params).')/', $val, $rers, PREG_OFFSET_CAPTURE|PREG_SET_ORDER);
		else
			$rers = array();
		$params = array_flip($params);
		$lu = 0;
		$déroulé = array();
		foreach($rers as $num => $rer)
			if(!preg_match('/[_a-zA-Z0-9]/', substr($val, $fin = $rer[0][1] + strlen($rer[0][0]), 1))) // La fameuse vérification du séparateur d'après. !preg_match et non preg_match([^, car la fin de chaîne est acceptée.
			{
				$déroulé[] = substr($val, $lu, $rer[0][1] - $lu).$rer[1][0];
				$déroulé[] = $params[$rer[2][0]] + SqleurPreproDef::PREMIER_PARAM;
				$lu = $fin;
			}
		$déroulé[] = substr($val, $lu);
		for($i = 0; $i < count($déroulé); $i += 2)
		{
			if(substr($déroulé[$i], 0, 2) == '##') $déroulé[$i] = substr($déroulé[$i], 2);
			if(substr($déroulé[$i], -2) == '##') $déroulé[$i] = substr($déroulé[$i], 0, -2);
		}
		
		// Entreposage et retour.
		
		$this->_défsParams[$déf][count($params)] = $déroulé;
		return $eDéf;
	}
	
	public function _remplacerDéfParams($rés)
	{
		$déroulé = $this->_défsParams[$rés[1]][count($rés) - SqleurPreproDef::PREMIER_PARAM];
		$texte = false; // Texte brut pour le premier bloc, donc variable pour le -1ème bloc.
		$r = '';
		foreach($déroulé as $bout)
		{
			// Si le précédent était texte fixe, donc celui-ci est un paramètre de la macro:
			if(!($texte = !$texte))
			{
				// Les \, (pour passer une "," littérale plutôt que séparatrice de paramètres) ne sont plus nécessaires, maintenant qu'on a bien découpé en tableau.
				$bout = strtr($rés[$bout], array('\,' => ','));
				// On s'assure que la valeur du paramètre ne comportait pas d'autres remplacements; en effet notre dégustation de gauche à droite fait que dans une expression DEF(DEF(a, b), c), le premier DEF se voit passer _tel quel_ le contenu, dont le DEF imbriqué.
				$bout = $this->_sqleur->appliquerDéfs($bout);
			}
			$r .= $bout;
		}
		return $r;
	}
	
	public function _remplacerSetParamsMulti($rés) { return $this->_remplacerSetParams($rés, true); }
	public function _remplacerSetParams($rés, $multi = false)
	{
		// Les paramètres de l'invocation sont attendus en mode #define: titi est la chaîne "titi", à moins qu'une variable titi existe auquel cas on remplace.
		for($un = count($rés); --$un >= SqleurPreproDef::PREMIER_PARAM;)
		{
			$val = $this->_sqleur->appliquerDéfs($rés[$un]);
			$val = '"'.strtr($val, [ '"' => '\"' ]).'"';
			$rés[$un] = $val;
		}
		
		// Tout ça devient une expression #set: on interprète.
		$val = $this->_remplacerDéfParams($rés);
		
		// Et on fait manger ça à l'expression.
		$e = new SqleurPreproExpr();
		$val = $e->calculer($val, $this->_sqleur, false, $multi);
		
		return $val;
	}
	
	public function _remplacerRegex($rés, $rempl)
	{
		$lieutenants = array();
		for($n = count($rés); --$n > 0;) // On commence par les derniers, pour que \10 trouve ses occurrences sans se les être fait bouffer par \1.
			$lieutenants['\\'.$n] = $rés[$n];
		
		return strtr($rempl, $lieutenants);
	}
	
	public function _chope($req, $false = false, $interne = null, $paramsDéfinir = null)
	{
		/* Pour le moment on ne gère qu'une grosse chaîne de caractères, délimiteur dollar. */
		
		// Les commentaires d'avant le #define sont, d'un point de vue SQL, ceux de la chaîne.
		$req = preg_replace('/^(?:\s+|--.*)+/', '', $req);
		
		if(!preg_match('/^[$]([^ $]*)[$]\n*/', $req, $rd))
			throw new Exception('le heredoc prend en entrée une chaîne délimitée par dollars');
		if(!preg_match('/\n[$]'.$rd[1].'[$]\n*$/', $req, $rf))
			throw new Exception('le heredoc prend en entrée une chaîne délimitée par dollars et terminée de la même manière');
		
		$val = substr($req, strlen($rd[0]), -strlen($rf[0]));
		$paramsDéfinir[2] = $val;
		call_user_func_array([ $this, '_définir' ], $paramsDéfinir);
	}
}

?>
