# Copyright (c) 2022-2023 Guillaume Outters
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.  IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
# SOFTWARE.

# Fonctions shell faisant collaborer sqleur (découpage de SQL) et sqlminus (poussage à une base, Oracle par exemple depuis un FreeBSD qui n'a que le JDBC pour s'y connecter).

sqlm()
{
	local bdd="$bdd" bddtunnel="$bddtunnel" bddtunnelbiblios="$bddtunnelbiblios"
	case "$BDD_CHAINE" in ?*) bdd="$BDD_CHAINE" ;; esac # Prend le pas sur $bdd même si celle-ci est définie.
	case "$bddtunnel:$BDD_SSH" in :?*) bddtunnel="$BDD_SSH" ;; esac
	case "$bddtunnelbiblios:$BDD_SSH_LIB" in :?*) bddtunnelbiblios="$BDD_SSH_LIB" ;; esac
	
	# Répartition des paramètres entre:
	# - $reqs: requêtes SQL ou instructions préprocesseur (dont des #include pour les fichiers passés à sqlm) ou - pour stdin
	# - $ppp: Params PréProcesseur
	# - "$@": params joueur
	local sep="`printf '\036'`" reqs= ppp= prereqs= prems=1
	local ppq= # Prochain Pour Qui
			# /!\ Repose sur le repapa des scripts de Guillaume.
	_exfifi_param() { unset IFS ; case "$1" in *[^A-Za-z0-9_@:]*) false ;; esac ; }
			exfifi() # exfifi = EXFIltre les FIchiers.
			{
		case "$ppq" in
			i) prereqs="$prereqs#include $param$sep" ; ppq= ; return 1 ;;
		esac
				case "$exfifi_prochainPourMinus" in
					1) exfifi_prochainPourMinus= ; return 0 ;;
				esac
				case "$param" in
			-) reqs="$reqs-$sep" ; return 1 ;;
			-i) ppq=i ; return 1 ;;
					-o) exfifi_prochainPourMinus=1 ;;
					# Les affectations de type VAR=VAL sont passées au préprocesseur, au même titre que les fichiers.
					# Cependant on doit ruser pour:
					# - ne pas y prendre les = SQL ("select 1 from t where c = 2")
					# - … sauf si le = SQL est inclus dans un VAR="SQL contenant un =", donc on ne doit tester que le premier =
					*=*)
						IFS='='
				_exfifi_param $param && { ppp="$ppp$param$sep" ; return 1 ; }
				# Sinon on laisse pisser jusqu'au test _deuxMotsSeSuivent.
						;;
				esac
		case "$param" in
			*.sql)
				# Si on est le premier fichier explicitement nommé, on devient :SCRIPT_FILENAME (surchargeant même les éventuels :SCRIPT_FILENAME définis par SqleurPreproIncl pour les -i).
				case "$prems" in 1)
					prems="$param"
					case "$prems" in /*) true ;; *) prems="$PWD/$prems" ;; esac
					reqs="$reqs#define :SCRIPT_FILENAME $prems$sep#define :SCRIPT_NAME `basename "$prems"`$sep"
					prems=
				;; esac
				reqs="$reqs#include $param$sep"
				return 1
				;;
		esac
		# Si deux des paramètres commencent par une lettre, il y a de fortes chances pour que ce soit une requête SQL ("from table", "update table", etc.).
		if _deuxMotsSeSuivent $param
		then
			reqs="$reqs$param$sep"
			return 1
		fi
			}
			repapa exfifi "$@" ; eval "$repapa"
	# Sans paramètre, on prend l'entrée standard.
	case "$reqs" in "") reqs="-" ;; esac
	case "$prereqs" in ?*) reqs="$prereqs$reqs" ;; esac
	
	# /!\ Même si le mélange de paramètres "requête" et de paramètres "fichier" est possible,
	#     à l'exécution tous les fichiers seront joués avant les requêtes individuelles.
	(
		err=/tmp/temp.sqlm.$$.err
		r=0
		rm -f $err
		IFS="$sep"
		{
			for req in $reqs
			do
				case "$req" in
					-) cat ;;
					"#"*) echo "$req" ;;
					*) echo "$req;" ;;
				esac
			done
		} \
		| { tifs php "$SQLEUR/sql2csv.php" -E -print0 $ppp || echo "$?" > $err ; } | _sqlm -0 "$@" || r=$?
		[ -s $err ] && r="`cat $err`" || true ; rm -f $err
		exit $r
	)
}

# Si nous sommes chargés après musqle/util.sh ou musqle/util.oracle.sh, nous redéfinissons sqloracle avec laquelle nous sommes compatibles.
sqloracle()
{
	# Un util.oracle.sql peut contenir certaines définitions en polyfill.
	local opts
	[ ! -f "$MUSQLE/util.oracle.sql" ] || opts="$opts -i $MUSQLE/util.oracle.sql"
	sqlm $opts "$@"
}

tifs() { unset IFS ; "$@" ; }

_deuxMotsSeSuivent()
{
	while [ $# -gt 1 ]
	do
		case "$1" in [a-zA-Z]*) case "$2" in [a-zA-Z0-9]*) return ;; esac ;; esac
		shift
	done
	false
}

_sqlm()
{
	local bdd="$bdd"
	# Gestion par service_name (https://stackoverflow.com/a/4832116/1346819),
	# si le nom de base est /<service> au lieu de <SID> (sans /).
	case "$bdd" in *:/*)
		bdd="`echo "$bdd" | sed -e 's#@\([^@]*\):/\([^:/]*\)$#@//\1/\2#'`" ;;
	esac
	if [ -z "$bddtunnel" ]
	then
		# À FAIRE: décoder en dur l'ojdbc8.jar, chercher en fonction du type de base (dans $bdd?).
		java -cp "$SQLEUR/sqlminus.jar:$SQLEUR/opencsv.jar:$SQLEUR/ojdbc8.jar" eu.outters.sqleur.SqlMinus "$bdd" "$@"
	else
		local sep sepaff # SÉParateur AFFichable (ou plutôt printfable).
		for sepaff in '\003' '\004' '\034' '\035' '\037' ""
		do
			sep="`printf "$sepaff"`"
			case "$sepaff" in "")
				unset IFS
				echo "# Tous les séparateurs potentiels sont déjà utilisés dans la ligne de commande." >&2
				return 1
				;;
			esac
			case "$*" in *"$sep"*) continue ;; esac
			break
		done
		IFS="$sep"
		
		local options="$bdd$IFS$*"
		options="--ss${IFS}200$IFS$options" # À FAIRE: rendre le --ss 200 paramétrable (dépend de la latence de sshd à inspecter tous ses entrants: si élevée, on s'aligne, sans quoi il lit en même temps un stderr sorti "bien" avant et un stdout de maintenant, les entremêlant salement.
		unset IFS
		
		ssh $bddtunnel \
		'for d in '"$bddtunnelbiblios"' "$HOME/src/projets/sqleur" "$HOME/lib/sqlminus"
		do
			[ -e "$d/sqlminus.jar" ] && break
		done
		export LC_ALL=fr_FR.UTF-8
		options="'"$options"'"
		IFS="`printf '\'"$sepaff"\''`"
		java -cp "$d/sqlminus.jar:$d/opencsv.jar:$d/ojdbc8.jar" eu.outters.sqleur.SqlMinus $options'
	fi
}

_sqlm_init()
{
	# Fonctions utilitaires.
	
	_sqlm_init_fournisseurde()
	{
		local d="vendor/gui/sqleur" n=7 r="$1"
		s="$r" ; rps ; case "$s" in ?*) r="$s" ;; esac # Certains dirname idiots considérant que .. est un nom de dossier comme un autre (et donc que dirname a/b/c/.. est c), on normalise auparavant le chemin (dans notre cas à a/b, pour que son dirname soit bien a). [constaté sur une CentOS 7, dans un sh lancé par cron]
		while [ $n -gt 0 ]
	do
		SQLEUR="$r/$d"
		[ -e "$SQLEUR/sql2csv.php" ] && return 0 || r="`dirname "$r"`"
			case "$r:$n" in /:1) true ;; /:*) n=2 ;; esac # Si on atteint la racine, un dernier essai avant de sortir.
			n=$((n-1))
	done
		return 1
	}
	
	command -v rps 2> /dev/null >&2 || rps() { s="`realpath "$s"`" ; } # rps: realpath de $s, cf. gui/scripts/sh/rp.sh
	
	# Au boulot!
	
	for SQLEUR in "$SQLEUR" "$SCRIPTS" "$HOME/src/projets/sqleur" "$HOME/lib/sqlminus"
	do
		[ ! -e "$SQLEUR/sql2csv.php" ] || return 0
	done
	_sqlm_init_fournisseurde "$PWD" && return 0 || true
	_sqlm_init_fournisseurde "$SCRIPTS" && return 0 || true
	_sqlm_init_dossierde()
	{
		for SQLEUR in "$@"
		do
			SQLEUR="`dirname "$SQLEUR"`"
			[ -e "$SQLEUR/sql2csv.php" ] && return 0 || continue
		done
	}
	IFS=:
	tifs _sqlm_init_dossierde $LOMBRICPATH
	# À FAIRE: taper $BASH_SOURCE ou équivalent sur les shells qui ont cette fonctionnalité.
	echo "# Impossible de dénicher sql2csv.php" >&2
	return 1
}

#--- repapa ---
# COPIE de gui/src/scripts/shrc.sh

# RÉPArtir les PAramètres.
# Utilisation à l'intérieur d'une fonction (ex. pour faire sauter le second paramètre):
#   montest() { [ $i -ne 2 ] ; } ; repapa montest "$@" ; eval "$repapa"
repapa()
{
	local param= i=0 test="$1" ; shift
	repapa=
	for param in "$@"
	do
		i=$((i+1))
		if $test ; then repapa="$repapa \"\$$i\"" ; fi
	done
	repapa="set --$repapa"
}
repapa0()
{
	local param= i=0 test="$1" ; shift
	repapa=
	for param in "$@"
	do
		case $i in 0) set -- ;; esac
		i=$((i+1))
		if $test ; then set -- "$@" "$param" ; fi
	done
	IFS=\; # À FAIRE: s'assurer un IFS qui ne figure pas dans les paramètres.
	repapa_="$*"
	unset IFS
	repapa='IFS=\; ; set -- $repapa_ ; unset IFS'
}

_sqlm_init || true
