# Fonctions shell faisant collaborer sqleur (découpage de SQL) et sqlminus (poussage à une base, Oracle par exemple depuis un FreeBSD qui n'a que le JDBC pour s'y connecter).

sqlm()
{
	local fichiers= sep="`printf '\036'`"
	case "$* " in
		*".sql "|*=*)
			# /!\ Repose sur le repapa des scripts de Guillaume.
			_exfifi_param() { case "$1" in *[^A-Za-z0-9_@:]*) false ;; esac ; }
			exfifi() # exfifi = EXFIltre les FIchiers.
			{
				local r=0 # 0: on laisse passer; 1: on prend pour nous.
				case "$exfifi_prochainPourMinus" in
					1) exfifi_prochainPourMinus= ; return 0 ;;
				esac
				case "$param" in
					-o) exfifi_prochainPourMinus=1 ;;
					*.sql) r=1 ;;
					# Les affectations de type VAR=VAL sont passées au préprocesseur, au même titre que les fichiers.
					# Cependant on doit ruser pour:
					# - ne pas y prendre les = SQL ("select 1 from t where c = 2")
					# - … sauf si le = SQL est inclus dans un VAR="SQL contenant un =", donc on ne doit tester que le premier =
					*=*)
						IFS='='
						_exfifi_param $param && r=1 || r=0
						unset IFS
						;;
				esac
				case "$r" in 1) fichiers="$fichiers$param$sep" ; return 1 ;; esac
			}
			repapa exfifi "$@" ; eval "$repapa"
	esac
	
	# Si deux des paramètres commencent par une lettre, il y a de fortes chances pour que ce soit une requête SQL ("from table", "update table", etc.).
	# Dans ce cas on lance la version bête qui joue une ou plusieurs requêtes.
	# Si on n'a que des tirets (-i, etc.), ou des tirets suivis d'un alpha (-s A),
	# alors l'entrée arrive par stdin ou par un fichier .sql, qui doivent être traités par sql2csv.
	if _deuxMotsSeSuivent $*
	then
		_sqlm "$@"
	else
		( IFS="$sep" ; tifs php "$SQLEUR/sql2csv.php" -E -print0 $fichiers ) | _sqlm -0 "$@"
	fi
}

tifs() { unset IFS ; "$@" ; }

_deuxMotsSeSuivent()
{
	while [ $# -gt 1 ]
	do
		case "$1" in [a-zA-Z]*) case "$2" in [a-zA-Z]*) return ;; esac ;; esac
		shift
	done
	false
}

_sqlm()
{
	if [ -z "$bddtunnel" ]
	then
		java -cp "$SQLEUR/sqlminus.jar:$SQLEUR/opencsv.jar:$SQLEUR/ojdbc8.jar" eu.outters.sqleur.SqlMinus "$bdd" "$@"
	else
		IFS="`printf '\003'`"
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
		IFS="`printf '\''\003'\''`"
		java -cp "$d/sqlminus.jar:$d/opencsv.jar:$d/ojdbc8.jar" eu.outters.sqleur.SqlMinus $options'
	fi
}

_sqlm_init()
{
	for SQLEUR in "$SCRIPTS" "$HOME/src/projets/sqleur" "$HOME/lib/sqlminus"
	do
		[ ! -e "$SQLEUR/sqlminus.jar" ] || break
	done
}

_sqlm_init
