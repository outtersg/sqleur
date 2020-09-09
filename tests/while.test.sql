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

#set RIEN 0
#for COMPTEUR in 1 2 3
#set RIEN RIEN + COMPTEUR
#done
#if RIEN == 6
Boucle for = [32mRIEN[0m;
#else
Boucle for = [31mRIEN[0m;
#endif

#else

#if COMPTEUR >= 2
Appelé avec un grand COMPTEUR
#else
Appelé avec un petit COMPTEUR
#endif

#endif
