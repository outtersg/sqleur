-- Toutes les structures préprocesseur qu'on a pu utiliser un jour ou l'autre.
-- À lancer avec un JoueurSql ayant motsChaînes à true, car on torture le préprocesseur (#set :colonne a au lieu de #set :colonne "a", etc.).
-- Le JoueurSql doit de plus être branché sur une vraie base (PostgreSQL pour le moment).

#format delim ' ' sans-en-tete

create temporary table toto (n integer, t timestamp default now(), a text);
insert into toto (a, n) values ('grand', 16000);
insert into toto (a, n) values ('petit', 1);

#define TAILLE_BLOC 1024
#set :colonne a
#set COMPTEUR 0
#set COMPTEUR COMPTEUR + TAILLE_BLOC
#set N_TRUCS `select count(*) from toto`
#define DEBUT_TOTO to
#set TABLE concat("to", DEBUT_TOTO)
#set DEBUT `select now()`

insert into toto (a, n) values ('entre', 7);

#if `select count(*) from TABLE` = 3
select * from TABLE where t >= 'DEBUT';
#else
boum;
#endif
select * from TABLE where n < N_TRUCS;
select * from TABLE where n > COMPTEUR;
#if :colonne == a
select * from TABLE where :colonne is not null;
#else
pouet;
#endif

#if defined(DZJNKJNZE)
ouille;
#elif !defined(TABLE)
grumf;
#elif defined(TABLE)
select 'bon';
#else
pasbon;
#endif

#if :colonne in "a", "b"
#if :colonne in "a"
select 'bon';
#else
zut;
#endif
#else
mince;
#endif

#if ! defined COMPTEUR
bloup;
#elif ! defined DELKZLKJ
select 'oui';
#else
gnarg;
#endif

#if TABLE ~ /^(to|ti)/
select 'oui';
#else
argh;
#endif

#set TROUVE 0
#setn LISTE `select a from TABLE`
--select 'LI STE'; -- À FAIRE: un truc à la awk avec un IFS par défaut.
#for TRUC in LISTE
select 'essaie TRUC';
#if TRUC == "petit"
#set TROUVE 1
#endif
#done
#if not TROUVE
petage;
#endif
