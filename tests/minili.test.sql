-- prepro SqleurPreproDef

#define _MINILI_AV(champ, sep) \
	cast(listagg(champ, sep) within group (order by
#define _MINILI_AP() \
	) as varchar2(127))
#define MINILI(champ, sep, tri) _MINILI_AV(champ, sep) tri _MINILI_AP()
#define MINILI(champ, sep, tri1, tri2) _MINILI_AV(champ, sep) tri1, tri2 _MINILI_AP()

select ','||MINILI(code, ',,', code)||',';
