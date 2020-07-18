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
