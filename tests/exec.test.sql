-- prepro SqleurPreproIncl SqleurPreproExec

#include bdd.conf

create temporary table t (passe int, val text);

#exec into temp stdout sh -c 'echo coucou ; echo "encore une fois"'
select count(1) from stdout;
select t from stdout where l = 2;

#exec >> temp stdout 2> temp stderr ?> temp proc \
	sh -c 'echo encore ; echo "argh" >&2 ; exit 42'
select count(1) from stdout;
select r from proc where id = (select max(id) from proc);

create temp table t_qui (id int, texte text);
insert into t_qui values (1, 'Guillaume');
insert into t_qui values (2, 'Lucette');
insert into t_qui values (3, 'Georges');

#exec > mono temp stdout 2> mono temp stderr < `select texte from t_qui order by id` sed -e 's/^/Bonjour /'
select count(1) from stdout; -- 1 seule, car on a demandé du mono.
select t from stdout;

#exec > temp stdout ?> proc
	select id pid, 'sed', texte "<", '-e', 's/^/Coucou /' from t_qui;
-- On s'attend à avoir été traités par 3 processus distincts:
select count(distinct proc.id) from stdout, proc where proc.pid = stdout.pid;
select t from stdout order by pid, l;

#if 0
-- À FAIRE

#exec //3 

-- select "<"

-- < `/*+ csv */ select …`
-- ou
-- <csv `select …`
-- <; `select …`

#endif
