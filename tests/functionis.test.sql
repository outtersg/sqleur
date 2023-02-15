-- sqleur._mode MODE_BEGIN_END
-- Fut un temps n'importe quel is / as requérait un begin (create function toto() as begin blabla; end;). Même quand ce n'était pas un vrai is / as, mais une inclusion.
create function is_potable();
