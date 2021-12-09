-- prepro SqleurPreproIncl SqleurPreproDef

-- Les #for dynamiques sont-ils bien recalculés à chaque tour de boucle quand eux-mêmes sont dans une boucle?

#include bdd.conf

create temporary table t (passe int, val text);

#for VAL in 1 2
insert into t values (VAL, 'coucou VAL');
-- On teste que le #for `` est bien réévalué à chaque tour de boucle du #for VAL:
-- si c'est le cas, lorsque VAL == 2 on doit avoir deux requêtes de deux résultats chacune.
#for PASSE, MESSAGE in `select passe, val from t`
select val||' de PASSE (créé en MESSAGE)' from t;
#done
#done
