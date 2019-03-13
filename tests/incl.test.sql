-- prepro SqleurPreproIncl

Première requête;

#if 0
On ne devrait pas passer par là, et la ligne suivante ne devrait pas être invoquée:
#include incl.test.0.sql
#endif

#include incl.test.1.sql
#include incl.test.2.sql
#include incl.test.3.sql

Dernière requête;
