-- Ouch, la structure suivante (destinée à accueillir un "#else where not 3", d'où le ; fermant post-#endif; mais avec un peu trop de ;) faisait que l'intégralité du create table sautait.

Avant;

create table toto as
	select 2
#if 0
;
delete from toto where 3;
#endif
;

Après;
