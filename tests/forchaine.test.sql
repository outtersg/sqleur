-- La boucle suivante doit nous sortir a, puis b, puis c.
-- Mais un défaut d'optimisation (remplacement des variables au premier tour de boucle et ensuite uniquement à la fin du $$) nous générait du a c c.

select $$
#for CIBLE in "a" "b" "c"
	coucou CIBLE,
#done
$$;
