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

-- Le détecteur de nombre exact de paramètres se faisait à une époque par une regex à conso exponentielle.
#testdecoupe toto(a,bbbbbbbbbbbbbbbbbbbbbb,)
TOTO(a,bbbbbbbbbbbbbbbbbbbbbb,);

-- On n'a pas à interpréter les mots dont nous sommes partie.
-- (bon alors comme ceci ne s'applique qu'aux défs à paramètre, ici le TOTO sans paramètre joue quand même. Mais au moins on n'est pas en x + y + xy)
#testdecoupe ROtoto(a, b)
ROTOTO(a, b);
-- Sans non plus nous interdire d'enchaîner les expressions.
#testdecoupe a + b + xyc + d + xy
TOTO(a,b)TOTO(c,d);

-- Utilisation imbriquée des définitions.
#testdecoupe a + b + xy + c + xy
TOTO(TOTO(a, b), c);

#define a3(a, u, v) rempl(fonc(a.u.v), ' ', '')
#define a2(a, b) (a + b)
-- Fut un temps où il renvoyait,
-- uniquement sous condition d'avoir deux fonctions imbriquées dans a3, et des apostrophes en paramètres de celle externe:
--           (rempl(fonc(x.y.z) + ' ', ''), t)
#testdecoupe (rempl(fonc(x.y.z), ' ', '') + t)
a2(a3(x, y, z), t);

#define vide() rien
#testdecoupe (rempl(fonc(x .y .rien), ' ', '') + t ) + rien + rien
a2 (  a3 ( x , y , vide()), t ) + vide() + vide(   );
