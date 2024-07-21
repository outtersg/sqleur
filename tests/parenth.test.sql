-- prepro SqleurPreproDef

-- Vérification que les parenthèses sont bien opératrices et non définisseuses de tableau.
#define SOLIDIFICATION 0
#define ÉBULLITION 100
#define LIQUIDE(t) (SOLIDIFICATION < t && t < ÉBULLITION)
#if LIQUIDE(22)
Baignable;
#else
Patinable ou aéronefable;
#endif

-- Définition de liste pour boucle for.

#for TABLE in ( "t_chose", "choses", concat("t_", "moui") )
select count(1)||' DESCR' from TABLE;
#done

#for TABLE, DESCR in ( ( "t_chose", "choses" ), ( "t_bidule", "tout le reste") )
select count(1)||' DESCR' from TABLE;
#done

#for TABLE, DESCR in ( ( "t_chose", "choses" ), ( "t_bidule", concat("tout", " ", "le", " ", "reste") ) )
select count(1)||' DESCR' from TABLE;
#done

#if 0
#for TABLE in ( ( "t_chose" ), ( concat("tout", " ", "le", " ", "reste") ) )
select count(1)||' DESCR' from TABLE;
#done
#endif
