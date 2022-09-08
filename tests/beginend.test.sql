-- prepro SqleurPreproTestDecoupe
-- sqleur._mode MODE_BEGIN_END

#testdecoupe create trigger truc begin rien; end;
create trigger truc
begin -- Là on commence
	rien;
end;

#testdecoupe create trigger truc begin rien; end ;
create trigger truc
begin -- Là on commence
	rien;
end -- Et là on finit
;

#testdecoupe create trigger truc begin rien;end;
create trigger truc
begin -- Là on commence
	rien;end;

#testdecoupe create trigger truc begin case when truc then plouf else machin end; end;
create trigger truc
begin case when truc then plouf else machin end; end;

-- Ne pas se laisser piéger par les mots-clés non avérés: rEND les ENDives
#testdecoupe create trigger truc begin case when truc then rend les endives else machin end; end;
create trigger truc
begin case when truc then rend les endives else machin end; end;

-- Voire collés.
#testdecoupe create trigger truc begin case when truc then endbegin else machin end; end;
create trigger truc
begin case when truc then endbegin else machin end; end;

-- Hum, et si le end n'est pas suivi d'un espace?
#testdecoupe create trigger truc begin case when a then b end, case when truc then plouf else machin end; end;
create trigger truc
begin case when a then b end, case when truc then plouf else machin end; end;
select rien;

-- Ne pas confondre avec un begin transaction! Cette fois-ci on ne recherche pas le end.
#testdecoupe begin transaction
begin transaction;
commit;
-- Même chose pour le begin sans rien derrière, synonyme de begin transaction chez PostgreSQL. C'est le ";" immédiat qui fait la différence.
#testdecoupe begin
begin;
commit;
