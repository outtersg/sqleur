-- prepro SqleurPreproTestExpr

--- Parenthèses et listes sans parenthèses ---
-- On distingue les cas à 1 élément de ceux à plusieurs éléments.

#testexpr TOTO in "a"
in(TOTO,[a]);

#testexpr TOTO in ("a")
in(TOTO,[a]);

#testexpr TOTO in "a", "b"
in(TOTO,[a,b]);

-- Un in avec des parenthèses…
#testexpr TOTO in ("a", "b")
in(TOTO,[a,b]);
-- … s'interprète comme un defined ou une fonction…
#testexpr defined(TOTO)
defined(TOTO);
-- … plutôt que comme un opérateur.
#testexpr TOTO and (2 or 3)
and(TOTO, or(2, 3));

--- Liste sans parenthèses ---

#testexpr TOTO in "a", "b",
! attend deux membres de part et d.autre;

#testexpr fonction("a", "b" or "c", "d" or "e" in "f", "g", "h" or "i", "j")
fonction(a,or(b,c),or(d,or(in(e,[f,g,h]),i)),j);

#testexpr ( VAR in ("bah", "bih", "(couc(ou)") or VAR == "rien" ) and (VAR == "bof" or defined(GLOUPS) or VAR in ("plif") or (fonction() and fonction ( )) or VAR in "a", "b", "c")
and
(
	or
	(
		in(VAR, [bah,bih,"(couc(ou)"]),
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
				in(VAR, [plif]),
				or
				(
					and
					(
						fonction(),
						fonction()
					),
					in(VAR,[a,b,c])
				)
			)
		)
	)
);

#testexpr ( rien ( nada )
! \( sans;
#testexpr rien ( nada ) )
! \) sans;
#testexpr rien nada )
! \) sans;
#testexpr rien ( nada
! \( sans;
