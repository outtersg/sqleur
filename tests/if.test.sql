L''instruction suivante doit contenir 4 mots OUI et aucun NON.;
select
$$
================
Je suis l''instruction suivante et [32mOUI[90m, je contiens
#if 0
#if 1
[31mNON[90m
#else
[31mNON[90m
#endif
#else
#if 1
[32mOUI[90m
#else
[31mNON[90m
#endif
#if 0
[31mNON[90m
#else
[32mOUI[90m
#endif
#endif
et ma fin, [32mOUI[90m.
================
$$;
