-- prepro SqleurPreproIncl SqleurPreproDef

#if !defined(COMPTEUR)

#define COMPTEUR 0
#define MULTIPLIEUR 1

#while COMPTEUR < 3
#include while.test.sql
#set COMPTEUR COMPTEUR + 1
#set MULTIPLIEUR MULTIPLIEUR * 2
#done

#if COMPTEUR == 3 and MULTIPLIEUR == 8
compteur et multiplieur = [32mCOMPTEUR MULTIPLIEUR[0m;
#else
compteur et multiplieur = [31mCOMPTEUR MULTIPLIEUR[0m;
#endif

#while COMPTEUR < 3
[31mCorps de boucle ne devant pas être appelé[0m;
#done

#else

#if COMPTEUR >= 2
Appelé avec un grand COMPTEUR
#else
Appelé avec un petit COMPTEUR
#endif

#endif
