-- prepro SqleurPreproIncl SqleurPreproExec

#include bdd.conf

create temporary table t (passe int, val text);

#exec into temp stdout sh -c 'echo coucou ; echo "encore une fois"'
select count(1) from stdout;
select t from stdout where l = 2;

#if 0
-- À FAIRE

#exec into temp stdout, temp stderr sh -c 'echo encore ; echo "argh" >&2 ; exit 42'
select count(1) from stdout;
-- Hum, comment restituer l'erreur? Je pensais initialement définir un $?, mais ça ne porterait que le résultat du dernier joué or comme vu ci-dessous on va vouloir s'amuser avec des instanciations multiples.

create temp table t_qui (id int, texte text);
insert into t_qui values (1, 'Guillaume');
insert into t_qui values (2, 'Lucette');
insert into t_qui values (3, 'Georges');

truncate table stdout;
#exec > raw temp stdout, 2> raw temp stderr < `select texte from t_qui order by id` sed -e 's/^/Bonjour /'
select t from stdout;

truncate table stdout;
#exec > temp stdout `select id pid, 'sed', texte "<", '-e', 's/^/Bonjour /' from t_qui`
select t from stdout order by l;

#exec //3 

-- select "<"

-- < `/*+ csv */ select …`
-- ou
-- <csv `select …`
-- <; `select …`

#endif
