-- prepro SqleurPreproIncl SqleurPreproDef

#for TRUC in "premier" "second"

create table temp_TRUC;

#if TRUC == "premier"
OUI1 TRUC;
#elif TRUC == "second"
OUI2 TRUC;
#else
[31mNON[0m TRUC;
#endif

#done

select id
#for CHAMP in A B C D
	, CHAMP
#done
from toto;

select
#for TRUC in "a b c" "d e f"
Paquet:
#for MACHIN in TRUC
MACHIN
#done
#done
;

-- Voir aussi quelques tests dans parenth.test.sql.
