-- Le machin suivant combine plusieurs conditions plantogènes:
-- - une boucle #for (donc répétition du contenu)
-- - contenant immédiatement un #if (donc ayant besoin que le #for lui "prête" sa fin de ligne, afin de pouvoir être identifié comme instruction prépro)
-- - sur 3 itérations (testant que la fin de ligne soit bien transmise de façon répétable, et non simplement mémorisée à la première passe, restituée à la seconde, mais oubliée ensuite)
-- - le tout dans une chaîne à dollars ($$), avec son interaction pour passage de relais au préprocesseur lorsque les deux sont imbriquées
-- Sous ces quatre conditions, nous avions une bogue par laquelle au troisième tour de boucle le #if n'était pas détecté (et le #endif si, résultant en un "#endif sans #if")
select
$$
#for TRUC in a b c
#if TRUC
TRUC
#endif
#done
$$;
