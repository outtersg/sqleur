-- prepro SqleurPreproDef SqleurPreproTestDecoupe

#define TOTO toto
#define TOTO(x, y) x + y + xy

#testdecoupe toto
TOTO;

#testdecoupe toto(a)
TOTO(a);

#testdecoupe a + b + xy
TOTO(a, b);

#testdecoupe toto(a, b, c)
TOTO(a, b, c);

#testdecoupe a + b, c + xy
TOTO(a, b\, c);

-- Les paramètres sont autorisés vides.
#testdecoupe a + + xy
TOTO(a,);
#testdecoupe + + xy
TOTO(,);

-- … Sauf le premier (car sinon comment distinguer une fonction à 0 paramètres d'une à 1?
-- Ouille en fait ben l'implémentation originale se plante :-\ Si une fonction possède deux variantes, avec 0 et 1 paramètre, l'appeler avec 0 invoquera celle à 1 paramètre en le lui fournissant vide. Grrr…
-- À FAIRE: implémenter proprement. Mais sans doute dans une version majeure.
--#define UN(x) un x
----#define UN() zéro x
--#testdecoupe UN()
--UN();
--#testdecoupe un a
--UN(a);
--#testdecoupe UN(a,)
--UN(a,);
