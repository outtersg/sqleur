-- prepro SqleurPreproIncl SqleurPreproExec

#include bdd.conf

create temporary table t (passe int, val text);

#exec into temp stdout sh -c 'echo coucou ; echo "encore une fois"'
select count(1) from stdout;
select t from stdout where l = 2;

#exec > temp stdout 2> temp stderr ?> temp proc sh -c 'echo encore ; echo "argh" >&2 ; exit 42'
select count(1) from stdout;
select r from proc where id = (select max(id) from proc);

#if 0
-- À FAIRE

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
