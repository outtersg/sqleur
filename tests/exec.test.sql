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

#exec //3 > temp stdout
	with
		v as (select 0.12 v), -- Délai en secondes, pour disjoindre les opérations afin de bien distinguer les étapes.
		config as
		(
			select '0' pid, 0 t0, 0 t1, 0 t2, 0 t3 where false
			union all select 'A', 0, 4, 6, 12
			union all select 'B', 0, 1, 2, 5 -- Le rapide, qui devrait laisser sa place à D.
			union all select 'C', 0, 3, 9, 10
			union all select 'D', 5, 7, 8, 11 -- Son t0 est à 5 = la fin de la première des 3 tâches précédentes (car on parallélise à hauteur de 3 max).
		),
		tranches as
		(
			select pid, 0 pos, 0 delai from config, v where false
			union all select pid, 1, v * (t1 - t0) from config, v
			union all select pid, 2, v * (t2 - t1) from config, v
			union all select pid, 3, v * (t3 - t2) from config, v
		),
		tout as
		(
			select pid, pos, group_concat('sleep '||delai||' ; echo '||pid||pos, ' ; ') over (partition by pid order by pos) deroule
			from tranches
		)
	select pid, 'sh', '-c', 'sleep '||((row_number() over (order by pid) - 1) * 0.01)||' ; echo '||pid||' ; '||deroule
	from tout where pos = 3 order by pid
;
select t from stdout order by h;

#if 0
-- Vérification que deux lectures en parallèle ne s'emmêlent pas les pinceaux.
-- Test un peu longuet, et trop dépendant de l'environnement (système + présence de fichiers).
-- res1: md5 calculé directement en shell
-- res2: md5 calculé après avoir #exec //2 cat le contenu des fichiers dans une table chez nous, puis réinjecté ces contenus vers un autre #exec md5 (avec un peu d'uuencode pour coller nos données binaires dans notre colonne texte qui apprécie mal l'UTF-8 malformé).
#exec > temp stdout sh -c 'find /tmp/ -maxdepth 1 -type f -size +1M -size -2M | head -2 | while read f ; do printf "%s %s\\n" "`md5 < "$f"`" "$f" ; done'
create temp table res1 as
	select substr(t, 1, instr(t, ' ') - 1) md5, substr(t, instr(t, ' ') + 1) chemin from stdout;
#exec //2 > mono temp contenu
	select chemin pid, 'uuencode', chemin, 'bla' from res1;
#exec //2 > temp res2
	select chemin pid, contenu.t "<", 'sh', '-c', 'uudecode -p | md5'
	from res1, contenu where res1.chemin = contenu.pid;
--select length(t) from contenu;
--select * from res1;
--select * from res2;
select count(1)||' fichiers '||case when res1.md5 = res2.t then 'correctement' else 'mal' end||' injectés en parallèle en base'
from res1 join res2 on res1.chemin = res2.pid;
#endif

#if 0
-- À FAIRE

-- < `/*+ csv */ select …`
-- ou
-- <csv `select …`
-- <; `select …`

#endif
