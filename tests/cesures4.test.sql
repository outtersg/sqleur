-- prepro SqleurPreproDef
-- sqleur._mode MODE_BEGIN_END
-- sqleur.tailleBloc 20

select re(case when (select a from _is_) = '' then '' else '' end);

-- Le _is_ contenant un is, même si écarté plus tard (du fait des _), et la limite de bloc tombant entre le case … is et le end,
-- le is s'interposait et empêchait le end de "consommer" son case => "blocs non terminés (case:5)"
