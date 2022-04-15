-- prepro SqleurPreproDef
-- sqleur._mode MODE_BEGIN_END
-- sqleur.tailleBloc 100

-- /!\ Le test a pour but de vérifier comment est traité le #if s'il tombe à cheval sur une limite de bloc.
-- Il faut donc qu'un dd if=cefichier.sql bs=400 count=1 renvoie un machin coupé pile entre "#if GL" et "OUB".

#if not defined(GLOUB)
#define GLOUB 1
#endif

select blablabla from youpilouplaboum;

#if GLOUB
blorp;
#else
blirp;
#endif

-- begin transaction avec espace pile sur la césure.
-- Est-il bien interprété comme un begin transaction (instruction SQL autonome) et non un begin (attendant un end)?
-- Le "; commit" est restitué en ";\ncommit" si Sqleur détecte bien les 2 instructions.

begin transaction; commit;

-- Un vrai begin end;, cette fois:

select remplissage pour le plaisir;
begin toto; end;
