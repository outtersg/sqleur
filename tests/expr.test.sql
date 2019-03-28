-- prepro SqleurPreproTestExpr

#testexpr TOTO in "a", "b"
in(TOTO,[a,b]);

#testexpr TOTO in "a", "b",
! L'opérateur binaire , attend deux membres de part et d'autre;

#testexpr fonction("a", "b" or "c", "d" or "e" in "f", "g", "h" or "i", "j")
fonction(a,or(b,c),or(d,or(in(e,(f,g,h)),i)),j);

#testexpr ( VAR in ("bah", "bih", "(couc(ou)") or VAR == "rien" ) and (VAR == "bof" or defined(GLOUPS) or VAR in ("plif") or (fonction() and fonction ( )) or VAR in "a", "b", "c")
and
(
	or
	(
		in(VAR, (bah,bih,"(couc(ou)")),
		==(VAR, rien)
	),
	or
	(
		==(VAR, bof),
		or
		(
			defined(GLOUPS),
			or
			(
				in(VAR, (plif)),
				or
				(
					fonction(),
					or
					(
						fonction(),
						or(in(VAR,(a,b,c)))
					)
				)
			)
		)
	)
);

#testexpr ( rien ( nada )
! ( sans )
#testexpr rien ( nada ) )
! ) sans (
#testexpr rien nada )
! ) sans (
#testexpr rien ( nada
! ( sans )
