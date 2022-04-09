-- prepro SqleurPreproDef
-- sqleur.tailleBloc 400
-- /!\ Le test a pour but de vérifier comment est traité le #if s'il tombe à cheval sur une limite de bloc.
-- Il faut donc qu'un dd if=cefichier.sql bs=400 count=1 renvoie un machin coupé pile entre "#if GL" et "OUB".

#if not defined(GLOUB)
#define GLOUB 1
#endif

select blablabla bliblibli from youpilouplaboumdinglagadatsointsoin;

#if GLOUB
blorp;
#else
blirp;
#endif
