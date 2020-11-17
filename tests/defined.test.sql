-- prepro SqleurPreproDef

#if defined(TOTO)
defined(TOTO) alors que non;
#endif

#define TOTO 1

#if defined(TOTO)
defined(TOTO);
#endif

#undef TOTO

#if defined(TOTO)
defined(TOTO) alors que plus;
#endif
